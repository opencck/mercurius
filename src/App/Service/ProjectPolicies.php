<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectLevel;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\ProjectPolicyRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The rights of a project: the tools and limits its owner set, never above the owner's own. A run in the project's
 * chat, topic or job works with them, whoever writes.
 *
 * spec: brief/participants.md#права-проекта
 * В референсах прав проекта нет: у hermes один пользователь и нет проектов.
 */
final class ProjectPolicies {
    /** limits a project's owner may set: run limits, the daily spend and the sandbox */
    public const LIMITS = [
        'max_turns', 'token_budget', 'run_timeout', 'tool_timeout', 'daily_spend_usd',
        'sandbox.disk_bytes', 'sandbox.processes', 'sandbox.cpu_seconds', 'sandbox.memory_bytes', 'sandbox.concurrent_commands',
    ];

    public function __construct(
        private readonly ProjectPolicyRepository $repository,
        private readonly ProjectRepository $projects,
        private readonly PrincipalRepository $principals,
        private readonly PolicyService $policies,
        /** @var \Closure(ParticipantPolicy): list<string> the registered tools a policy allows */
        private readonly \Closure $names,
        private readonly LoggerInterface $logger,
        private readonly ?ParticipantInbox $inbox = null,
    ) {}

    /** The ceiling: the rights of the project's owner. */
    public function ceiling(Project $project): ParticipantPolicy {
        $owner = $project->ownerId === null ? null : $this->principals->find($project->ownerId);
        return $owner === null ? $this->policies->owner() : $this->policies->forPrincipal($owner);
    }

    /** The project's rights now: its own capped by its owner's, or its owner's when it has none. */
    public function of(Project $project): ParticipantPolicy {
        $ceiling = $this->ceiling($project);
        $stored = $this->repository->find($project->id);
        if ($stored === null) {
            return $ceiling;
        }
        try {
            return ParticipantPolicy::fromArray($stored, $ceiling)->capTo($ceiling);
        } catch (InvalidArgumentException $e) {
            // A broken row does not widen anything: the project works with nothing more than talk.
            $this->logger->error('Project policy is malformed', ['project' => $project->id, 'error' => $e->getMessage()]);
            return new ParticipantPolicy([], $ceiling->run, $ceiling->sandbox, false);
        }
    }

    /**
     * The rights of a run in the project for one who writes there: the project's for a member, read-only for a reader;
     * null — no level in the project, the usual rights apply.
     */
    public function forRun(Project $project, Principal $who): ?ParticipantPolicy {
        $level = $project->levelOf($who, $this->projects->memberLevel($project->id, $who->id));
        if ($level === null) {
            return null;
        }
        $policy = $this->of($project);
        return $level->atLeast(ProjectLevel::Write) ? $policy : $policy->readOnlyCopy();
    }

    /** @throws InvalidArgumentException when the owner of the project has no such tool */
    public function grant(Project $project, string $tool): ParticipantPolicy {
        $ceiling = $this->ceiling($project);
        if (!in_array($tool, ($this->names)($ceiling), true)) {
            throw new InvalidArgumentException(sprintf(t('project.policy.no_tool_at_owner'), $tool));
        }
        $stored = $this->stored($project, $ceiling);
        $stored['tools'] = array_values(array_unique([...self::toolsOf($stored), $tool]));
        return $this->save($project, $stored, $ceiling);
    }

    public function revoke(Project $project, string $tool): ParticipantPolicy {
        $ceiling = $this->ceiling($project);
        $stored = $this->stored($project, $ceiling);
        $stored['tools'] = array_values(array_diff(self::toolsOf($stored), [$tool]));
        return $this->save($project, $stored, $ceiling);
    }

    /** @throws InvalidArgumentException on an unknown limit, a bad value or a value above the owner's */
    public function limit(Project $project, string $key, int|float $value): ParticipantPolicy {
        if (!in_array($key, self::LIMITS, true)) {
            throw new InvalidArgumentException(sprintf(t('project.policy.unknown_limit'), $key, implode(', ', self::LIMITS)));
        }
        $ceiling = $this->ceiling($project);
        $top = self::value($ceiling, $key);
        if ($key === 'sandbox.memory_bytes' && $top === 0) {
            $top = null; // the owner has no limit of the address space: any positive one fits under it
        }
        if ($key === 'daily_spend_usd') {
            // 0 is no cap: only an owner without a cap may give a project none
            if ($value < 0 || ($top > 0 && ($value <= 0 || $value > $top))) {
                throw new InvalidArgumentException(sprintf(t('project.policy.above_owner'), $key, self::shown($top)));
            }
        } elseif (!is_int($value) || $value < 1 || ($top !== null && $value > $top)) {
            throw new InvalidArgumentException(sprintf(t('project.policy.above_owner'), $key, self::shown($top)));
        }
        $stored = $this->stored($project, $ceiling);
        if (str_starts_with($key, 'sandbox.')) {
            $sandbox = isset($stored['sandbox']) && is_array($stored['sandbox']) ? $stored['sandbox'] : [];
            $sandbox[substr($key, 8)] = $value;
            $stored['sandbox'] = $sandbox;
        } else {
            $stored[$key] = $key === 'daily_spend_usd' ? (float) $value : $value;
        }
        return $this->save($project, $stored, $ceiling);
    }

