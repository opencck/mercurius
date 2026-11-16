<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Mercurius\Llm\Message;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Throwable;
use UnexpectedValueException;

/**
 * spec: BRIEF.md#запуск-и-цикл — the answer with its calls is saved in one transaction before any tool runs
 * ref: references/hermes-agent/agent/turn_tool_round.py@e96221f:L130-L153 — persist the assistant message before side effects
 */
final class PostgresTranscriptRepository implements TranscriptRepository {
    private const PREVIEW_CHARS = 1500;

    public function __construct(private readonly PostgresLink $db) {}

    public function appendUser(int $conversationId, ?int $runId, string $content, ?string $externalId = null): void {
        $this->db->execute(
            "INSERT INTO messages (conversation_id, run_id, role, content, external_message_id) VALUES (?, ?, 'user', ?, ?)",
            [$conversationId, $runId, $content, $externalId],
        );
    }

    public function appendAssistant(int $conversationId, int $runId, string $text, array $calls): void {
        $toolCalls = $calls === [] ? null : json_encode(
            array_map(static fn(StoredToolCall $c): array => ['id' => $c->callId, 'name' => $c->name, 'arguments' => $c->argumentsJson], $calls),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $transaction = $this->db->beginTransaction();
        try {
            $transaction->execute(
                "INSERT INTO messages (conversation_id, run_id, role, content, tool_calls) VALUES (?, ?, 'assistant', ?, ?::jsonb)",
                [$conversationId, $runId, $text, $toolCalls],
            );
            foreach ($calls as $call) {
                $transaction->execute(
                    'INSERT INTO tool_calls (run_id, call_id, position, name, arguments, danger, status) VALUES (?, ?, ?, ?, ?::jsonb, ?, ?)',
                    [
                        $runId,
                        $call->callId,
                        $call->position,
                        $call->name,
                        $call->args === null ? null : json_encode((object) $call->args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        $call->danger->value,
                        $call->status->value,
                    ],
                );
            }
            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function startCall(int $runId, string $callId): void {
        $this->db->execute(
            "UPDATE tool_calls SET status = 'running', started_at = now() WHERE run_id = ? AND call_id = ?",
            [$runId, $callId],
        );
    }

    public function setCallStatus(int $runId, string $callId, ToolCallStatus $status): void {
        $this->db->execute('UPDATE tool_calls SET status = ? WHERE run_id = ? AND call_id = ?', [$status->value, $runId, $callId]);
    }

    public function appendToolResult(int $conversationId, int $runId, string $callId, ToolCallStatus $status, string $content): void {
        $transaction = $this->db->beginTransaction();
        try {
            $transaction->execute(
                "INSERT INTO messages (conversation_id, run_id, role, content, tool_call_id) VALUES (?, ?, 'tool', ?, ?)",
                [$conversationId, $runId, $content, $callId],
            );
            $transaction->execute(
                'UPDATE tool_calls SET status = ?, result_preview = ?, finished_at = now() WHERE run_id = ? AND call_id = ?',
                [$status->value, mb_substr($content, 0, self::PREVIEW_CHARS), $runId, $callId],
            );
            $transaction->commit();
        } catch (Throwable $e) {
            if ($transaction->isActive()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    public function runMessages(int $runId): array {
        $messages = [];
        foreach ($this->db->execute(
            'SELECT role, content, tool_calls::text AS tool_calls, tool_call_id FROM messages WHERE run_id = ? ORDER BY id',
            [$runId],
        ) as $row) {
            $messages[] = self::message($row);
        }
        return $messages;
    }

    public function history(int $conversationId, int $beforeRunId, int $limit): array {
        $result = $this->db->execute(
            "SELECT role, content, NULL AS tool_calls, NULL AS tool_call_id FROM (
                SELECT id, role, content FROM messages
                WHERE conversation_id = ? AND (run_id IS NULL OR run_id < ?)
                  AND (role = 'user' OR (role = 'assistant' AND tool_calls IS NULL AND content <> ''))
                ORDER BY id DESC LIMIT ?
             ) last ORDER BY id",
            [$conversationId, $beforeRunId, $limit],
        );
        $messages = [];
        foreach ($result as $row) {
            $messages[] = self::message($row);
        }
        return $messages;
    }

    public function pendingCalls(int $runId): array {
        $calls = [];
        foreach ($this->db->execute(
            "SELECT call_id, position, name, arguments::text AS arguments, danger, status FROM tool_calls
             WHERE run_id = ? AND status IN ('pending', 'running', 'awaiting_approval') ORDER BY position",
            [$runId],
        ) as $row) {
            $arguments = self::nullableString($row, 'arguments');
            /** @var mixed $decoded */
            $decoded = $arguments === null ? null : json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
            $args = is_array($decoded) ? self::stringKeys($decoded) : null;
            $calls[] = new StoredToolCall(
                self::string($row, 'call_id'),
                self::int($row, 'position'),
                self::string($row, 'name'),
                $args,
                $arguments ?? '{}',
                DangerClass::from(self::string($row, 'danger')),
                ToolCallStatus::from(self::string($row, 'status')),
            );
        }
        return $calls;
    }

    public function callIds(int $runId): array {
        $ids = [];
        foreach ($this->db->execute('SELECT call_id FROM tool_calls WHERE run_id = ? ORDER BY call_id', [$runId]) as $row) {
            $ids[] = self::string($row, 'call_id');
        }
        return $ids;
    }

    public function inbox(int $runId, string $content, ?string $externalId = null): void {
        $this->db->execute(
            'INSERT INTO run_inbox (run_id, content, external_message_id) VALUES (?, ?, ?)',
            [$runId, $content, $externalId],
        );
    }

    public function consumeInbox(int $runId): array {
        $messages = [];
        foreach ($this->db->execute(
            'WITH consumed AS (
                UPDATE run_inbox SET consumed_at = now() WHERE run_id = ? AND consumed_at IS NULL RETURNING id, content
             )
             SELECT content FROM consumed ORDER BY id',
            [$runId],
        ) as $row) {
            $messages[] = self::string($row, 'content');
        }
        return $messages;
    }

    /** @param array<string, mixed> $row */
    private static function message(array $row): Message {
        $content = self::string($row, 'content');
        return match (self::string($row, 'role')) {
            'user' => Message::user($content),
            'assistant' => Message::assistant($content, self::toolCalls(self::nullableString($row, 'tool_calls'))),
            'tool' => Message::toolResult(self::string($row, 'tool_call_id'), $content),
            default => throw new UnexpectedValueException('Malformed messages row: role'),
        };
    }

    /** @return list<ToolCall> */
    private static function toolCalls(?string $json): array {
        if ($json === null) {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Malformed messages row: tool_calls');
        }
        $calls = [];
        foreach (array_keys($decoded) as $index) {
            /** @var mixed $call */
            $call = $decoded[$index];
            if (!is_array($call)) {
                throw new UnexpectedValueException('Malformed messages row: tool_calls');
            }
            /** @var array<string, mixed> $call */
            $calls[] = new ToolCall(self::string($call, 'id'), self::string($call, 'name'), self::string($call, 'arguments'));
        }
        return $calls;
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<string, mixed>
     */
    private static function stringKeys(array $array): array {
        return array_combine(array_map('strval', array_keys($array)), array_values($array));
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key): int {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException(sprintf('Malformed row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $key): string {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new UnexpectedValueException(sprintf('Malformed row: %s', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $key): ?string {
        return isset($row[$key]) ? self::string($row, $key) : null;
    }
}
