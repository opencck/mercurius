<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * How many messages the agent may start itself in a day of the owner (job reports, the initiative): over the cap a
 * report waits for the next day. Counted in memory: a restart starts the day's count again.
 *
 * spec: brief/scheduler.md#планировщик-и-проекты-1 — initiative messages with a set frequency and quiet hours
 */
final class ProactiveBudget {
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;
    private string $day = '';
    private int $sent = 0;

    /**
     * @param int $perDay 0 — no cap
     * @param (Closure(): DateTimeImmutable)|null $now
     */
    public function __construct(
        private readonly int $perDay,
        private readonly DateTimeZone $zone,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /** Takes one message of today's cap; false when today's cap is spent. */
    public function take(): bool {
        if ($this->perDay <= 0) {
            return true;
        }
        $day = ($this->now)()->setTimezone($this->zone)->format('Y-m-d');
        if ($day !== $this->day) {
            $this->day = $day;
            $this->sent = 0;
        }
        if ($this->sent >= $this->perDay) {
            return false;
        }
        $this->sent++;
        return true;
    }
}
