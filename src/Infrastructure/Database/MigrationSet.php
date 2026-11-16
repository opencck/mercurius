<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Database;

/**
 * Ordered set of SQL migrations named `NNNN_snake_name.sql`.
 */
final class MigrationSet {
    private const FILE_NAME = '/^(\d{4})_([a-z0-9_]+)\.sql$/';

    /** @param list<Migration> $migrations sorted by version */
    private function __construct(private readonly array $migrations) {}

    /**
     * @param array<string, string> $files file name => SQL
     * @throws MigrationException
     */
    public static function fromFiles(array $files): self {
        $byVersion = [];
        foreach ($files as $file => $sql) {
            if (!preg_match(self::FILE_NAME, $file, $match)) {
                throw new MigrationException(sprintf('Migration file name %s must look like 0001_name.sql', $file));
            }
            $version = (int) $match[1];
            if ($version < 1) {
                throw new MigrationException(sprintf('Migration %s must have a version above 0000', $file));
            }
            if (isset($byVersion[$version])) {
                throw new MigrationException(sprintf('Migration version %s is used more than once', $match[1]));
            }
            $sql = trim($sql);
            if ($sql === '') {
                throw new MigrationException(sprintf('Migration %s is empty', $file));
            }
            $byVersion[$version] = new Migration($version, $match[2], $sql);
        }
        ksort($byVersion);
        return new self(array_values($byVersion));
    }

    /** @return list<Migration> */
    public function all(): array {
        return $this->migrations;
    }

    /**
     * @param list<int> $applied versions already recorded in the database
     * @return list<Migration>
     * @throws MigrationException when the database knows a version that no file describes
     */
    public function pending(array $applied): array {
        $known = array_map(static fn(Migration $m): int => $m->version, $this->migrations);
        $unknown = array_diff($applied, $known);
        if ($unknown !== []) {
            throw new MigrationException(sprintf(
                'Database has migrations with no file: %s',
                implode(', ', $unknown),
            ));
        }
        return array_values(array_filter(
            $this->migrations,
            static fn(Migration $m): bool => !in_array($m->version, $applied, true),
        ));
    }
}
