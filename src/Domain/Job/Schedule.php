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
    public const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    private const DAY_NAMES = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];
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
            $kind === 'weekly' && self::weekly($value) !== null => new self('weekly', $value),
            default => throw new InvalidArgumentException(sprintf('Расписание — once:<время ISO>, every:<минут, не меньше %d>, daily:<ЧЧ:ММ>, weekly:<дни через запятую: mon…sun или weekdays>@<ЧЧ:ММ> или event.', self::MIN_EVERY)),
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
        if ($this->kind === 'weekly') {
            [$days, $hour, $minute] = self::weekly($this->value) ?? throw new InvalidArgumentException('bad weekly schedule');
            $local = $now->setTimezone($zone)->setTime($hour, $minute);
            for ($i = 0; $i < 8; $i++, $local = $local->modify('+1 day')) {
                if ($local > $now && in_array((int) $local->format('N'), $days, true)) {
                    return $local;
                }
            }
            return null;
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
            'weekly' => self::describeWeekly($this->value),
            default => 'каждый день в ' . $this->value,
        };
    }

    /**
     * "mon,wed,fri@09:00" or "weekdays@09:00": the days (ISO numbers) and the time.
     *
     * ref: references/hermes-agent/cron/jobs.py@e96221fa:L681-L866 (parse_schedule) — weekdays and named days; cron
     *      expressions are not taken: days of the week cover the owner's cases
     *
     * @return array{list<int>, int, int}|null
     */
    private static function weekly(string $value): ?array {
        if (preg_match('~^([a-z,]+)@([01]\d|2[0-3]):([0-5]\d)$~', $value, $m) !== 1) {
            return null;
        }
        $days = [];
        foreach (explode(',', $m[1]) as $day) {
            $found = match ($day) {
                'weekdays' => [1, 2, 3, 4, 5],
                'weekends' => [6, 7],
                default => isset(self::DAYS[$day]) ? [self::DAYS[$day]] : null,
            };
            if ($found === null) {
                return null;
            }
            $days = [...$days, ...$found];
        }
        $days = array_values(array_unique($days));
        sort($days);
        return [$days, (int) $m[2], (int) $m[3]];
    }

    private static function describeWeekly(string $value): string {
        $parsed = self::weekly($value);
        if ($parsed === null) {
            return $value;
        }
        [$days, $hour, $minute] = $parsed;
        $names = $days === [1, 2, 3, 4, 5] ? 'по будням' : 'по ' . implode(', ', array_map(static fn(int $d): string => self::DAY_NAMES[$d] ?? '?', $days));
        return sprintf('%s в %02d:%02d', $names, $hour, $minute);
    }
}
