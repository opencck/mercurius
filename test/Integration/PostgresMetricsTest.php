<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\MetricsText;
use OpenCCK\Infrastructure\Repository\PostgresMetricsRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMetricsTest extends AsyncTestCase {
    public function testMetricsRenderFromTheDatabase(): void {
        $db = TestDatabase::connect();
        try {
            $text = (new MetricsText(new PostgresMetricsRepository($db)))->render();
        } finally {
            $db->close();
        }

        self::assertStringContainsString('# TYPE mercurius_runs_total counter', $text);
        self::assertStringContainsString('# TYPE mercurius_step_duration_seconds histogram', $text);
        // spec: brief/operations.md#метрики-prometheus — workers per run; every run has at most +Inf workers
        self::assertMatchesRegularExpression('/^mercurius_workers_per_run_count (\d+)$/m', $text);
        preg_match('/^mercurius_workers_per_run_bucket\{le="\+Inf"\} (\d+)$/m', $text, $all);
        preg_match('/^mercurius_workers_per_run_count (\d+)$/m', $text, $count);
        self::assertSame($count[1] ?? null, $all[1] ?? null);
    }
}
