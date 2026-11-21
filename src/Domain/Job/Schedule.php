<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Job;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function Mercurius\I18n\t;

/**
 * When a job runs: once at a moment, every N minutes, or every day at a local time. The next run is computed from now,
 * never from the missed ones: a downtime catches up with one run, not with every one missed.
 *
 * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — the next run is moved before the run; missed runs catch up by a rule, at most
 *       once; an explicit time zone
 */
final class Schedule {
    public const MIN_EVERY = 5;
    public const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    private const DAY_NAMES = [1 => 'job.schedule.day_1', 2 => 'job.schedule.day_2', 3 => 'job.schedule.day_3', 4 => 'job.schedule.day_4', 5 => 'job.schedule.day_5', 6 => 'job.schedule.day_6', 7 => 'job.schedule.day_7'];
    /** runs on a webhook, never by time */
    public const EVENT = 'event';

    private function __construct(
        public readonly string $kind,
        public readonly string $value,
    ) {}

    /** @throws InvalidArgumentException */
    /**
     * "in:<minutes>" — a one-off job that many minutes from now, by the server's clock: the model does not count the
     * moment itself from the time in its prompt, which is the time the conversation was opened.
     *
     * spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов
     */
    public static function resolve(string $schedule, DateTimeImmutable $now): string {
        if (preg_match('~^in:(\d{1,6})$~', trim($schedule), $m) !== 1 || (int) $m[1] < 1) {
            return $schedule;
        }
        return 'once:' . $now->modify(sprintf('+%d minutes', (int) $m[1]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function parse(string $schedule): self {
        [$kind, $value] = array_pad(explode(':', trim($schedule), 2), 2, '');
        return match (true) {
            $kind === self::EVENT && $value === '' => new self(self::EVENT, ''),
            $kind === 'once' && strtotime($value) !== false => new self('once', $value),
            $kind === 'every' && ctype_digit($value) && (int) $value >= self::MIN_EVERY => new self('every', $value),
            $kind === 'daily' && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $value) === 1 => new self('daily', $value),
            $kind === 'weekly' && self::weekly($value) !== null => new self('weekly', $value),
            default => throw new InvalidArgumentException(sprintf(t('job.schedule.raspisanie_once_vremya_iso_every'), self::MIN_EVERY)),
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
            'once' => t('job.schedule.odnazhdy') . $this->value,
            'every' => sprintf(t('job.schedule.kazhdye_min'), (int) $this->value),
            self::EVENT => t('job.schedule.po_sobytiyu_webhook'),
            'weekly' => self::describeWeekly($this->value),
            default => t('job.schedule.kazhdyy_den_v') . $this->value,
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
        $names = $days === [1, 2, 3, 4, 5] ? t('job.schedule.po_budnyam') : t('job.schedule.po') . implode(', ', array_map(static fn(int $d): string => isset(self::DAY_NAMES[$d]) ? t(self::DAY_NAMES[$d]) : '?', $days));
        return sprintf(t('job.schedule.v'), $names, $hour, $minute);
    }
}
