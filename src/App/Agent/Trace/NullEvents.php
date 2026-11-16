<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Trace;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use OpenCCK\App\Agent\Tool\ToolResult;

/** Nobody listens. */
final class NullEvents implements AgentEvents {
    public function runStarted(int $runId, string $model): void {}

    public function modelCalled(int $runId, string $purpose, ChatRequest $request, ?ChatResponse $response, ?string $error, float $seconds): void {}

    public function toolFinished(int $runId, string $name, ?array $args, ToolResult $result, float $seconds): void {}

    public function approvalDecided(int $runId, string $tool, string $outcome, float $seconds): void {}

    public function runFinished(int $runId, string $status, ?string $reason, int $activeMs): void {}
}
