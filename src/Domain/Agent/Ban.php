<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * Something that never runs, whatever grant there is: a key of a grant (exact or wide) or a pattern with "*", in an
 * area — everyone, one participant, a project or a job.
 *
 * spec: brief/agent-loop.md#запреты
 */
final class Ban {
    public function __construct(
        public readonly int $id,
        /** null — everyone */
        public readonly ?int $principalId,
        /** '' — everywhere; "project:N", "job:N" — runs of that area */
        public readonly string $area,
        public readonly string $key,
        public readonly ?string $label = null,
        /** who set it; null — unknown */
        public readonly ?int $createdBy = null,
    ) {}

    /**
     * Whether it reaches a run of this participant in these areas.
     *
     * @param list<string> $areas
     */
    public function reaches(int $principalId, array $areas): bool {
        return ($this->principalId === null || $this->principalId === $principalId)
            && ($this->area === '' || in_array($this->area, $areas, true));
    }

    /**
     * Whether one of the keys of a call falls under it: the same key, or the pattern ("*" — any text).
     *
     * @param list<string> $keys
     */
    public function covers(array $keys): bool {
        if (!str_contains($this->key, '*')) {
            return in_array($this->key, $keys, true);
        }
        $pattern = '~^' . str_replace('\*', '.*', preg_quote($this->key, '~')) . '$~su';
        foreach ($keys as $key) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }
        return false;
    }
}
