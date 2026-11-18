<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\PromptBuilder;
use OpenCCK\App\Agent\Tool\SelfInfoTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;

/**
 * The agent learns about itself on demand: an index of topics, then a topic with what is true now; the prompt holds
 * only the pointer.
 *
 * spec: BRIEF.md#знания-агента-о-себе
 */
final class SelfInfoToolTest extends AsyncTestCase {
    public function testAnIndexThenATopicWithWhatIsTrueNow(): void {
        $asked = [];
        $tool = new SelfInfoTool([
            'network' => static function (ToolContext $c) use (&$asked): string {
                $asked[] = $c->principal->id;
                return 'адрес https://agent.example';
            },
            'modules' => static fn(ToolContext $c): string => 'browser: работает',
        ]);
        $context = new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::Owner, 'Owner'), new NullCancellation());

        $index = $tool->execute([], $context)->content;
        self::assertStringContainsString('network', $index);
        self::assertStringContainsString('modules', $index);
        self::assertCount(0, $asked, 'the index computes nothing');

        self::assertSame('адрес https://agent.example', $tool->execute(['topic' => 'network'], $context)->content);
        self::assertSame([1], $asked);
        self::assertStringContainsString('"enum":["index","network","modules"]', json_encode($tool->definition()->parameters, JSON_THROW_ON_ERROR));
    }

    public function testThePromptHoldsOnlyThePointer(): void {
        $owner = new Principal(1, PrincipalKind::Human, Role::Owner, 'Owner');
        $prompt = PromptBuilder::system($owner, new \DateTimeImmutable('2026-11-18 10:00 UTC'), instance: \Mercurius\I18n\t('agent.prompt_builder.self_info'));

        self::assertStringContainsString('self_info', $prompt);
        self::assertStringNotContainsString('/peer add', $prompt, 'the details are not in every prompt');
    }
}
