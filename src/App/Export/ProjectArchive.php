<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Amp\Cancellation;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Service\ProjectPolicies;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Job\Schedule;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ApprovalRepository;
use OpenCCK\Domain\Repository\JobRepository;
use OpenCCK\Domain\Repository\MailboxRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\ProjectLinkRepository;
use OpenCCK\Domain\Repository\ProjectPolicyRepository;
use OpenCCK\Domain\Repository\ProjectPrefRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use RuntimeException;
use Throwable;

use function Mercurius\I18n\t;

/**
 * A project packed to move to another instance: its card, folder (skills, files, MEMORY.md), jobs, rights, bans,
 * grants of its area, clock, subscriptions, the names of its mailboxes and secrets — never their values, the browser
 * profiles or the chats it is bound to. Sealed by a password like the archive of an agent.
 *
 * spec: brief/participants.md#экспорт-и-импорт-проекта
 * В референсах переноса проекта нет: у hermes один пользователь и нет проектов; шифрование — как у архива агента
 * (ArchiveCrypto).
 */
final class ProjectArchive {
    public const FORMAT = 'mercurius-project';
    public const EXTENSION = '.mrcp';
    /** the folder of a project packed at most this big */
    public const FOLDER_BYTES = 30_000_000;

    /**
     * @param Closure(int): ?SandboxLimits $limitsOf
     */
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectPolicyRepository $policies,
        private readonly ProjectPrefRepository $prefs,
        private readonly ApprovalRepository $approvals,
        private readonly JobRepository $jobs,
        private readonly PrincipalRepository $principals,
        private readonly string $folder,
        private readonly ?Sandbox $sandbox = null,
        private readonly ?Closure $limitsOf = null,
        private readonly ?MailboxRepository $mailboxes = null,
        private readonly ?Vault $vault = null,
        private readonly ?ProjectLinkRepository $links = null,
        private readonly ?ProjectPolicies $rights = null,
        private readonly ?JobScheduler $scheduler = null,
    ) {}

    /**
     * The archive of a project in the export folder; the caller has checked that the participant owns it.
     *
     * @throws RuntimeException|InvalidArgumentException why there is no archive
     */
    public function export(Project $project, #[\SensitiveParameter] string $password, Cancellation $cancellation): string {
        $area = 'project:' . $project->id;
        $manifest = [
            'name' => $project->name,
            'goal' => $project->goal,
            'status' => $project->status,
            'card' => $project->card,
            'policy' => $this->policies->find($project->id),
            'prefs' => $this->prefs->get($project->id),
            'bans' => array_values(array_map(
                static fn(\OpenCCK\Domain\Agent\Ban $b): array => ['key' => $b->key, 'label' => $b->label],
                array_filter($this->approvals->bans(), static fn(\OpenCCK\Domain\Agent\Ban $b): bool => $b->area === $area),
            )),
            'grants' => array_values(array_unique(array_map(
                static fn(\OpenCCK\Domain\Agent\Grant $g): string => self::arealess($g->key, $project->id),
                array_filter($this->approvals->grants($project->ownerId ?? $this->principals->owner()?->id), static fn(\OpenCCK\Domain\Agent\Grant $g): bool => $g->conversationId === null && self::inArea($g->key, $project->id)),
            ))),
            'jobs' => array_map(static fn(Job $j): array => [
                'title' => $j->title, 'prompt' => $j->prompt, 'schedule' => $j->schedule, 'status' => $j->status,
                'hook_filter' => $j->hookFilter, 'model_role' => $j->modelRole, 'watch_url' => $j->watchUrl,
            ], $this->jobs->ofProjects([$project->id])),
            'subscriptions' => array_values(array_filter(array_map(fn(int $id): ?string => $this->principals->find($id)?->displayName, $this->links?->subscribers($project->id) ?? []))),
            'mailboxes' => array_map(static fn(\OpenCCK\Domain\Mail\Mailbox $m): string => $m->name, $this->mailboxes?->ofProject($project->id) ?? []),
            'secrets' => array_map(static fn(array $s): array => ['name' => $s['name'], 'site' => $s['site']], $this->vault?->projectNames($project->id) ?? []),
        ];
        $owner = $project->ownerId ?? $this->principals->owner()?->id;
        $folder = $owner === null ? '' : $this->pack($owner, $project->id, $cancellation);
        $json = json_encode(['format' => self::FORMAT, 'version' => 1, 'source' => $project->id, 'manifest' => $manifest, 'folder' => base64_encode($folder)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (!is_dir($this->folder) && !mkdir($this->folder, 0700, true) && !is_dir($this->folder)) {
            throw new RuntimeException('export folder');
        }
        $plain = $this->folder . '/project-' . $project->id . '.json';
        $sealed = $this->folder . '/project-' . $project->id . '-' . gmdate('Ymd-His') . self::EXTENSION;
        file_put_contents($plain, $json);
        try {
            ArchiveCrypto::encrypt($plain, $sealed, $password);
        } finally {
            @unlink($plain);
        }
        return $sealed;
    }

    /**
     * A project from an archive for $who: a new project of theirs, its rights brought under theirs; the answer names
     * what they still have to do (bind a chat, put the secrets, connect mailboxes).
     *
     * @throws InvalidArgumentException a wrong password or not an archive of a project
     */
    public function import(string $path, #[\SensitiveParameter] string $password, Principal $who, Cancellation $cancellation): string {
        $plain = $path . '.json';
        try {
            ArchiveCrypto::decrypt($path, $plain, $password);
            /** @var mixed $data */
            $data = json_decode((string) file_get_contents($plain), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException(t('project.archive.not_project'));
        } finally {
            @unlink($plain);
        }
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['manifest'] ?? null)) {
            throw new InvalidArgumentException(t('project.archive.not_project'));
        }
        /** @var array<string, mixed> $m */
        $m = $data['manifest'];
        $name = self::text($m, 'name') ?: 'Project';
        $scope = Project::scopeOf($who);
        while ($this->projects->byName($name, $scope) !== null) {
            $name = mb_substr($name, 0, Project::NAME_MAX - 12) . ' ' . t('project.archive.imported_suffix');
        }
        $project = $this->projects->create(mb_substr($name, 0, Project::NAME_MAX), mb_substr(self::text($m, 'goal'), 0, Project::GOAL_MAX), $scope);
        $status = self::text($m, 'status');
        $this->projects->update($project->id, null, in_array($status, Project::STATUSES, true) ? $status : null, mb_substr(self::text($m, 'card'), 0, Project::CARD_MAX));
        $area = 'project:' . $project->id;
        if (is_array($m['policy'] ?? null)) {
            /** @var array<string, mixed> $policy */
            $policy = $m['policy'];
            $this->policies->save($project->id, $policy);
            $this->rights?->reconcile($project);
        }
        $prefs = self::rows(['p' => [$m['prefs'] ?? null]], 'p')[0] ?? null;
        if ($prefs !== null) {
            $this->prefs->save($project->id, self::maybe($prefs, 'timezone'), self::maybe($prefs, 'quiet'));
        }
        foreach (self::rows($m, 'bans') as $ban) {
            $key = self::text($ban, 'key');
            if ($key !== '') {
                $this->approvals->addBan(null, $area, $key, self::maybe($ban, 'label'), $who->id);
            }
        }
        foreach (self::strings($m, 'grants') as $key) {
            $this->approvals->addGrant($who->id, str_replace('{area}', $area, $key));
        }
        $jobs = 0;
        foreach (self::rows($m, 'jobs') as $row) {
            $jobs += $this->job($row, $project, $who) ? 1 : 0;
        }
        $subscribed = [];
        foreach (self::strings($m, 'subscriptions') as $agent) {
            foreach ($this->principals->all() as $principal) {
                if ($principal->isAgent() && $principal->displayName === $agent) {
                    $this->links?->subscribe($project->id, $principal->id);
                    $subscribed[] = $agent;
                }
            }
        }
        $folder = base64_decode(self::text($data, 'folder'), true);
        $unpacked = is_string($folder) && $folder !== '' ? $this->unpack($who->id, $project->id, $folder, $cancellation) : false;
        $secrets = array_map(static fn(array $s): string => sprintf('/secret %s: %s <значение> %s', '{project}', self::text($s, 'name'), self::text($s, 'site')), self::rows($m, 'secrets'));
        $mailboxes = self::strings($m, 'mailboxes');
        return sprintf(
            t('project.archive.imported'),
            $project->name,
            $jobs,
            $unpacked ? t('project.archive.folder_yes') : t('project.archive.folder_no'),
            $secrets === [] ? '—' : str_replace('{project}', $project->name, implode("\n", $secrets)),
            $mailboxes === [] ? '—' : implode(', ', $mailboxes),
            $subscribed === [] ? '—' : implode(', ', $subscribed),
        );
    }

    /** @param array<array-key, mixed> $row */
    private function job(array $row, Project $project, Principal $who): bool {
        $title = mb_substr(self::text($row, 'title'), 0, 100);
        $prompt = self::text($row, 'prompt');
        $schedule = self::text($row, 'schedule');
        if ($title === '' || $prompt === '' || $schedule === '') {
            return false;
        }
        try {
            $parsed = Schedule::parse($schedule);
        } catch (InvalidArgumentException) {
            return false;
        }
        $chat = $this->principals->externalId($who->id, \Mercurius\Messenger\Telegram\TelegramChannel::NAME) ?? '';
        // an imported text comes from outside: its runs carry that mark until the owner trusts the job
        $job = $this->jobs->create($who->id, \Mercurius\Messenger\Telegram\TelegramChannel::NAME, $chat, $title, $prompt, (string) $parsed, null, 'project import');
        $this->jobs->setProject($job->id, $project->id);
        $this->jobs->setDelivery($job->id, Job::DELIVER_DEFAULT, '', '');
        if (self::text($row, 'hook_filter') !== '') {
            $this->jobs->setHookFilter($job->id, self::text($row, 'hook_filter'));
        }
        if (self::text($row, 'model_role') === 'cheap') {
            $this->jobs->setModelRole($job->id, 'cheap');
        }
        if (self::text($row, 'watch_url') !== '') {
            $this->jobs->setWatch($job->id, self::text($row, 'watch_url'));
        }
        if (!$job->byEvent()) {
            $placed = $this->jobs->find($job->id) ?? $job;
            $next = $parsed->next(new DateTimeImmutable(), $this->scheduler?->zoneOf($placed) ?? new \DateTimeZone('UTC'), true);
            $this->jobs->setNext($job->id, $next);
        }
        if (self::text($row, 'status') === Job::PAUSED) {
            $this->jobs->setStatus($job->id, Job::PAUSED);
        }
        return true;
    }

    /** The project's folder packed in the sandbox of its owner; '' — no folder or no sandbox. */
    private function pack(int $owner, int $projectId, Cancellation $cancellation): string {
        $limits = $this->limitsOf === null ? null : ($this->limitsOf)($owner);
        if ($this->sandbox === null || $limits === null) {
            return '';
        }
        $archive = '.mercurius/project-export-' . $projectId . '.tgz';
        $packed = $this->sandbox->exec($owner, $limits, 'project-export-' . $projectId, sprintf('if [ -d projects/%1$d ]; then mkdir -p .mercurius && tar -czf %2$s -C projects/%1$d . && echo packed; else echo none; fi', $projectId, $archive), '.', 300, $cancellation);
        if (trim($packed->stdout) !== 'packed') {
            return '';
        }
        try {
            $bytes = $this->sandbox->fetch($owner, $limits, $archive, self::FOLDER_BYTES + 1, $cancellation);
        } finally {
            $this->sandbox->exec($owner, $limits, 'project-export-clean-' . $projectId, 'rm -f ' . $archive, '.', 30, $cancellation);
        }
        if (strlen($bytes) > self::FOLDER_BYTES) {
            throw new RuntimeException(sprintf(t('project.archive.folder_too_big'), intdiv(self::FOLDER_BYTES, 1_000_000)));
        }
        return $bytes;
    }

    private function unpack(int $owner, int $projectId, string $bytes, Cancellation $cancellation): bool {
        $limits = $this->limitsOf === null ? null : ($this->limitsOf)($owner);
        if ($this->sandbox === null || $limits === null) {
            return false;
        }
        $archive = '.mercurius/project-import-' . $projectId . '.tgz';
        try {
            $this->sandbox->put($owner, $limits, $archive, $bytes, $cancellation);
            // the archive holds the inside of the folder ("./…"): no path leaves projects/<number>
            $done = $this->sandbox->exec($owner, $limits, 'project-import-' . $projectId, sprintf('mkdir -p projects/%1$d && tar -xzf %2$s -C projects/%1$d --no-same-owner && echo unpacked; rm -f %2$s', $projectId, $archive), '.', 300, $cancellation);
            return trim($done->stdout) === 'unpacked';
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether a grant key belongs to the area of the project. */
    private static function inArea(string $key, int $projectId): bool {
        return $key === 'area:project:' . $projectId || str_starts_with($key, 'project:' . $projectId . '|');
    }

    /** A grant key with the project's number taken out, to be put back for the new project. */
    private static function arealess(string $key, int $projectId): string {
        return $key === 'area:project:' . $projectId ? 'area:{area}' : '{area}' . substr($key, strlen('project:' . $projectId));
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }

    /** @param array<array-key, mixed> $data */
    private static function maybe(array $data, string $key): ?string {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<string> the non-empty strings of a list
     */
    private static function strings(array $data, string $key): array {
        $found = [];
        $list = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
        foreach (array_keys($list) as $i) {
            if (isset($list[$i]) && is_string($list[$i]) && $list[$i] !== '') {
                $found[] = $list[$i];
            }
        }
        return $found;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $data, string $key): array {
        $rows = [];
        $list = isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
        foreach (array_keys($list) as $i) {
            if (isset($list[$i]) && is_array($list[$i])) {
                $rows[] = $list[$i];
            }
        }
        return $rows;
    }
}
