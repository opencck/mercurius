<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Skill;

use OpenCCK\App\Skill\SkillBundle;
use OpenCCK\App\Skill\SkillGuard;
use PHPUnit\Framework\TestCase;

final class SkillGuardTest extends TestCase {
    /** @return list<string> ids of the findings of a skill with this body */
    private static function findings(string $body): array {
        $bundle = SkillBundle::fromFiles(['SKILL.md' => "---\nname: probe\ndescription: A probe skill\n---\n\n" . $body], 'test', '');
        return array_column(SkillGuard::scan($bundle), 'id');
    }

    public function testASecretByItsNameOrShapeIsFound(): void {
        // spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — a secret written into a skill is a finding
        self::assertContains('secret_in_file', self::findings('api_key: abc123def456'));
        self::assertContains('secret_in_file', self::findings('send it with the header Authorization: Bearer abcdef123456'));
    }

    public function testOrdinaryTextOfASkillIsNotTakenForASecret(): void {
        // a trailer of a commit, a path of scripts and an id in a link are not secrets: findings nobody can act on teach to skip them
        $body = "Commit with the trailer Co-Authored-By: Claude Opus <noreply@example.org>.\n"
            . "Forms: `web.open/fill/choose/check/click/submit/upload/screenshot`.\n"
            . "The profile: https://example.org/resume/0123456789abcdef0123456789abcdef01234567\n";

        self::assertNotContains('secret_in_file', self::findings($body));
    }
}
