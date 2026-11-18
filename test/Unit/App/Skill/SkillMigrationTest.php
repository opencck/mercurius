<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Skill;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Skill\SkillLibrary;
use OpenCCK\App\Skill\SkillMigration;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\OldSkillCatalog;
use OpenCCK\Test\Support\FakeSandbox;

/**
 * The skills of the old catalog in the base go into the owner's folder once and the base forgets them; a built-in one
 * is not copied, one already in the folder is not overwritten.
 */
final class SkillMigrationTest extends AsyncTestCase {
    public function testOldSkillsMoveIntoTheOwnersFolderAndTheBaseForgetsThem(): void {
        $old = new class implements OldSkillCatalog {
            public bool $cleared = false;

            public function active(): array {
                return [
                    ['name' => 'internal-comms', 'description' => 'Письма', 'body' => 'Как писать.', 'origin' => 'external', 'files' => ['examples/a.md' => 'пример']],
                    ['name' => 'skill-creator', 'description' => 'Старый встроенный', 'body' => 'старое', 'origin' => 'builtin', 'files' => []],
                    ['name' => 'notes', 'description' => 'Из базы', 'body' => 'старый текст', 'origin' => 'agent', 'files' => []],
                ];
            }

            public function clear(): void {
                $this->cleared = true;
            }
        };
        $sandbox = new FakeSandbox();
        $sandbox->files[1]['skills/notes/SKILL.md'] = "---\nname: notes\ndescription: Уже в папке\n---\n\nновый текст";
        $library = new SkillLibrary([], $sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), static fn(): int => 1);

        $moved = (new SkillMigration($old, $library))->toFolder(new Principal(1, PrincipalKind::Human, Role::Owner, 'Owner'));

        self::assertSame(['internal-comms'], $moved);
        $files = $sandbox->files[1];
        self::assertContains('пример', $files);
        self::assertContains('skills/internal-comms/examples/a.md', array_keys($files));
        self::assertNotContains('skills/skill-creator/SKILL.md', array_keys($files), 'a built-in skill is served from the code');
        self::assertContains("---\nname: notes\ndescription: Уже в папке\n---\n\nновый текст", $files, 'the folder wins');
        self::assertTrue($old->cleared, 'the base keeps no skills');
    }
}
