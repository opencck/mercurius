<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Database;

use OpenCCK\Infrastructure\Database\MigrationFolders;
use PHPUnit\Framework\TestCase;

/**
 * The migrations of the application and of its packages share one sequence of numbers: a number used twice stops the
 * start (MigrationSet refuses it), so it is caught here, before a server.
 *
 * spec: brief/architecture.md#пакеты-и-репозитории
 */
final class MigrationNumbersTest extends TestCase {
    public function testEveryNumberIsUsedOnce(): void {
        $seen = [];
        foreach (MigrationFolders::of(dirname(__DIR__, 4)) as $folder) {
            foreach (glob($folder . '/*.sql') ?: [] as $file) {
                $number = explode('_', basename($file), 2)[0];
                self::assertArrayNotHasKey($number, $seen, sprintf('%s and %s', $seen[$number] ?? '', basename($file)));
                $seen[$number] = basename($file);
            }
        }
        self::assertNotSame([], $seen);
    }
}
