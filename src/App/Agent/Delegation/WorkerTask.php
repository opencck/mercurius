<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use OpenCCK\App\Agent\Model\TaskProfile;

/** A part of a task for a worker, as the orchestrator wrote it. */
final class WorkerTask {
    /** @param list<string> $tools */
    public function __construct(
        public readonly string $goal,
        public readonly string $context,
        public readonly string $output,
        public readonly array $tools,
        public readonly string $role,
        /** scores for choosing the model; null — the role decides */
        public readonly ?TaskProfile $profile = null,
    ) {}

    /** @param list<string> $tools */
    public function withTools(array $tools): self {
        return new self($this->goal, $this->context, $this->output, $tools, $this->role, $this->profile);
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
        return new self(
            self::text($data, 'goal'),
            self::text($data, 'context'),
            self::text($data, 'output'),
            $tools,
            self::text($data, 'role') === '' ? 'main' : self::text($data, 'role'),
            isset($data['profile']) && is_array($data['profile']) ? TaskProfile::fromArray($data['profile']) : null,
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function text(array $data, string $key): string {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }
}
