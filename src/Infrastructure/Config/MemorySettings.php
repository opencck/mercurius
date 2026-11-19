<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Config;

use SensitiveParameter;

/**
 * Memory: the key of the fingerprints a forgotten text leaves behind, and the share of HRR in recall.
 *
 * spec: BRIEF.md#память-слои — forgetting removes the text; a keyed fingerprint keeps it from being stored again
 * spec: BRIEF.md#hrr-с-самого-начала — the weight of HRR is a setting, not a constant
 */
final class MemorySettings {
    private readonly ?string $secret;

    private function __construct(
        #[SensitiveParameter] ?string $secret,
        /** share of HRR in the score of a recalled fact, 0..1 */
        public readonly float $hrrWeight = 0.3,
        /** hour of the nightly check of memory, UTC */
        public readonly int $checkHour = 3,
        /** @var positive-int characters of MEMORY.md that go into the prompt; more — the agent compresses the file */
        public readonly int $fileLimit = 8000,
    ) {
        $this->secret = $secret;
    }

    /**
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self {
        $weight = Env::raw($env, 'MEMORY_HRR_WEIGHT');
        if ($weight !== null && (!is_numeric($weight) || (float) $weight < 0.0 || (float) $weight > 1.0)) {
            throw new ConfigException('MEMORY_HRR_WEIGHT must be a number from 0 to 1');
        }
        $hour = Env::raw($env, 'MEMORY_CHECK_HOUR');
        if ($hour !== null && (!ctype_digit($hour) || (int) $hour > 23)) {
            throw new ConfigException('MEMORY_CHECK_HOUR must be an hour from 0 to 23');
        }
        // spec: BRIEF.md#всегда-в-контексте — not small by default (hermes keeps 2200); the owner changes it
        $limit = Env::positiveInt($env, 'MEMORY_FILE_LIMIT', 8000, 100_000);
        if ($limit < 500) {
            throw new ConfigException('MEMORY_FILE_LIMIT must be at least 500 characters');
        }
        return new self(Env::raw($env, 'MEMORY_SECRET'), $weight === null ? 0.3 : (float) $weight, $hour === null ? 3 : (int) $hour, $limit);
    }

    /**
     * The fingerprint key; without MEMORY_SECRET it is derived from the given seed (the bot token), so rotating that
     * seed lets forgotten texts be proposed again.
     */
    public function key(#[SensitiveParameter] string $seed): string {
        return $this->secret ?? hash('sha256', 'mercurius-memory:' . $seed);
    }

    public function hasSecret(): bool {
        return $this->secret !== null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array {
        return ['secret' => $this->secret === null ? 'none' : '***'];
    }
}
