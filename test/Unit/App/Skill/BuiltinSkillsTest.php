<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Skill;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Skill\BuiltinSkills;
use OpenCCK\App\Skill\SkillCatalog;
use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Test\Support\InMemorySkillRepository;

/**
 * Built-in skills ship with the code (submodules in skills/): at the start each is put into the catalog as an active
 * version, a changed one as a new version, an unchanged one is left alone; the README and the licence of the
 * repository are not part of the skill.
 *
 * spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — every built-in skill is its own repository, a submodule in skills/
 */
final class BuiltinSkillsTest extends AsyncTestCase {
    private string $dir;

    protected function setUp(): void {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/builtin-skills-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/skill-creator/references', 0777, true);
        file_put_contents($this->dir . '/skill-creator/SKILL.md', "---\nname: skill-creator\ndescription: Создание скиллов агента\n---\n\n# Как создать скилл\nШаги.");
        file_put_contents($this->dir . '/skill-creator/references/checklist.md', "- описание\n");
        file_put_contents($this->dir . '/skill-creator/README.md', 'репозиторий');
        file_put_contents($this->dir . '/skill-creator/LICENSE', 'MIT');
        mkdir($this->dir . '/empty');
    }

    protected function tearDown(): void {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testABuiltinSkillIsActiveAndUpdatedOnlyWhenItChanged(): void {
        $repo = new InMemorySkillRepository();
        $builtin = new BuiltinSkills(new SkillCatalog($repo), $repo);

        self::assertSame(['skill-creator'], $builtin->sync($this->dir));
        $skill = $repo->find('skill-creator');
        self::assertNotNull($skill);
        self::assertSame(Skill::ACTIVE, $skill->status);
        self::assertStringContainsString('Шаги.', $skill->body);
        self::assertSame(['references/checklist.md'], array_keys($repo->files('skill-creator')), 'no README or LICENSE');

        self::assertSame([], $builtin->sync($this->dir), 'unchanged: nothing new');
        self::assertSame(1, $repo->find('skill-creator')?->version);

        file_put_contents($this->dir . '/skill-creator/SKILL.md', "---\nname: skill-creator\ndescription: Создание скиллов агента\n---\n\n# Как создать скилл\nНовые шаги.");
        self::assertSame(['skill-creator'], $builtin->sync($this->dir));
        self::assertSame(['skill-creator' => 2], array_map(static fn($s): int => $s->version, $repo->skills));
    }

    public function testAMissingDirectoryIsNothing(): void {
        $repo = new InMemorySkillRepository();
        self::assertSame([], (new BuiltinSkills(new SkillCatalog($repo), $repo))->sync($this->dir . '/nope'));
    }
}
