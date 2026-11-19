<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Closure;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\TranscriptEntry;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Repository\TranscriptRepository;

final class InMemoryTranscriptRepository implements TranscriptRepository {
    /** @var list<array{conversation: int, run: ?int, message: Message}> */
    public array $messages = [];
    /** @var array<int, array<string, StoredToolCall>> */
    public array $calls = [];
    /** @var array<int, list<string>> */
    public array $inboxes = [];
    /** @var array<int, string> message id => text the model gets instead */
    public array $compact = [];
    /** @var (Closure(): void)|null called first in appendAssistant; throw from it to test save-before-execute */
    public ?Closure $beforeAssistant = null;
    /** @var (Closure(): void)|null called first in appendToolResult */
    public ?Closure $beforeToolResult = null;
    /** @var (Closure(): void)|null called first in appendUser */
    public ?Closure $beforeUser = null;
    /** @var list<array{int, string, int}> lines with a channel message id: conversation, text, unix seconds */
    private array $spoken = [];
    /** @var array<int, int> conversation => its person; a conversation not here belongs to the person of its number */
    public array $owners = [];

    public function appendUser(int $conversationId, ?int $runId, string $content, ?string $externalId = null): void {
        if ($this->beforeUser !== null) {
            ($this->beforeUser)();
        }
        $this->messages[] = ['conversation' => $conversationId, 'run' => $runId, 'message' => Message::user($content)];
        if ($externalId !== null) {
            $this->spoken[] = [$conversationId, $content, time()];
        }
    }

    public function spokenBy(int $principalId, int $since, int $limit): array {
        $lines = [];
        foreach (array_reverse($this->spoken) as [$conversation, $content, $at]) {
            if (($this->owners[$conversation] ?? $conversation) === $principalId && $at >= $since && count($lines) < $limit) {
                $lines[] = [$content, $at];
            }
        }
        return $lines;
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

    public function setCallStatus(int $runId, string $callId, ToolCallStatus $status): void {
        $this->setStatus($runId, $callId, $status);
    }

    public function appendToolResult(int $conversationId, int $runId, string $callId, ToolCallStatus $status, string $content): void {
        if ($this->beforeToolResult !== null) {
            ($this->beforeToolResult)();
        }
        $this->messages[] = ['conversation' => $conversationId, 'run' => $runId, 'message' => Message::toolResult($callId, $content)];
        $this->setStatus($runId, $callId, $status);
    }

    public function runMessages(int $runId): array {
        return array_map(static fn(TranscriptEntry $e): Message => $e->message, $this->runEntries($runId));
    }

    public function runEntries(int $runId, int $afterId = 0): array {
        $entries = [];
        foreach ($this->messages as $index => $row) {
            $id = $index + 1;
            if ($row['run'] === $runId && $id > $afterId) {
                $entries[] = $this->entry($id, $row['message'], $row['run']);
            }
        }
        return $entries;
    }

    public function historyEntries(int $conversationId, int $beforeRunId, int $limit, int $afterId = 0): array {
        $entries = [];
        foreach ($this->messages as $index => $row) {
            $message = $row['message'];
            if ($row['conversation'] !== $conversationId || ($row['run'] !== null && $row['run'] >= $beforeRunId) || $index + 1 <= $afterId) {
                continue;
            }
            if ($message->role === Role::User || ($message->role === Role::Assistant && $message->toolCalls === [] && $message->content !== '')) {
                $entries[] = $this->entry($index + 1, $message, null);
            }
        }
        return array_slice($entries, -$limit);
    }

    public function compactContent(int $messageId, string $content): void {
        $this->compact[$messageId] = $content;
    }

    public function redactCalls(string $text, string $replacement): int {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $from = substr(json_encode($text, $flags), 1, -1);
        $to = substr(json_encode($replacement, $flags), 1, -1);
        $changed = 0;
        $rows = [];
        foreach ($this->messages as $row) {
            $m = $row['message'];
            $calls = [];
            $touched = false;
            foreach ($m->toolCalls as $call) {
                if ($text !== '' && str_contains($call->argumentsJson, $from)) {
                    $call = new \Mercurius\Llm\ToolCall($call->id, $call->name, str_replace($from, $to, $call->argumentsJson));
                    $touched = true;
                }
                $calls[] = $call;
            }
            if ($touched) {
                $row['message'] = new Message($m->role, $m->content, $calls, $m->toolCallId);
                $changed++;
            }
            $rows[] = $row;
        }
        $this->messages = $rows;
        return $changed;
    }

    public function redact(string $text, string $replacement): int {
        $changed = 0;
        $messages = [];
        foreach ($this->messages as $row) {
            $m = $row['message'];
            if ($text !== '' && str_contains($m->content, $text)) {
                $row['message'] = new Message($m->role, str_replace($text, $replacement, $m->content), $m->toolCalls, $m->toolCallId);
                $changed++;
            }
            $messages[] = $row;
        }
        $this->messages = $messages;
        foreach ($this->compact as $id => $content) {
            $this->compact[$id] = str_replace($text, $replacement, $content);
        }
        return $changed;
    }

    public function userMessages(int $conversationId, int $afterId, int $uptoId): array {
        $texts = [];
        foreach ($this->messages as $index => $row) {
            if ($row['conversation'] === $conversationId && $row['message']->role === Role::User && $index + 1 > $afterId && $index + 1 <= $uptoId) {
                $texts[] = $row['message']->content;
            }
        }
        return $texts;
    }

    private function entry(int $id, Message $message, ?int $runId): TranscriptEntry {
        $content = $this->compact[$id] ?? null;
        if ($content !== null) {
            $message = match ($message->role) {
                Role::Tool => Message::toolResult($message->toolCallId ?? '', $content),
                Role::User => Message::user($content),
                default => Message::assistant($content, $message->toolCalls),
            };
        }
        $tool = null;
        if ($message->role === Role::Tool && $runId !== null) {
            $tool = ($this->calls[$runId][$message->toolCallId ?? ''] ?? null)?->name;
        }
        return new TranscriptEntry($id, $message, $tool);
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
            if (in_array($call->status, [ToolCallStatus::Pending, ToolCallStatus::Running, ToolCallStatus::AwaitingApproval], true)) {
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
