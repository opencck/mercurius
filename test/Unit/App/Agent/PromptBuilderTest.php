<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use DateTimeImmutable;
use OpenCCK\App\Agent\PromptBuilder;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use PHPUnit\Framework\TestCase;

final class PromptBuilderTest extends TestCase {
    public function testConstantPartComesFirstThenContext(): void {
        $prompt = PromptBuilder::system(new Principal(1, PrincipalKind::Human, Role::Owner, 'Сергей'), new DateTimeImmutable('2026-11-16 10:00:00+00:00'));

        self::assertStringStartsWith(SystemPrompt::text(), $prompt);
        self::assertStringContainsString('Собеседник: Сергей, владелец.', $prompt);
        self::assertStringContainsString('Диалог начат: 2026-11-16 10:00 UTC.', $prompt);
    }

    public function testParticipantWithoutNameAndOtherRole(): void {
        $prompt = PromptBuilder::system(new Principal(2, PrincipalKind::Agent, Role::User, null), new DateTimeImmutable('2026-11-16 10:00:00+00:00'));

        self::assertStringContainsString('Собеседник: другой агент без имени, пользователь.', $prompt);
    }

    public function testRulesNoLongerDenyTools(): void {
        self::assertStringNotContainsString('нет инструментов', SystemPrompt::text());
        self::assertStringContainsString('<untrusted-data', SystemPrompt::text());
    }
}
