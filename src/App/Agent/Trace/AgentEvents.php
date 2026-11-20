<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Trace;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use OpenCCK\App\Agent\Tool\ToolResult;

/**
 * What happens in a run, told to whoever listens (tracing, metrics). The loop only tells; it does not trace itself.
 * A listener must not throw and must not slow the run down noticeably.
 *
 * spec: brief/architecture.md#устройство-приложения-на-opencck — tracing subscribes to events instead of living in the loop
 * spec: brief/operations.md#команды-и-отчёты-владельца
 */
interface AgentEvents {
    /** A run starts or resumes after a restart. */
    public function runStarted(int $runId, string $model): void;

    /**
     * A model call ended, successfully or not.
     *
     * @param string $purpose agent, compaction, ...
     */
    public function modelCalled(int $runId, string $purpose, ChatRequest $request, ?ChatResponse $response, ?string $error, float $seconds): void;

    /** @param array<string, mixed>|null $args */
    public function toolFinished(int $runId, string $name, ?array $args, ToolResult $result, float $seconds): void;

    /** The owner decided on an approval (or it expired): approved, denied, expired. */
    public function approvalDecided(int $runId, string $tool, string $outcome, float $seconds): void;

    /**
     * A worker run of an orchestrator ended: done, failed, limit or stopped.
     *
     * spec: brief/operations.md#шаги-и-хранение — nested traces
     */
    public function workerFinished(int $parentRunId, int $workerRunId, string $model, string $status, float $seconds): void;

    /** A run ended: done, failed or stopped. */
    public function runFinished(int $runId, string $status, ?string $reason, int $activeMs): void;
}
