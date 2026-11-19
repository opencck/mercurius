<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\Infrastructure\Repository\PostgresTraceReviewRepository;
use OpenCCK\Test\Support\TestDatabase;
use PHPUnit\Framework\Assert;

/**
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18 — the review of traces leaves out the gate's eval runs
 *
 * @group integration
 */
final class PostgresTraceReviewTest extends AsyncTestCase {
    public function testRunsOfTheEvalChannelAreNotReviewed(): void {
        $db = TestDatabase::connect();
        $tag = bin2hex(random_bytes(4));
        $row = $db->execute("INSERT INTO principals (kind, role) VALUES ('human', 'user') RETURNING id")->fetchRow();
        $principal = $row['id'] ?? null;
        Assert::assertIsInt($principal);
        $run = static function (string $channel, string $reason) use ($db, $principal, $tag): void {
            $row = $db->execute('INSERT INTO conversations (principal_id, channel, chat_id) VALUES (?, ?, ?) RETURNING id', [$principal, $channel, 'review-' . $tag])->fetchRow();
            $conversation = $row['id'] ?? null;
            Assert::assertIsInt($conversation);
            $db->execute(
                "INSERT INTO runs (conversation_id, principal_id, model, status, stop_reason, max_turns, token_budget, run_timeout, tool_timeout)
                 VALUES (?, ?, 'm', 'done', ?, 1, 1, 1, 1)",
                [$conversation, $principal, $reason],
            );
            $db->execute("INSERT INTO llm_calls (conversation_id, purpose, model, cost_usd) VALUES (?, ?, 'm', 0.5)", [$conversation, 'review-' . $reason]);
        };

        $run('eval', 'eval-' . $tag);
        $run('telegram', 'real-' . $tag);
        $review = (new PostgresTraceReviewRepository($db))->review(new DateTimeImmutable('-1 hour'));

        $reasons = array_column($review['stops'], 'reason');
        self::assertContains('real-' . $tag, $reasons);
        self::assertNotContains('eval-' . $tag, $reasons, 'the gate runs are not part of the review');
        $purposes = array_column($review['spend'], 'purpose');
        self::assertContains('review-real-' . $tag, $purposes);
        self::assertNotContains('review-eval-' . $tag, $purposes);

        $db->execute("DELETE FROM llm_calls WHERE purpose LIKE ?", ['review-%' . $tag]);
        $db->execute('DELETE FROM runs WHERE conversation_id IN (SELECT id FROM conversations WHERE chat_id = ?)', ['review-' . $tag]);
        $db->execute('DELETE FROM conversations WHERE chat_id = ?', ['review-' . $tag]);
        $db->execute('DELETE FROM principals WHERE id = ?', [$principal]);
        $db->close();
    }
}
