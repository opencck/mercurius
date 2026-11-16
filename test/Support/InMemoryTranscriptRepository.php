<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Closure;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Repository\TranscriptRepository;

final class InMemoryTranscriptRepository implements TranscriptRepository {
    /** @var list<array{conversation: int, run: ?int, message: Message}> */
    public array $messages = [];
    /** @var array<int, array<string, StoredToolCall>> */
    public array $calls = [];
    /** @var array<int, list<string>> */
    public array $inboxes = [];
    /** @var (Closure(): void)|null called first in appendAssistant; throw from it to test save-before-execute */
    public ?Closure $beforeAssistant = null;

    public function appendUser(int $conversationId, ?int $runId, string $content, ?string $externalId = null): void {
        $this->messages[] = ['conversation' => $conversationId, 'run' => $runId, 'message' => Message::user($content)];
    }

    public function appendAssistant(int $conversationId, int $runId, string $text, array $calls): void {
        if ($this->beforeAssistant !== null) {
            ($this->beforeAssistant)();
        }
        $toolCalls = array_map(static fn(StoredToolCall $c): ToolCall => new ToolCall($c->callId, $c->name, $c->argumentsJson), $calls);
        $this->messages[] = ['conversation' => $conversationId, 'run' => $runId, 'message' => Message::assistant($text, $toolCalls)];
        foreach ($calls as $call) {
            $this->calls[$runId][$call->callId] = $call;
        }
    }

    public function startCall(int $runId, string $callId): void {
        $this->setStatus($runId, $callId, ToolCallStatus::Running);
    }

    public function appendToolResult(int $conversationId, int $runId, string $callId, ToolCallStatus $status, string $content): void {
        $this->messages[] = ['conversation' => $conversationId, 'run' => $runId, 'message' => Message::toolResult($callId, $content)];
        $this->setStatus($runId, $callId, $status);
    }

    public function runMessages(int $runId): array {
        $messages = [];
        foreach ($this->messages as $row) {
            if ($row['run'] === $runId) {
                $messages[] = $row['message'];
            }
        }
        return $messages;
    }

    public function history(int $conversationId, int $beforeRunId, int $limit): array {
        $history = [];
        foreach ($this->messages as $row) {
            $message = $row['message'];
            if ($row['conversation'] !== $conversationId || ($row['run'] !== null && $row['run'] >= $beforeRunId)) {
                continue;
            }
            if ($message->role === Role::User || ($message->role === Role::Assistant && $message->toolCalls === [] && $message->content !== '')) {
                $history[] = $message;
            }
        }
        return array_slice($history, -$limit);
    }

    public function pendingCalls(int $runId): array {
        $pending = [];
        foreach ($this->calls[$runId] ?? [] as $call) {
            if ($call->status === ToolCallStatus::Pending || $call->status === ToolCallStatus::Running) {
                $pending[] = $call;
            }
        }
        usort($pending, static fn(StoredToolCall $a, StoredToolCall $b): int => $a->position <=> $b->position);
        return $pending;
    }

    public function callIds(int $runId): array {
        $ids = [];
        foreach ($this->calls[$runId] ?? [] as $call) {
            $ids[] = $call->callId;
        }
        return $ids;
    }

    public function inbox(int $runId, string $content, ?string $externalId = null): void {
        $this->inboxes[$runId][] = $content;
    }

    public function consumeInbox(int $runId): array {
        $messages = $this->inboxes[$runId] ?? [];
        $this->inboxes[$runId] = [];
        return $messages;
    }

    public function status(int $runId, string $callId): ToolCallStatus {
        return $this->calls[$runId][$callId]->status;
    }

    private function setStatus(int $runId, string $callId, ToolCallStatus $status): void {
        $call = $this->calls[$runId][$callId];
        $this->calls[$runId][$callId] = new StoredToolCall($call->callId, $call->position, $call->name, $call->args, $call->argumentsJson, $call->danger, $status);
    }
}
