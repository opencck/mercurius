<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use OpenCCK\Domain\Agent\DangerClass;

/**
 * Splits the calls of one answer into ordered segments: consecutive reads run in parallel (at most 8),
 * a write or an invalid call is a segment of its own. Order is kept, so results and side effects match
 * fully sequential execution.
 *
 * spec: BRIEF.md#запуск-и-цикл — reads in parallel, a write is a barrier
 * ref: references/hermes-agent/agent/tool_dispatch_helpers.py@e96221f:L213-L259 (_plan_tool_batch_segments) — ordered
 *      segments with barriers; we split by danger class instead of a whitelist and path overlap (no file tools yet)
 * ref: references/hermes-agent/run_agent.py@e96221f:L143 (_MAX_TOOL_WORKERS) — at most 8 at once
 */
final class BatchPlanner {
    public const MAX_PARALLEL = 8;

    /**
     * @param list<ValidatedCall> $calls
     * @return list<non-empty-list<ValidatedCall>>
     */
    public function segments(array $calls): array {
        $segments = [];
        $current = [];
        foreach ($calls as $call) {
            if ($call->executable() && $call->danger === DangerClass::Read) {
                $current[] = $call;
                if (count($current) === self::MAX_PARALLEL) {
                    $segments[] = $current;
                    $current = [];
                }
                continue;
            }
            if ($current !== []) {
                $segments[] = $current;
                $current = [];
            }
            $segments[] = [$call];
        }
        if ($current !== []) {
            $segments[] = $current;
        }
        return $segments;
    }
}
