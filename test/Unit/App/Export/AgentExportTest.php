<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Export;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Export\AgentExport;
use OpenCCK\App\Export\ArchiveCrypto;

/**
 * spec: brief/operations.md#экспорт-и-импорт-агента — the archive holds the files of bin/backup.sh, sealed by the password
 */
final class AgentExportTest extends AsyncTestCase {
    /** @var list<list<string>> */
    private static array $commands = [];

    /**
     * Stands in for pg_dump and tar.
     *
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function fake(array $command, array $env): void {
        self::$commands[] = $command;
        $at = array_search('-f', $command, true);
        $target = is_int($at) ? ($command[$at + 1] ?? '') : '';
        if ($command[0] === 'pg_dump') {
            self::assertSame('секрет базы', $env['PGPASSWORD'] ?? null, 'the password goes in the environment, not the arguments');
            file_put_contents($target, 'DUMP');
        } elseif (in_array('-czf', $command, true)) {
            file_put_contents($command[2] ?? '', 'APPDATA');
        } else {
            // the outer tar: the parts one after another stand in for it
            $dir = $command[4] ?? '';
            $parts = [];
            foreach (array_slice($command, 5) as $file) {
                $parts[] = (string) file_get_contents($dir . '/' . $file);
            }
            file_put_contents($command[2] ?? '', implode('|', $parts));
        }
    }

    /** @return list<list<string>> the commands the fake ran */
    private static function commands(): array {
        return self::$commands;
    }

    public function testTheArchiveHoldsTheDumpTheDataAndTheWorkspacesSealed(): void {
        $storage = sys_get_temp_dir() . '/export-' . bin2hex(random_bytes(4));
        mkdir($storage);
        self::$commands = [];
        $export = new AgentExport(
            self::fake(...),
            ['host' => 'postgres', 'port' => 5432, 'user' => 'mercurius', 'password' => 'секрет базы', 'name' => 'mercurius'],
            $storage,
            "SECRETS_KEY=abc\n",
            'deadbeef',
            static function (string $to, Cancellation $c): void {
                file_put_contents($to, 'WORKSPACES');
            },
        );

        $path = $export->export('пароль владельца 1', new NullCancellation());

        self::assertStringStartsWith($storage . '/exports/mercurius-', $path);
        self::assertStringEndsWith(AgentExport::EXTENSION, $path);
        ArchiveCrypto::decrypt($path, $storage . '/opened', 'пароль владельца 1');
        self::assertSame("DUMP|APPDATA|WORKSPACES|SECRETS_KEY=abc\n|deadbeef\n", file_get_contents($storage . '/opened'));
        foreach (self::commands() as $command) {
            self::assertNotContains('секрет базы', $command, 'no password in a command line');
        }
        $left = scandir($storage . '/exports');
        self::assertSame([basename($path)], array_values(array_diff($left === false ? [] : $left, ['.', '..'])), 'the build folder is gone');
    }

    public function testTheSettingsAreTheVariablesOfTheExample(): void {
        $env = AgentExport::env(['TELEGRAM_BOT_TOKEN' => 't', 'PATH' => '/usr/bin', 'QUIET_HOURS' => '23-8'], "# comment\nTELEGRAM_BOT_TOKEN=\n#QUIET_HOURS=23-8\nOTHER=\n");

        self::assertSame("TELEGRAM_BOT_TOKEN=t\nQUIET_HOURS=23-8\n", $env);
    }
}
