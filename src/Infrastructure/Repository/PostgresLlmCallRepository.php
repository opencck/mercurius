<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use OpenCCK\Domain\Entity\LlmCallRecord;
use DateTimeImmutable;
use OpenCCK\Domain\Repository\LlmCallRepository;
use OpenCCK\Domain\Repository\SpendRepository;

final class PostgresLlmCallRepository implements LlmCallRepository, SpendRepository {
    public function __construct(private readonly PostgresLink $db) {}

    public function record(LlmCallRecord $call): void {
        $this->db->execute(
            'INSERT INTO llm_calls (conversation_id, purpose, model, finish_reason, error_kind, input_tokens, output_tokens,
                cache_read_tokens, cache_write_tokens, reasoning_tokens, cost_usd, duration_ms, run_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $call->conversationId, $call->purpose, $call->model, $call->finishReason, $call->errorKind,
                $call->inputTokens, $call->outputTokens, $call->cacheReadTokens, $call->cacheWriteTokens,
                $call->reasoningTokens, $call->costUsd, $call->durationMs, $call->runId,
            ],
        );
    }

    public function spentBy(int $principalId, DateTimeImmutable $since): float {
        $row = $this->db->execute(
            'SELECT coalesce(sum(c.cost_usd), 0)::float8 AS spent FROM llm_calls c JOIN conversations v ON v.id = c.conversation_id
             WHERE v.principal_id = ? AND c.created_at >= to_timestamp(?)',
            [$principalId, $since->getTimestamp()],
        )->fetchRow();
        $spent = $row['spent'] ?? 0.0;
        return is_float($spent) ? $spent : 0.0;
    }

    public function spentSince(DateTimeImmutable $since): float {
        $row = $this->db->execute('SELECT coalesce(sum(cost_usd), 0)::float8 AS spent FROM llm_calls WHERE created_at >= to_timestamp(?)', [$since->getTimestamp()])->fetchRow();
        $spent = $row['spent'] ?? 0.0;
        return is_float($spent) ? $spent : 0.0;
    }
}
