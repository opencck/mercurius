<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Database;

use Amp\Postgres\PostgresLink;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\File\listFiles;
use function Amp\File\read;

/**
 * Applies pending SQL migrations at startup.
 *
 * Everything runs in one transaction under an advisory lock, so two instances starting together
 * cannot apply the same migration twice, and a failed migration leaves the schema unchanged.
 */
final class Migrator {
    /** Arbitrary constant key for pg_advisory_xact_lock. */
    private const LOCK_KEY = 7_331_001;

    public function __construct(
        private readonly PostgresLink $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** @throws MigrationException */
    public static function loadDirectory(string $directory): MigrationSet {
        $files = [];
        foreach (listFiles($directory) as $name) {
            if (str_ends_with($name, '.sql')) {
                $files[$name] = read($directory . '/' . $name);
            }
        }
        return MigrationSet::fromFiles($files);
    }

    /**
     * @return list<int> versions applied by this call
     * @throws MigrationException|Throwable
     */
    public function migrate(MigrationSet $set): array {
        $tx = $this->db->beginTransaction();
        try {
            $tx->execute('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            $tx->query(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version integer PRIMARY KEY,
                    name text NOT NULL,
                    applied_at timestamptz NOT NULL DEFAULT now()
                )',
            );

            $applied = [];
            foreach ($tx->query('SELECT version FROM schema_migrations ORDER BY version') as $row) {
                $version = $row['version'] ?? null;
                if (!is_int($version)) {
                    throw new MigrationException('schema_migrations.version must be an integer');
                }
                $applied[] = $version;
            }

            $done = [];
            foreach ($set->pending($applied) as $migration) {
                $this->logger->info('Applying migration', ['version' => $migration->version, 'name' => $migration->name]);
                $tx->query($migration->sql);
                $tx->execute('INSERT INTO schema_migrations (version, name) VALUES (?, ?)', [$migration->version, $migration->name]);
                $done[] = $migration->version;
            }

            $tx->commit();
            return $done;
        } catch (Throwable $e) {
            if ($tx->isActive()) {
                $tx->rollback();
            }
            throw $e;
        }
    }
}
