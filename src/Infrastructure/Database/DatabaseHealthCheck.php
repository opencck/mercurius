<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Database;

use Amp\Postgres\PostgresExecutor;
use OpenCCK\App\Service\HealthCheck;

final class DatabaseHealthCheck implements HealthCheck {
    public function __construct(private readonly PostgresExecutor $db) {}

    public function name(): string {
        return 'database';
    }

    public function check(): void {
        $this->db->query('SELECT 1');
    }
}
