<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

/**
 * What a part of a task needs from a model: scores 1–10 on fixed axes. A missing or non-numeric score is 1.
 *
 * spec: brief/models.md#профиль-задачи
 */
final class TaskProfile {
    public const AXES = ['text', 'reasoning', 'math', 'academic', 'code', 'agency', 'images', 'audio', 'context', 'error_cost', 'urgency'];

    /** @param array<string, int<1, 10>> $scores */
    private function __construct(private readonly array $scores) {}

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw): self {
        $scores = [];
        foreach (self::AXES as $axis) {
            // Models send 7, 7.0 or "7" alike.
            $value = isset($raw[$axis]) && (is_int($raw[$axis]) || is_float($raw[$axis]) || is_string($raw[$axis])) && is_numeric($raw[$axis]) ? (int) round((float) $raw[$axis]) : 1;
            $scores[$axis] = max(1, min(10, $value));
        }
        return new self($scores);
    }

    /** @return int<1, 10> */
    public function score(string $axis): int {
        return $this->scores[$axis] ?? 1;
    }

    /** Whether the profile asks for anything a model is chosen by: a capability, images, audio or a window. */
    public function demanding(): bool {
        foreach (array_diff(self::AXES, ['error_cost', 'urgency']) as $axis) {
            if ($this->score($axis) > 1) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, int<1, 10>> the axes scored above 1, for the log */
    public function nonTrivial(): array {
        return array_filter($this->scores, static fn(int $s): bool => $s > 1);
    }
}
