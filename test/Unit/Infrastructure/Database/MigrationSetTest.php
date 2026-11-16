<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Database;

use OpenCCK\Infrastructure\Database\MigrationException;
use OpenCCK\Infrastructure\Database\MigrationSet;
use PHPUnit\Framework\TestCase;

final class MigrationSetTest extends TestCase {
    public function testSortsByVersion(): void {
        $set = MigrationSet::fromFiles([
            '0002_users.sql' => 'CREATE TABLE users ();',
            '0001_init.sql' => 'SELECT 1;',
        ]);

        self::assertSame([1, 2], array_map(static fn($m) => $m->version, $set->all()));
        self::assertSame('init', $set->all()[0]->name);
    }

    public function testPendingSkipsApplied(): void {
        $set = MigrationSet::fromFiles([
            '0001_init.sql' => 'SELECT 1;',
            '0002_users.sql' => 'SELECT 2;',
            '0003_facts.sql' => 'SELECT 3;',
        ]);

        self::assertSame([3], array_map(static fn($m) => $m->version, $set->pending([1, 2])));
        self::assertSame([], $set->pending([1, 2, 3]));
    }

    public function testRejectsBadFileName(): void {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('init.sql');
        MigrationSet::fromFiles(['init.sql' => 'SELECT 1;']);
    }

    public function testRejectsDuplicateVersion(): void {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('0001');
        MigrationSet::fromFiles([
            '0001_a.sql' => 'SELECT 1;',
            '0001_b.sql' => 'SELECT 2;',
        ]);
    }

    public function testRejectsEmptySql(): void {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('0001_empty.sql');
        MigrationSet::fromFiles(['0001_empty.sql' => "  \n"]);
    }

    public function testRejectsAppliedVersionUnknownToFiles(): void {
        $set = MigrationSet::fromFiles(['0001_init.sql' => 'SELECT 1;']);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('7');
        $set->pending([1, 7]);
    }
}
