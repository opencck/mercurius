<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use DateTimeZone;
use OpenCCK\Domain\Repository\ParticipantPrefRepository;
use Throwable;

/**
 * Whose clock a job keeps: the participant's own time zone and quiet hours when they set them, the owner's from .env
 * otherwise.
 *
 * spec: brief/participants.md#несколько-пользователей — each participant has their own time zone and quiet hours
 */
final class ParticipantClock {
    /** @param array{int, int}|null $ownerQuiet */
    public function __construct(
        private readonly ParticipantPrefRepository $prefs,
        private readonly DateTimeZone $ownerZone,
        private readonly ?array $ownerQuiet,
    ) {}

    public function zone(?int $principalId): DateTimeZone {
        $name = $principalId === null ? null : $this->prefs->get($principalId)['timezone'];
        if ($name === null) {
            return $this->ownerZone;
        }
        return self::parseZone($name) ?? $this->ownerZone;
    }

    /** @return array{int, int}|null [from, to) hours; null — no quiet hours */
    public function quiet(?int $principalId): ?array {
        $raw = $principalId === null ? null : $this->prefs->get($principalId)['quiet'];
        if ($raw === null) {
            return $this->ownerQuiet;
        }
        return self::parseQuiet($raw);
    }

    public static function parseZone(string $name): ?DateTimeZone {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{int, int}|null hours "from-to"; null for anything else */
    public static function parseQuiet(string $raw): ?array {
        if (preg_match('~^(\d{1,2})-(\d{1,2})$~', trim($raw), $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 23) {
            return null;
        }
        return [(int) $m[1], (int) $m[2]];
    }
}
