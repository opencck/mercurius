<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use Mercurius\Llm\Message;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\TranscriptEntry;
use OpenCCK\Domain\Agent\ToolCallStatus;

/**
 * Messages of conversations and runs, tool calls and steering messages.
 *
 * spec: BRIEF.md#запуск-и-цикл — save before execution, history of earlier runs
 */
interface TranscriptRepository {
    public function appendUser(int $conversationId, ?int $runId, string $content, ?string $externalId = null): void;

    /**
     * The model's answer with its tool calls, in one transaction: if it fails, nothing is saved.
     *
     * @param list<StoredToolCall> $calls
     */
    public function appendAssistant(int $conversationId, int $runId, string $text, array $calls): void;

    public function startCall(int $runId, string $callId): void;

    public function setCallStatus(int $runId, string $callId, ToolCallStatus $status): void;

    /** The result message and the call's final status, in one transaction. */
    public function appendToolResult(int $conversationId, int $runId, string $callId, ToolCallStatus $status, string $content): void;

    /** @return list<Message> every message of the run in order */
    public function runMessages(int $runId): array;

    /**
     * Earlier runs and messages before them: user messages and final answers only, the last $limit in order.
     *
     * @param positive-int $limit
     * @return list<Message>
     */
    public function history(int $conversationId, int $beforeRunId, int $limit): array;

    /**
     * Messages of the run after $afterId, with ids and tool names, in order.
     *
     * @return list<TranscriptEntry>
     */
    public function runEntries(int $runId, int $afterId = 0): array;

    /**
     * Like history(), with ids, and only messages after $afterId.
     *
     * @param positive-int $limit
     * @return list<TranscriptEntry>
     */
    public function historyEntries(int $conversationId, int $beforeRunId, int $limit, int $afterId = 0): array;

    /** Text the model gets instead of the message from now on; the original stays in the store. */
    public function compactContent(int $messageId, string $content): void;

    /** @return list<string> user messages of the conversation with $afterId < id <= $uptoId, in order */
    public function userMessages(int $conversationId, int $afterId, int $uptoId): array;

    /** @return list<StoredToolCall> calls of the run that have no result yet (pending, running, awaiting approval), in order */
    public function pendingCalls(int $runId): array;

    /** @return list<string> */
    public function callIds(int $runId): array;

    public function inbox(int $runId, string $content, ?string $externalId = null): void;

    /** @return list<string> steering messages not delivered yet, each returned once */
    public function consumeInbox(int $runId): array;
}
