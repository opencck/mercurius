<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/** Words of a search query: runs of letters and digits, lower case, no repeats. */
final class SearchWords {
    public const MAX = 16;

    /** @return list<string> */
    public static function split(string $text): array {
        $parts = preg_split('~[^\p{L}\p{N}]+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : $parts;
    }

    /**
     * @param list<string> $queries
     * @return list<string> at most MAX words of two letters or more
     */
    public static function terms(array $queries): array {
        $terms = [];
        foreach ($queries as $query) {
            foreach (self::split(mb_strtolower($query)) as $word) {
                if (mb_strlen($word) >= 2) {
                    $terms[$word] = true;
                }
            }
        }
        return array_slice(array_map(strval(...), array_keys($terms)), 0, self::MAX);
    }
}
