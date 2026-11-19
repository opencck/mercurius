<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SkillUsageRepository;

final class InMemorySkillUsageRepository implements SkillUsageRepository {
    /** @var array<string, array<string, array{views: int, last: ?int, created: int, pinned: bool, off: bool}>> place => name => use; a test may set it to look back in time */
    public array $rows = [];

    public function touch(string $place, string $name): void {
        $row = $this->row($place, $name);
        $this->rows[$place][$name] = ['views' => $row['views'] + 1, 'last' => time()] + $row;
    }

    public function created(string $place, string $name): void {
        $this->row($place, $name);
    }

    public function stats(): array {
        return $this->rows;
    }

    public function pin(string $place, string $name, bool $pinned): void {
        $this->rows[$place][$name] = ['pinned' => $pinned] + $this->row($place, $name);
    }

    public function turnOff(string $place, string $name, bool $off): void {
        $this->rows[$place][$name] = ['off' => $off] + $this->row($place, $name);
    }

    /** @return array{views: int, last: ?int, created: int, pinned: bool, off: bool} */
    private function row(string $place, string $name): array {
        if ($place !== '' && !isset($this->rows[$place][$name]) && isset($this->rows[''][$name])) {
            $this->rows[$place][$name] = $this->rows[''][$name];
            unset($this->rows[''][$name]);
        }
        return $this->rows[$place][$name] ??= ['views' => 0, 'last' => null, 'created' => time(), 'pinned' => false, 'off' => false];
    }
}
