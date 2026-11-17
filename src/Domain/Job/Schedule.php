<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Job;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * When a job runs: once at a moment, every N minutes, or every day at a local time. The next run is computed from now,
 * never from the missed ones: a downtime catches up with one run, not with every one missed.
 *
 * spec: BRIEF.md#планировщик-и-проекты — the next run is moved before the run; missed runs catch up by a rule, at most
 *       once; an explicit time zone
 */
final class Schedule {
    public const MIN_EVERY = 5;
    /** runs on a webhook, never by time */
    public const EVENT = 'event';

    private function __construct(
        public readonly string $kind,
        public readonly string $value,
    ) {}

    /** @throws InvalidArgumentException */
    public static function parse(string $schedule): self {
        [$kind, $value] = array_pad(explode(':', trim($schedule), 2), 2, '');
        return match (true) {
            $kind === self::EVENT && $value === '' => new self(self::EVENT, ''),
            $kind === 'once' && strtotime($value) !== false => new self('once', $value),
            $kind === 'every' && ctype_digit($value) && (int) $value >= self::MIN_EVERY => new self('every', $value),
            $kind === 'daily' && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $value) === 1 => new self('daily', $value),
            default => throw new InvalidArgumentException(sprintf('Расписание — once:<время ISO>, every:<минут, не меньше %d>, daily:<ЧЧ:ММ> или event.', self::MIN_EVERY)),
        };
    }

    public function __toString(): string {
        return $this->kind === self::EVENT ? self::EVENT : $this->kind . ':' . $this->value;
    }

    /** The first run after $now; null when a one-off job's moment has passed and always for a job by event. */
    public function next(DateTimeImmutable $now, DateTimeZone $zone, bool $first = false): ?DateTimeImmutable {
        if ($this->kind === self::EVENT) {
            return null;
        }
        if ($this->kind === 'once') {
            $at = new DateTimeImmutable($this->value, $zone);
            return $first && $at > $now ? $at : null;
        }
        if ($this->kind === 'every') {
            return $now->modify(sprintf('+%d minutes', (int) $this->value));
        }
        [$hour, $minute] = array_map('intval', explode(':', $this->value));
        $local = $now->setTimezone($zone)->setTime($hour, $minute);
        if ($local <= $now) {
            $local = $local->modify('+1 day');
        }
        return $local;
    }

    public function describe(): string {
        return match ($this->kind) {
            'once' => 'однажды ' . $this->value,
            'every' => sprintf('каждые %d мин', (int) $this->value),
            self::EVENT => 'по событию (webhook)',
            default => 'каждый день в ' . $this->value,
        };
    }
}
