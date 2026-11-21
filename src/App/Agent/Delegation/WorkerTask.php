<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use OpenCCK\App\Agent\Model\TaskProfile;

/**
 * A part of a task for a worker, as the orchestrator wrote it: what to do and with which rights.
 *
 * spec: brief/orchestration.md#права-воркера-задаёт-оркестратор
 */
final class WorkerTask {
    public const MODES = ['read', 'write'];
    public const MEMORY = ['none', 'read', 'write'];
    public const CONTEXT_OF = ['project', 'participant'];

    /** @var list<'project'|'participant'> */
    public readonly array $contextOf;

    /**
     * @param list<string> $tools
     * @param ''|'read'|'write' $mode '' — read-only when every tool reads, otherwise with writing
     * @param 'none'|'read'|'write' $memory the participant's memory: none, searching it, or also proposing facts
     * @param list<string> $contextOf what of the conversation's context the worker gets; [] — isolation
     */
    public function __construct(
        public readonly string $goal,
        public readonly string $context,
        public readonly string $output,
        public readonly array $tools,
        public readonly string $role,
        /** scores for choosing the model; null — the role decides */
        public readonly ?TaskProfile $profile = null,
        /** a verifier with a clean context checks the answer */
        public readonly bool $verify = false,
        public readonly string $mode = '',
        public readonly string $memory = 'none',
        array $contextOf = [],
        /** @var array<array-key, mixed>|null the answer's JSON Schema; null — a free answer */
        public readonly ?array $outputSchema = null,
        /** the id of the orchestrator's plan item this part does: its status follows the part */
        public readonly ?string $todo = null,
        /** @var list<int> parts of the same call (positions from 1) that must end first; their reports come with this part */
        public readonly array $after = [],
    ) {
        $this->contextOf = array_values(array_unique(array_filter($contextOf, static fn(string $c): bool => in_array($c, self::CONTEXT_OF, true))));
    }

    /** @param list<string> $tools */
    public function withTools(array $tools): self {
        return new self($this->goal, $this->context, $this->output, $tools, $this->role, $this->profile, $this->verify, $this->mode, $this->memory, $this->contextOf, $this->outputSchema, $this->todo, $this->after);
    }

    /** The same part with more background: the reports of the parts it waited for. */
    public function withMoreContext(string $more): self {
        $context = trim($this->context . "\n\n" . $more);
        return new self($this->goal, $context, $this->output, $this->tools, $this->role, $this->profile, $this->verify, $this->mode, $this->memory, $this->contextOf, $this->outputSchema, $this->todo, $this->after);
    }

    /**
     * The rights as the orchestrator asked for them, with unknown values dropped.
     *
     * @param array<array-key, mixed> $item
     * @return array{''|'read'|'write', 'none'|'read'|'write', list<string>}
     */
    public static function rights(array $item): array {
        $of = [];
        $list = isset($item['context_of']) && is_array($item['context_of']) ? $item['context_of'] : [];
        foreach (array_keys($list) as $key) {
            if (isset($list[$key]) && is_string($list[$key])) {
                $of[] = $list[$key];
            }
        }
        $mode = isset($item['mode']) && is_string($item['mode']) ? $item['mode'] : '';
        $memory = isset($item['memory']) && is_string($item['memory']) ? $item['memory'] : 'none';
        return [
            $mode === 'read' ? 'read' : ($mode === 'write' ? 'write' : ''),
            $memory === 'read' ? 'read' : ($memory === 'write' ? 'write' : 'none'),
            $of,
        ];
    }

    /** Whether the answer is checked: asked for, or a mistake here is costly. */
    public function checked(): bool {
        return $this->verify || ($this->profile !== null && $this->profile->score('error_cost') >= 8);
    }

    /** @return array<string, mixed> what a background delegation keeps in the database */
    public function toArray(): array {
        return [
            'goal' => $this->goal,
            'context' => $this->context,
            'output' => $this->output,
            'tools' => $this->tools,
            'role' => $this->role,
            'profile' => $this->profile?->nonTrivial(),
            'verify' => $this->verify,
            'mode' => $this->mode,
            'memory' => $this->memory,
            'context_of' => $this->contextOf,
            'output_schema' => $this->outputSchema,
            'todo' => $this->todo,
            'after' => $this->after,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self {
        $tools = [];
        if (isset($data['tools']) && is_array($data['tools'])) {
            foreach (array_keys($data['tools']) as $key) {
                if (isset($data['tools'][$key]) && is_string($data['tools'][$key])) {
                    $tools[] = $data['tools'][$key];
                }
            }
        }
        [$mode, $memory, $of] = self::rights($data);
        return new self(
            self::text($data, 'goal'),
            self::text($data, 'context'),
            self::text($data, 'output'),
            $tools,
            self::text($data, 'role') === '' ? 'main' : self::text($data, 'role'),
            isset($data['profile']) && is_array($data['profile']) ? TaskProfile::fromArray($data['profile']) : null,
            ($data['verify'] ?? false) === true,
            $mode,
            $memory,
            $of,
            isset($data['output_schema']) && is_array($data['output_schema']) ? $data['output_schema'] : null,
            self::text($data, 'todo') === '' ? null : self::text($data, 'todo'),
            self::positions($data['after'] ?? null),
        );
    }

    /**
     * Positions of parts (from 1) from a list; anything else is dropped.
     *
     * @return list<int>
     */
    public static function positions(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }
        $found = [];
        foreach (array_keys($value) as $key) {
            if (isset($value[$key]) && is_int($value[$key]) && $value[$key] >= 1) {
                $found[] = $value[$key];
            }
        }
        return array_values(array_unique($found));
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }
}
