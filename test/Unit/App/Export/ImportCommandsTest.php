<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Export;

use Amp\Cancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\AttachmentKind;
use OpenCCK\App\Export\AgentExport;
use OpenCCK\App\Export\AgentImport;
use OpenCCK\App\Export\ArchiveCrypto;
use OpenCCK\App\Export\ImportCommands;
use OpenCCK\App\Export\PartialImport;
use OpenCCK\Test\Support\FakeChannel;
use Psr\Log\NullLogger;

use function Amp\delay;
use function Mercurius\I18n\t;

/**
 * spec: BRIEF.md#экспорт-и-импорт-агента — /import: checked, asked once more, the current state saved, then a restart
 */
final class ImportCommandsTest extends AsyncTestCase {
    private const PASSWORD = 'пароль владельца 1';

    private string $storage = '';
    private FakeChannel $channel;
    /** @var list<string> */
    private array $exported = [];
    private int $restarts = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/import-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->storage . '/import', 0700, true);
        $this->channel = new FakeChannel();
        $this->exported = [];
        $this->restarts = 0;
    }

    protected function tearDown(): void {
        AgentImport::remove($this->storage);
        parent::tearDown();
    }

    /**
     * Unpacks the stand-in archive "name=content|…".
     *
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function fake(array $command, array $env): void {
        if ($command[0] === 'pg_restore') {
            file_put_contents($command[4] ?? '', "COPY public.facts (id, scope, category, text, trust, provenance, status) FROM stdin;\n1\towner\tknowledge\tИз архива\t0.8\towner_said\tactive\n\\.\n");
            return;
        }
        if ($command[0] === 'tar' && $command[1] === '-xf') {
            foreach (explode('|', (string) file_get_contents($command[2])) as $part) {
                [$name, $content] = explode('=', $part, 2) + [1 => ''];
                file_put_contents(($command[4] ?? '') . '/' . $name, $content);
            }
        }
    }

    private function sealed(): string {
        $plain = $this->storage . '/plain';
        file_put_contents($plain, 'db.dump=DUMP|app.tar.gz=A|workspace.tar.gz=|env=|commit=0123456789abcdef');
        $bytes = $this->storage . '/sealed';
        ArchiveCrypto::encrypt($plain, $bytes, self::PASSWORD);
        return (string) file_get_contents($bytes);
    }

    private function commands(?PartialImport $partial = null): ImportCommands {
        $storage = $this->storage;
        return new ImportCommands(
            new AgentImport(self::fake(...), ['host' => 'h', 'port' => 5432, 'user' => 'u', 'password' => 'p', 'name' => 'n'], $storage),
            function (string $password, Cancellation $c) use ($storage): string {
                $this->exported[] = $password;
                return $storage . '/exports/mercurius-now' . AgentExport::EXTENSION;
            },
            function (): void {
                $this->restarts++;
            },
            $this->channel,
            new NullLogger(),
            $storage . '/import',
            $partial,
        );
    }

    private function restarts(): int {
        return $this->restarts;
    }

    private function texts(): string {
        return implode("\n", $this->channel->texts('send'));
    }

    public function testAnAttachedArchiveIsCheckedThenAppliedOnYes(): void {
        $this->channel->downloads['f1'] = $this->sealed();
        $commands = $this->commands();
        $file = new Attachment(AttachmentKind::Document, 'f1', 'agent.mrcx', null, 100);

        self::assertSame(t('import.checking'), $commands->start('42', self::PASSWORD, $file));
        delay(0.6);
        self::assertStringContainsString('0123456789ab', $this->texts(), 'the commit of the archive is said');
        self::assertSame(0, $this->restarts, 'nothing is replaced before the yes');

        self::assertSame(t('import.saving'), $commands->start('42', 'yes', null));
        delay(0.6);

        self::assertSame([self::PASSWORD], $this->exported, 'the current state is sealed by the same password first');
        self::assertSame(1, $this->restarts(), 'restarted once');
        self::assertDirectoryExists($this->storage . '/import/pending');
        self::assertStringContainsString('storage/app/exports/mercurius-now.mrcx', $this->texts());
    }

    public function testAWrongPasswordIsSaidAndYesFindsNothing(): void {
        $this->channel->downloads['f1'] = $this->sealed();
        $commands = $this->commands();

        $commands->start('42', 'другой пароль 1234', new Attachment(AttachmentKind::Document, 'f1', 'agent.mrcx', null, 100));
        delay(0.6);

        self::assertStringContainsString(t('export.wrong_password'), $this->texts());
        self::assertSame(t('import.nothing'), $commands->start('42', 'yes', null));
        self::assertSame(0, $this->restarts);
    }

    public function testAnArchiveByNameStaysInTheImportFolder(): void {
        file_put_contents($this->storage . '/import/agent.mrcx', $this->sealed());
        $commands = $this->commands();

        self::assertSame(t('import.no_file'), $commands->start('42', self::PASSWORD . ' ../secret.mrcx', null));
        self::assertSame(t('import.usage'), $commands->start('42', 'одно-слово-пароль', null), 'one word is the password, and no file is named');
        self::assertSame(t('import.checking'), $commands->start('42', self::PASSWORD . ' agent.mrcx', null));
        delay(0.6);
        self::assertStringContainsString('0123456789ab', $this->texts());

        self::assertSame(t('import.cancelled'), $commands->start('42', 'no', null));
        self::assertDirectoryDoesNotExist($this->storage . '/import/staging');
        self::assertSame(t('import.nothing'), $commands->start('42', 'yes', null));
    }

    public function testATooLargeFileIsNotDownloaded(): void {
        $commands = $this->commands();

        $answer = $commands->start('42', self::PASSWORD, new Attachment(AttachmentKind::Document, 'f1', 'agent.mrcx', null, ImportCommands::DOWNLOAD_LIMIT + 1));

        self::assertSame(t('import.too_large'), $answer);
    }

    public function testOnlyTheMemoryOfTheArchiveIsTakenAndTheArchiveStillWaits(): void {
        file_put_contents($this->storage . '/import/agent.mrcx', $this->sealed());
        $memory = new \Mercurius\Memory\Infrastructure\InMemoryMemoryStore();
        $commands = $this->commands(new PartialImport(self::fake(...), $memory, new \Mercurius\Memory\Domain\TextAnalyzer()));

        self::assertSame(t('import.nothing'), $commands->start('42', 'memory', null, 1), 'nothing before a checked archive');
        $commands->start('42', self::PASSWORD . ' agent.mrcx', null, 1);
        delay(0.6);
        self::assertSame(t('import.part_started'), $commands->start('42', 'memory', null, 1));
        delay(0.1);

        self::assertSame(['Из архива'], array_map(static fn($f): string => $f->text, $memory->active(10)));
        self::assertStringContainsString(sprintf(t('import.memory_done'), 1), $this->texts());
        self::assertSame(0, $this->restarts(), 'nothing replaced, no restart');
        self::assertDirectoryExists($this->storage . '/import/staging', 'the archive waits for the other part or the yes');
    }
}
