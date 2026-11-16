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
    }
}
