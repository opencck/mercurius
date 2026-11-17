<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Closure;
use OpenCCK\Memory\Domain\ConsolidationJournal;

final class InMemoryConsolidationJournal implements ConsolidationJournal {
    /** @var list<array{at: int, facts: int, chars: int, status: string, report: list<string>}> */
    public array $passes = [];
    /** @var array<string, int> "kind:a:b" => shown at */
    public array $notices = [];
    /** @var Closure(): int */
    public Closure $now;

    public function __construct() {
        $this->now = static fn(): int => time();
    }

    public function last(): ?array {
        foreach (array_reverse($this->passes) as $pass) {
            if ($pass['status'] !== self::FAILED) {
                return ['at' => $pass['at'], 'facts' => $pass['facts'], 'chars' => $pass['chars'], 'status' => $pass['status']];
            }
        }
        return null;
    }

    public function save(string $status, int $facts, int $chars, int $durationMs, array $report): void {
        $this->passes[] = ['at' => ($this->now)(), 'facts' => $facts, 'chars' => $chars, 'status' => $status, 'report' => $report];
    }

    public function notice(string $kind, int $a, int $b, int $pauseDays): bool {
        $key = $kind . ':' . $a . ':' . $b;
        $now = ($this->now)();
        if (isset($this->notices[$key]) && $this->notices[$key] >= $now - $pauseDays * 86400) {
            return false;
        }
        $this->notices[$key] = $now;
        return true;
    }
}
