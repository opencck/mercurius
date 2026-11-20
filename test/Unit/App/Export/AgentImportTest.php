<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Export;

use Amp\PHPUnit\AsyncTestCase;
use InvalidArgumentException;
use OpenCCK\App\Export\AgentExport;
use OpenCCK\App\Export\AgentImport;
use OpenCCK\App\Export\ArchiveCrypto;
use RuntimeException;

/**
 * spec: brief/operations.md#экспорт-и-импорт-агента — a full import: opened by the password, applied at the next start
 */
final class AgentImportTest extends AsyncTestCase {
    private const DB = ['host' => 'postgres', 'port' => 5432, 'user' => 'mercurius', 'password' => 'секрет базы', 'name' => 'mercurius'];

    /** @var list<list<string>> */
    private static array $commands = [];
    private static bool $restoreFails = false;
    private string $storage = '';

    protected function setUp(): void {
        parent::setUp();
        self::$commands = [];
        self::$restoreFails = false;
        $this->storage = sys_get_temp_dir() . '/import-' . bin2hex(random_bytes(4));
        mkdir($this->storage);
    }

    protected function tearDown(): void {
        AgentImport::remove($this->storage);
        parent::tearDown();
    }

    /**
     * Stands in for tar, psql and pg_restore: the archive is "a=b|c=d" of its parts.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function fake(array $command, array $env): void {
        self::$commands[] = $command;
        if ($command[0] === 'tar' && $command[1] === '-xf') {
            foreach (explode('|', (string) file_get_contents($command[2])) as $part) {
                [$name, $content] = explode('=', $part, 2) + [1 => ''];
                file_put_contents(($command[4] ?? '') . '/' . $name, $content);
            }
        } elseif ($command[0] === 'pg_restore') {
            self::assertSame('секрет базы', $env['PGPASSWORD'] ?? null);
            if (self::$restoreFails) {
                throw new RuntimeException('pg_restore exited with 1');
            }
        }
    }

    /** @return list<list<string>> */
    private static function commands(): array {
        return self::$commands;
    }

    private function archive(string $parts): string {
        file_put_contents($this->storage . '/plain', $parts);
        ArchiveCrypto::encrypt($this->storage . '/plain', $this->storage . '/agent' . AgentExport::EXTENSION, 'пароль владельца 1');
        unlink($this->storage . '/plain');
        return $this->storage . '/agent' . AgentExport::EXTENSION;
    }

    private function import(): AgentImport {
        return new AgentImport(self::fake(...), self::DB, $this->storage);
    }

    public function testAnArchiveIsPreparedThenAppliedAtTheStart(): void {
        $import = $this->import();
        $archive = $this->archive("db.dump=DUMP|app.tar.gz=APP|workspace.tar.gz=WS|env=SECRETS_KEY=a2V5\nOTHER=1\n|commit=abc123\n");

        self::assertSame(['commit' => 'abc123', 'bytes' => 4], $import->prepare($archive, 'пароль владельца 1'));
        self::assertTrue($import->prepared());
        self::assertNull($import->apply(), 'nothing is applied before the yes');

        $import->commit();
        $applied = $import->apply();

        self::assertNotNull($applied);
        self::assertSame('abc123', $applied['commit']);
        self::assertSame('WS', file_get_contents($applied['dir'] . '/workspace.tar.gz'), 'the working folders wait for the executor');
        self::assertFileDoesNotExist($applied['dir'] . '/db.dump');
        self::assertSame('a2V5', AgentImport::secretsKey($this->storage));
        $programs = array_map(static fn(array $c): string => $c[0], self::commands());
        self::assertSame(['tar', 'psql', 'pg_restore', 'tar'], $programs);
        self::assertContains('DROP SCHEMA public CASCADE; CREATE SCHEMA public;', self::commands()[1]);
        foreach (self::commands() as $command) {
            self::assertNotContains('секрет базы', $command, 'no password in a command line');
        }
        self::assertNull($import->apply(), 'applied once');
    }

    public function testAWrongPasswordLeavesNothing(): void {
        $import = $this->import();
        $archive = $this->archive('db.dump=D|app.tar.gz=A|env=|commit=c');

        try {
            $import->prepare($archive, 'чужой пароль 12345');
            self::fail('a wrong password opened the archive');
        } catch (InvalidArgumentException) {
        }
        self::assertFalse($import->prepared());
    }

    public function testAnArchiveWithoutItsDumpIsRefused(): void {
        $import = $this->import();
        $archive = $this->archive('app.tar.gz=A|env=|commit=c');

        $this->expectException(InvalidArgumentException::class);
        $import->prepare($archive, 'пароль владельца 1');
    }

    public function testAFailedRestoreIsSetAsideAndNotRetried(): void {
        $import = $this->import();
        $import->prepare($this->archive('db.dump=D|app.tar.gz=A|env=|commit=c'), 'пароль владельца 1');
        $import->commit();
        self::$restoreFails = true;

        try {
            $import->apply();
            self::fail('a failed restore went unnoticed');
        } catch (RuntimeException) {
        }
        self::assertNull($import->apply(), 'the next start does not try again');
        self::assertNotEmpty(glob($this->storage . '/import/failed-*'));
        self::assertNull(AgentImport::secretsKey($this->storage));
    }
}
