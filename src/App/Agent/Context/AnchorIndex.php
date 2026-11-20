<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Context;

/**
 * Exact identifiers of the part of a conversation that is compacted — commits, numbers, files, errors, addresses —
 * collected by expressions, without a model. A summary retells; the index keeps them word for word.
 *
 * spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — the anchors of a compacted part are kept by expressions
 * ref: references/hermes-agent/agent/context_compressor.py@e96221fa:L1029-L1078 (_build_anchor_index) — the same
 *      categories with caps, the most frequent first, a budget of characters; paths here take php and sql too
 */
final class AnchorIndex {
    public const HEADING = '## Якоря (собраны без модели, дословно)';
    private const BUDGET = 7_000;
    /** label => [pattern, cap] */
    private const PATTERNS = [
        'issues' => ['~(?<![\w#])#\d{3,6}\b~u', 120],
        'commits' => ['~\b[0-9a-f]{9,40}\b~', 40],
        'files' => ['~\b[\w./-]+/[\w.-]+\.(?:php|py|ts|tsx|js|rs|md|yaml|yml|json|toml|sh|sql|env|conf)\b~u', 80],
        'errors' => ['~\b(?:[A-Z][a-zA-Z]*(?:Error|Exception)|ENOSPC|EACCES|SIGKILL)\b[^\n]{0,90}~', 40],
        'urls' => ['~https?://[^\s)"\'<>]{10,110}~', 30],
    ];

    public static function build(string $text): string {
        $sections = [];
        $used = 0;
        foreach (self::PATTERNS as $label => [$pattern, $cap]) {
            if (preg_match_all($pattern, $text, $found) < 1) {
                continue;
            }
            $counts = [];
            foreach ($found[0] as $value) {
                $value = rtrim(trim($value), '.,;:');
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
            arsort($counts);
            $items = [];
            foreach (array_slice($counts, 0, $cap, true) as $value => $count) {
                $items[] = $count > 1 ? sprintf("%s(x%d)", $value, $count) : sprintf("%s", $value);
            }
            $line = $label . ': ' . implode(', ', $items);
            if ($used + mb_strlen($line) > self::BUDGET) {
                break;
            }
            $sections[] = $line;
            $used += mb_strlen($line);
        }
        return $sections === [] ? '' : self::HEADING . "\n" . implode("\n", $sections);
    }

    /** A summary without the index it carried: a new one is built from the whole compacted text. */
    public static function strip(string $summary): string {
        $at = mb_strpos($summary, self::HEADING);
        return trim($at === false ? $summary : mb_substr($summary, 0, $at));
    }
}
