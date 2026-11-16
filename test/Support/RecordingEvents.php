<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Agent\Trace\AgentEvents;

/** Remembers the events of runs, as "kind:run id:detail". */
final class RecordingEvents implements AgentEvents {
    /** @var list<string> */
    public array $events = [];

    public function runStarted(int $runId, string $model): void {
        $this->events[] = 'started:' . $runId;
    }

    public function modelCalled(int $runId, string $purpose, ChatRequest $request, ?ChatResponse $response, ?string $error, float $seconds): void {
        $this->events[] = 'model:' . $runId . ':' . $purpose;
    }

    public function toolFinished(int $runId, string $name, ?array $args, ToolResult $result, float $seconds): void {
        $this->events[] = 'tool:' . $runId . ':' . $name;
    }

    public function approvalDecided(int $runId, string $tool, string $outcome, float $seconds): void {
        $this->events[] = 'approval:' . $runId . ':' . $outcome;
    }

    public function runFinished(int $runId, string $status, ?string $reason, int $activeMs): void {
        $this->events[] = 'finished:' . $runId . ':' . $status;
    }
}
