<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Export;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Export\AgentImport;
use OpenCCK\App\Export\PartialImport;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryMemoryStore;
use RuntimeException;

/**
 * spec: BRIEF.md#экспорт-и-импорт-агента — a partial import: the owner's memory or skills, nothing else replaced
 */
final class PartialImportTest extends AsyncTestCase {
    /** the pg_restore scripts of the archive's tables */
    private const TABLES = [
        'facts' => "SET x = 1;\n\nCOPY public.facts (id, scope, category, text, keys, trust, provenance, status) FROM stdin;\n"
            . "1\towner\tknowledge\tВладелец пьёт чай без сахара\t{чай}\t0.9\towner_said\tactive\n"
            . "2\tprincipal:5\tknowledge\tЧужое\t{}\t0.5\tagent\tactive\n"
            . "3\towner\trule\tОтвечать коротко\\nи по делу\t{}\t0.7\towner_confirmed\tactive\n"
            . "4\towner\tknowledge\tСтарое\t{}\t0.5\tagent\tended\n"
            . "5\towner\tknowledge\tУже известно\t{}\t0.5\tagent\tactive\n"
            . "\\.\n",
        'principals' => "COPY public.principals (id, kind, role, created_at) FROM stdin;\n7\thuman\towner\t2026-11-01\n8\thuman\tuser\t\\N\n\\.\n",
    ];

    private string $staging = '';
    private static bool $noSkills = false;

    protected function setUp(): void {
        parent::setUp();
        self::$noSkills = false;
        $this->staging = sys_get_temp_dir() . '/partial-' . bin2hex(random_bytes(4));
        mkdir($this->staging);
        file_put_contents($this->staging . '/db.dump', 'DUMP');
        file_put_contents($this->staging . '/workspace.tar.gz', 'WS');
    }

    protected function tearDown(): void {
        AgentImport::remove($this->staging);
        parent::tearDown();
    }

    /**
     * pg_restore writes the script of its table; tar unpacks two skills of the archive owner (p7).
     *
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function fake(array $command, array $env): void {
        if ($command[0] === 'pg_restore') {
            $table = substr($command[2] ?? '', strlen('--table='));
            file_put_contents($command[4] ?? '', self::TABLES[$table] ?? '');
        } elseif ($command[0] === 'tar') {
            if (self::$noSkills) {
                throw new RuntimeException('tar exited with 2: Not found in archive');
            }
            $root = ($command[4] ?? '') . '/p7/.mercurius/skills';
            mkdir($root . '/deploy/scripts', 0777, true);
            file_put_contents($root . '/deploy/SKILL.md', '# deploy');
            file_put_contents($root . '/deploy/scripts/run.sh', 'echo hi');
            mkdir($root . '/notes', 0777, true);
            file_put_contents($root . '/notes/SKILL.md', '# notes from the archive');
        }
    }

    private static function file(FakeSandbox $sandbox, string $path): ?string {
        return $sandbox->files[3][$path] ?? null;
    }

    public function testTheOwnersFactsComeInOnceAndOthersStayOut(): void {
        $memory = new InMemoryMemoryStore();
        $memory->addFact('owner', 'knowledge', 'Уже известно', [], 0.5, 'agent', null);
        $import = new PartialImport(self::fake(...), $memory, new TextAnalyzer());

        self::assertSame(2, $import->memory($this->staging));
        self::assertSame(0, $import->memory($this->staging), 'a second import adds nothing');

        $texts = array_map(static fn($f): string => $f->text, $memory->active(100));
        self::assertContains('Владелец пьёт чай без сахара', $texts);
        self::assertContains("Отвечать коротко\nи по делу", $texts, 'COPY escapes are read');
        self::assertNotContains('Чужое', $texts, 'another participant\'s memory stays out');
        self::assertNotContains('Старое', $texts);
        $rule = array_values(array_filter($memory->active(100), static fn($f): bool => str_starts_with($f->text, 'Отвечать')))[0];
        self::assertSame('rule', $rule->category);
        self::assertEqualsWithDelta(0.7, $rule->trust, 0.001);
    }

    public function testTheOwnersSkillsGoToTheNewOwnerAndAKnownOneIsKept(): void {
        $sandbox = new FakeSandbox();
        $sandbox->files[3]['.mercurius/skills/notes/SKILL.md'] = '# my notes';
        $import = new PartialImport(self::fake(...), new InMemoryMemoryStore(), new TextAnalyzer(), $sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(100, 10, 5, 1000, 1));

        self::assertSame(['deploy'], $import->skills($this->staging, 3, new NullCancellation()));

        self::assertSame('# deploy', self::file($sandbox, '.mercurius/skills/deploy/SKILL.md'));
        self::assertSame('echo hi', self::file($sandbox, '.mercurius/skills/deploy/scripts/run.sh'));
        self::assertSame('# my notes', self::file($sandbox, '.mercurius/skills/notes/SKILL.md'), 'a skill of the same name here is kept');
        self::assertDirectoryDoesNotExist($this->staging . '/skills');
    }

    public function testAnArchiveWithoutSkillsGivesNone(): void {
        self::$noSkills = true;
        $import = new PartialImport(self::fake(...), new InMemoryMemoryStore(), new TextAnalyzer(), new FakeSandbox(), static fn(int $id): SandboxLimits => new SandboxLimits(100, 10, 5, 1000, 1));

        self::assertSame([], $import->skills($this->staging, 3, new NullCancellation()));
    }

    public function testCopyRowsReadNullsAndEscapes(): void {
        $rows = PartialImport::copyRows("COPY public.t (a, b) FROM stdin;\nx\\ty\t\\N\n\\.\n");

        self::assertSame([['a' => "x\ty", 'b' => null]], $rows);
    }
}
