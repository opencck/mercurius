<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Export;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Export\ExportCommands;
use OpenCCK\Test\Support\FakeChannel;
use Psr\Log\NullLogger;
use RuntimeException;

use function Amp\delay;

/**
 * spec: brief/operations.md#экспорт-и-импорт-агента — /export: the archive comes as a file, a large one stays in exports/
 */
final class ExportCommandsTest extends AsyncTestCase {
    private string $dir = '';

    protected function setUp(): void {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/export-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @return \Closure(string, Cancellation): string */
    private function build(string $content): \Closure {
        $dir = $this->dir;
        return static function (string $password, Cancellation $c) use ($dir, $content): string {
            file_put_contents($dir . '/mercurius-1.mrcx', $content);
            return $dir . '/mercurius-1.mrcx';
        };
    }

    public function testTheArchiveComesAsAFile(): void {
        $channel = new FakeChannel();
        $commands = new ExportCommands($this->build('SEALED'), $channel, new NullLogger());

        $reply = $commands->start('42', null, 'длинный пароль 1');
        self::assertSame(\Mercurius\I18n\t('export.started'), $reply);
        delay(0.05);

        self::assertSame([['42', 'mercurius-1.mrcx', 'SEALED']], $channel->files);
    }

    public function testALargeArchiveStaysOnTheServer(): void {
        $channel = new FakeChannel();
        $commands = new ExportCommands($this->build('0123456789'), $channel, new NullLogger(), 5);

        $commands->start('42', null, 'длинный пароль 1');
        delay(0.05);

        self::assertSame([], $channel->files);
        $texts = array_column($channel->log, 2);
        self::assertStringContainsString('docker compose cp app:/app/storage/exports/mercurius-1.mrcx', implode("\n", $texts));
    }

    public function testAShortOrMissingPasswordStartsNothing(): void {
        $built = false;
        $commands = new ExportCommands(static function (string $p, Cancellation $c) use (&$built): string {
            $built = true;
            return '';
        }, new FakeChannel(), new NullLogger());

        self::assertSame(\Mercurius\I18n\t('export.usage'), $commands->start('42', null, '  '));
        self::assertStringContainsString('12', $commands->start('42', null, 'короткий'));
        delay(0.01);
        self::assertFalse($built);
    }

    public function testOneExportAtATimeAndAFailureIsSaid(): void {
        $gate = new DeferredFuture();
        $channel = new FakeChannel();
        $commands = new ExportCommands(static function (string $p, Cancellation $c) use ($gate): string {
            $gate->getFuture()->await();
            throw new RuntimeException('pg_dump: connection refused');
        }, $channel, new NullLogger());

        $commands->start('42', null, 'длинный пароль 1');
        self::assertSame(\Mercurius\I18n\t('export.busy'), $commands->start('42', null, 'длинный пароль 1'));
        $gate->complete();
        delay(0.05);

        $texts = implode("\n", array_column($channel->log, 2));
        self::assertStringContainsString(\Mercurius\I18n\t('export.failed'), $texts);
        self::assertStringNotContainsString('connection refused', $texts, 'the details go to the log');
        self::assertSame(\Mercurius\I18n\t('export.started'), $commands->start('42', null, 'длинный пароль 1'));
    }

    public function testTheCommandDeletesThePasswordAndKeepsItFromTheModel(): void {
        $stack = new \OpenCCK\Test\Support\AgentStack(new \OpenCCK\Test\Support\ScriptedLlm([]));
        $got = [];
        $stack->chat->useExport(new ExportCommands(static function (string $password, Cancellation $c) use (&$got): string {
            $got[] = $password;
            throw new RuntimeException('no database here');
        }, $stack->channel, new NullLogger()));

        $stack->say('/export длинный пароль 1');
        delay(0.05);

        self::assertSame(['длинный пароль 1'], $got);
        self::assertCount(1, $stack->channel->deleted, 'the message with the password is gone');
        self::assertStringNotContainsString('длинный пароль', implode("\n", $stack->channel->texts('send')));
        self::assertSame([], $stack->llm->requests, 'the model never saw it');
        self::assertSame([], $stack->transcript->messages, 'nor the transcript');
    }
}