    /**
     * Brings the stored rights of a project under its owner's: tools the owner lost go, limits go down to the owner's.
     *
     * @return list<string> what changed, in words; [] — nothing
     */
    public function reconcile(Project $project): array {
        $stored = $this->repository->find($project->id);
        if ($stored === null) {
            return [];
        }
        $ceiling = $this->ceiling($project);
        try {
            $capped = ParticipantPolicy::fromArray($stored, $ceiling)->capTo($ceiling);
        } catch (InvalidArgumentException) {
            return [];
        }
        $changes = [];
        $lost = array_values(array_diff(self::toolsOf($stored), $capped->tools ?? self::toolsOf($stored)));
        if ($lost !== []) {
            $changes[] = sprintf(t('project.policy.tools_gone'), implode(', ', $lost));
        }
        foreach (self::LIMITS as $key) {
            $was = self::storedValue($stored, $key);
            $now = self::value($capped, $key);
            if ($was !== null && $now !== null && $now < $was) {
                $changes[] = sprintf(t('project.policy.limit_down'), $key, self::shown($was), self::shown($now));
            }
        }
        if ($changes !== []) {
            $this->repository->save($project->id, self::storable($capped, $stored));
        }
        return $changes;
    }

    /** After a change of anyone's rights: every project with rights of its own is brought under its owner's; owners are told. */
    public function reconcileAll(): void {
        foreach ($this->repository->projects() as $id) {
            $project = $this->projects->find($id);
            if ($project === null) {
                continue;
            }
            try {
                $changes = $this->reconcile($project);
            } catch (Throwable $e) {
                $this->logger->error('Project policy was not reconciled', ['project' => $id, 'error' => $e::class]);
                continue;
            }
            if ($changes === []) {
                continue;
            }
            $this->logger->info('Project policy narrowed under its owner', ['project' => $id, 'changes' => count($changes)]);
            $owner = $project->ownerId === null ? $this->principals->owner() : $this->principals->find($project->ownerId);
            if ($owner !== null) {
                $this->inbox?->tell($owner, sprintf(t('project.policy.narrowed'), $project->name, implode('; ', $changes)));
            }
        }
    }

    /** The rights in words: tools and limits, for the project's members. */
    public function describe(Project $project): string {
        $policy = $this->of($project);
        $own = $this->repository->find($project->id) !== null;
        $tools = ($this->names)($policy);
        $limits = [];
        foreach (self::LIMITS as $key) {
            $value = self::value($policy, $key);
            if ($value !== null) {
                $limits[] = $key . '=' . self::shown($value);
            }
        }
        return sprintf(
            t($own ? 'project.policy.own' : 'project.policy.inherited'),
            $project->name,
            $tools === [] ? '—' : implode(', ', $tools),
            implode(', ', $limits),
        );
    }

    /**
     * The stored rights to change: the project's own, or its owner's as they are now when it has none yet.
     *
     * @return array<string, mixed>
     */
    private function stored(Project $project, ParticipantPolicy $ceiling): array {
        $stored = $this->repository->find($project->id);
        if ($stored !== null) {
            if (!array_key_exists('tools', $stored) || $stored['tools'] === null) {
                $stored['tools'] = ($this->names)($ceiling);
            }
            return $stored;
        }
        $base = $ceiling->toArray();
        $base['tools'] = ($this->names)($ceiling);
        return $base;
    }

    /** @param array<string, mixed> $stored */
    private function save(Project $project, array $stored, ParticipantPolicy $ceiling): ParticipantPolicy {
        $policy = ParticipantPolicy::fromArray($stored, $ceiling)->capTo($ceiling);
        $this->repository->save($project->id, $stored);
        return $policy;
    }

    /**
     * @param array<string, mixed> $stored
     * @return list<string>
     */
    private static function toolsOf(array $stored): array {
        $tools = [];
        $list = isset($stored['tools']) && is_array($stored['tools']) ? $stored['tools'] : [];
        foreach (array_keys($list) as $key) {
            if (isset($list[$key]) && is_string($list[$key])) {
                $tools[] = $list[$key];
            }
        }
        return $tools;
    }

    /**
     * What is stored after capping: the capped values, the tools as a list.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private static function storable(ParticipantPolicy $capped, array $stored): array {
        $array = $capped->toArray();
        $array['tools'] = $capped->tools ?? self::toolsOf($stored);
        if (!array_key_exists('sandbox', $stored)) {
            unset($array['sandbox']);
        }
        return $array;
    }

    private static function value(ParticipantPolicy $policy, string $key): int|float|null {
        return match ($key) {
            'max_turns' => $policy->run->maxTurns,
            'token_budget' => $policy->run->tokenBudget,
            'run_timeout' => $policy->run->runTimeout,
            'tool_timeout' => $policy->run->toolTimeout,
            'daily_spend_usd' => $policy->dailySpendUsd,
            'sandbox.disk_bytes' => $policy->sandbox?->diskBytes,
            'sandbox.processes' => $policy->sandbox?->processes,
            'sandbox.cpu_seconds' => $policy->sandbox?->cpuSeconds,
            'sandbox.memory_bytes' => $policy->sandbox?->memoryBytes,
            'sandbox.concurrent_commands' => $policy->sandbox?->concurrentCommands,
            default => null,
        };
    }

    /** @param array<string, mixed> $stored */
    private static function storedValue(array $stored, string $key): int|float|null {
        $from = $stored;
        if (str_starts_with($key, 'sandbox.')) {
            $from = isset($stored['sandbox']) && is_array($stored['sandbox']) ? $stored['sandbox'] : [];
            $key = substr($key, 8);
        }
        return isset($from[$key]) && (is_int($from[$key]) || is_float($from[$key])) ? $from[$key] : null;
    }

    private static function shown(int|float|null $value): string {
        return match (true) {
            $value === null => '—',
            is_float($value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }
}
