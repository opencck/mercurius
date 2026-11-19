<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SkillUsageRepository;

final class InMemorySkillUsageRepository implements SkillUsageRepository {
    /** @var array<string, int> */
    public array $views = [];
    /** @var array<string, int> the last opening of a skill, set by a test to look back in time */
    public array $viewedAt = [];
    /** @var array<string, bool> */
    public array $pinned = [];
    /** @var array<string, bool> */
    public array $off = [];

    public function turnOff(string $name, bool $off): void {
        $this->off[$name] = $off;
    }

    public function touch(string $name): void {
        $this->views[$name] = ($this->views[$name] ?? 0) + 1;
        $this->viewedAt[$name] = time();
    }

    public function stats(): array {
        $out = [];
        foreach (array_unique([...array_keys($this->views), ...array_keys($this->viewedAt), ...array_keys($this->pinned), ...array_keys($this->off)]) as $name) {
            $out[$name] = ['views' => $this->views[$name] ?? 0, 'last' => $this->viewedAt[$name] ?? null, 'pinned' => $this->pinned[$name] ?? false, 'off' => $this->off[$name] ?? false];
        }
        return $out;
    }

    public function pin(string $name, bool $pinned): void {
        $this->pinned[$name] = $pinned;
    }
}
