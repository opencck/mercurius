<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Media;

/**
 * A file name from outside, safe to keep: no slashes or control characters (a name is a name, not a path), no dots or
 * spaces at the ends, up to 100 characters; nothing left — "file".
 *
 * spec: BRIEF.md#входящие-вложения — files from people and agents go into inbox/ and stay there
 */
final class MediaNames {
    public static function clean(string $name): string {
        $clean = preg_replace('~[\x00-\x1F\x7F/\\\\]+~u', '_', $name) ?? '';
        $clean = trim(mb_substr($clean, 0, 100), ". \t");
        return $clean === '' ? 'file' : $clean;
    }

    /** "report.txt", the 2nd — "report (2).txt". */
    public static function numbered(string $name, int $number): string {
        if ($number < 2) {
            return $name;
        }
        $dot = strrpos($name, '.');
        return $dot === false || $dot === 0 ? "{$name} ({$number})" : substr($name, 0, $dot) . " ({$number})" . substr($name, $dot);
    }
}
