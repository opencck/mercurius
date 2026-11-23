<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Context;

/**
 * Token sizes of compaction, all derived from the model's window.
 *
 * spec: brief/agent-loop.md#контекст — threshold 75% of the window, 50% from 512K
 * spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия — a place may hold less than the window and compact at its own percent
 * ref: references/hermes-agent/agent/context_compressor.py@e96221fa:L828-L829, L3390-L3391 — summary budget: 20% of
 *      what is compacted, at least 2000 tokens, at most the configured maximum (min(5% of the window, 12000))
 * ref: docs/notes/hermes-agent.md#сжатие-контекста — lean tail: 2.5% of the window, 10–25K tokens; here also at most a
 *      quarter of the window, so small windows still have a middle to compact
 */
final class ContextBudget {
    /** Rough tokens of a text: characters / 4, the same estimate the loop uses. */
    public static function tokens(string $text): int {
        return intdiv(mb_strlen($text), 4);
    }

    /** @param int|null $percent the place's own percent of the window; null — 75%, 50% from 512K */
    public static function threshold(int $window, ?int $percent = null): int {
        return intdiv($window * ($percent ?? ($window >= 512 * 1024 ? 50 : 75)), 100);
    }

    /**
     * What a conversation may hold: the model's window, or less when the place set a limit.
     *
     * ref: references/hermes-agent/agent/context_compressor.py@e96221fa:L2179-L2189 (_resolve_context_length) — a
     *      configured context length over the model's own; here it only lowers the window, never raises it
     */
    public static function window(int $modelWindow, ?int $limit): int {
        return $limit !== null && $limit > 0 ? max(1, min($modelWindow, $limit)) : $modelWindow;
    }

    /** The context is nearly full: no waiting out a pause after failed summaries. */
    public static function critical(int $window): int {
        return intdiv($window * 95, 100);
    }

    public static function tail(int $window): int {
        return min(max(intdiv($window * 25, 1000), 10_000), 25_000, intdiv($window, 4));
    }

    /** @return positive-int */
    public static function summaryTokens(int $compactedTokens, int $window): int {
        $max = max(1, min(intdiv($window, 20), 12_000));
        return max(1, min(max(2000, intdiv($compactedTokens, 5)), $max));
    }
}
