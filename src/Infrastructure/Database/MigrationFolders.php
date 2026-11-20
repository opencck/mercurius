<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Database;

use Mercurius\Memory\Migrations as MemoryMigrations;

/**
 * Where the migrations of the agent lie: its own folder and the folders of the packages that keep tables. They are
 * numbered in one sequence and applied together, by number.
 *
 * spec: BRIEF.md#память-слои — the memory package brings the migrations of its tables
 */
final class MigrationFolders {
    /** @return list<string> */
    public static function of(string $root): array {
        return [rtrim($root, '/') . '/migrations', MemoryMigrations::directory()];
    }
}
