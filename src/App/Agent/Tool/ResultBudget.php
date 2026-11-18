<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * How much of the model's window tool results may take: one result at most 15%, all results of a turn at most 30%.
 * The system prompt, the schemas, the history and the answer need the rest.
 *
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18 — tool results are bounded by the window of the model
 * ref: references/hermes-agent/tools/budget_config.py@e96221fa:L80-L113 (budget_for_context_window) — the same
 *      fractions of window * 4 chars, floors so a small model still gets a usable result, caps for big ones
 */
final class ResultBudget {
    private const CHARS_PER_TOKEN = 4;
    private const PER_RESULT = 0.15;
    private const PER_TURN = 0.30;
    private const MIN_RESULT = 8_000;
    private const MIN_TURN = 16_000;
    private const MAX_RESULT = 100_000;
    private const MAX_TURN = 200_000;

    /**
     * @param positive-int $perResult characters one result may keep in the context
     * @param positive-int $perTurn characters all results of a turn may keep
     */
    public function __construct(
        public readonly int $perResult,
        public readonly int $perTurn,
    ) {}

    /** @param positive-int $windowTokens */
    public static function forWindow(int $windowTokens): self {
        $chars = $windowTokens * self::CHARS_PER_TOKEN;
        return new self(
            max(self::MIN_RESULT, min((int) ((float) $chars * self::PER_RESULT), self::MAX_RESULT)),
            max(self::MIN_TURN, min((int) ((float) $chars * self::PER_TURN), self::MAX_TURN)),
        );
    }

    /**
     * The results of a turn within the budget: the largest are shrunk first (spilled to a file, else cut), only as
     * many as needed.
     *
     * ref: references/hermes-agent/tools/tool_result_storage.py@e96221fa:L523-L560 (enforce_turn_budget) — the largest
     *      results are persisted until the turn fits
     * @param array<int, string> $contents
     * @param \Closure(string, int): string $shrink makes one result small (a preview and a path, or a cut)
     * @return array<int, string>
     */
    public function fit(array $contents, \Closure $shrink): array {
        $sizes = array_map('mb_strlen', $contents);
        $shrunk = [];
        while (array_sum($sizes) > $this->perTurn) {
            $largest = null;
            foreach ($sizes as $index => $size) {
                if (!isset($shrunk[$index]) && ($largest === null || $size > $sizes[$largest])) {
                    $largest = $index;
                }
            }
            if ($largest === null) {
                break;
            }
            $shrunk[$largest] = true;
            $contents[$largest] = $shrink($contents[$largest], $largest);
            $sizes[$largest] = mb_strlen($contents[$largest]);
        }
        return $contents;
    }
}
