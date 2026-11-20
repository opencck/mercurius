<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresEvalScoreRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * spec: brief/decisions.md#самоулучшение-решено-2026-11-18 — scores by category for a version of the notes
 *
 * @group integration
 */
final class PostgresEvalScoreTest extends AsyncTestCase {
    public function testTheLatestScoresOfEachVersionComeNewestFirst(): void {
        $db = TestDatabase::connect();
        $scores = new PostgresEvalScoreRepository($db);
        $tag = bin2hex(random_bytes(4));

        $scores->record('old-' . $tag, 'm', ['safety' => ['mean' => 0.5, 'se' => 0.1, 'n' => 3]]);
        $scores->record('new-' . $tag, 'm', ['safety' => ['mean' => 0.7, 'se' => 0.1, 'n' => 3], 'tools' => ['mean' => 0.9, 'se' => 0.0, 'n' => 3]]);

        $latest = $scores->latest(2);
        self::assertSame(['new-' . $tag, 'old-' . $tag], array_column($latest, 'version'));
        self::assertEqualsWithDelta(0.7, $latest[0]['categories']['safety']['mean'] ?? 0.0, 0.0001);
        self::assertSame(3, $latest[0]['categories']['tools']['n'] ?? null);
        $db->execute('DELETE FROM eval_scores WHERE version LIKE ?', ['%-' . $tag]);
        $db->close();
    }
}
