<?php

declare(strict_types=1);

namespace OpenCCK\App\Eval;

/**
 * Who judges an answer of the agent in the task set: a chat model or a service made for judging.
 *
 * spec: brief/decisions.md#границы-самоулучшения — a judge of another model family; the judge may be of any provider
 */
interface EvalJudge {
    /**
     * @param list<string> $trace the agent's tool calls
     * @return array{?float, string} the score 0..1 (null — no verdict) and why
     */
    public function verdict(EvalTask $task, array $trace, string $answer): array;
}
