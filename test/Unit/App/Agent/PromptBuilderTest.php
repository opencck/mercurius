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
        $prompt = PromptBuilder::system(new Principal(1, PrincipalKind::Human, Role::owner(), 'Сергей'), new DateTimeImmutable('2026-11-16 10:00:00+00:00'));

        self::assertStringStartsWith(SystemPrompt::text(), $prompt);
        self::assertStringContainsString('Собеседник: Сергей, владелец.', $prompt);
        self::assertStringContainsString('Диалог начат: 2026-11-16 10:00 UTC.', $prompt);
    }

    public function testParticipantWithoutNameAndOtherRole(): void {
        $prompt = PromptBuilder::system(new Principal(2, PrincipalKind::Agent, Role::user(), null), new DateTimeImmutable('2026-11-16 10:00:00+00:00'));

        self::assertStringContainsString('Собеседник: другой агент без имени, пользователь.', $prompt);
    }

    public function testRulesNoLongerDenyTools(): void {
        self::assertStringNotContainsString('нет инструментов', SystemPrompt::text());
        self::assertStringContainsString('<untrusted-data', SystemPrompt::text());
        self::assertStringContainsString('Если инструмент не вызывался, действия не было.', SystemPrompt::text(), 'no reported step without a tool');
    }

    public function testAConversationOfTheWebsocketApiIsNamedSo(): void {
        $owner = new \OpenCCK\Domain\Entity\Principal(1, \OpenCCK\Domain\Entity\PrincipalKind::Human, \OpenCCK\Domain\Entity\Role::owner(), 'Owner');
        $at = new \DateTimeImmutable('2026-11-18 10:00 UTC');

        self::assertStringContainsString('WebSocket API', \OpenCCK\App\Agent\PromptBuilder::system($owner, $at, channel: 'ws'));
        self::assertStringNotContainsString('WebSocket API', \OpenCCK\App\Agent\PromptBuilder::system($owner, $at, channel: 'telegram'));
    }

    public function testTheAgentKnowsHowANeighbourConnectsToIt(): void {
        $owner = new \OpenCCK\Domain\Entity\Principal(1, \OpenCCK\Domain\Entity\PrincipalKind::Human, \OpenCCK\Domain\Entity\Role::owner(), 'Owner');
        $about = sprintf(\Mercurius\I18n\t('agent.prompt_builder.instance_a2a'), 'https://agent.example', 'wss://agent.example/a2a/ws');

        $prompt = \OpenCCK\App\Agent\PromptBuilder::system($owner, new \DateTimeImmutable('2026-11-18 10:00 UTC'), instance: $about);

        self::assertStringContainsString('https://agent.example/.well-known/agent-card.json', $prompt);
        self::assertStringContainsString('/peer add', $prompt);
        self::assertStringContainsString('A2A_TOKEN', $prompt, 'where the token is, not the token');
    }
}
