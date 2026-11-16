<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use Mercurius\Llm\Message;
use OpenCCK\Domain\Agent\StoredToolCall;
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

    /** @return list<StoredToolCall> calls of the run that have no result yet, in order */
    public function pendingCalls(int $runId): array;

    /** @return list<string> */
    public function callIds(int $runId): array;

    public function inbox(int $runId, string $content, ?string $externalId = null): void;

    /** @return list<string> steering messages not delivered yet, each returned once */
    public function consumeInbox(int $runId): array;
}
