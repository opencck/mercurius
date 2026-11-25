<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Context\ContextLimits;
use OpenCCK\App\Agent\Tool\Memory\MyPreferencesTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Job\ParticipantClock;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\ContextLimitRepository;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryContextLimitRepository;
use OpenCCK\Test\Support\InMemoryParticipantPrefRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;

use function Mercurius\I18n\t;

/**
 * The limits of a conversation in the context: the project's over the participant's over the agent's, each value on
 * its own; the owner of a project and each participant for their private chats set them through the agent.
 *
 * spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия
 */
final class ContextLimitsTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryContextLimitRepository $limits;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack();
        $this->limits = new InMemoryContextLimitRepository();
    }

    private function as(Member $who, string $chat): ToolContext {
        return new ToolContext($who->id, $this->stack->conversations->openConversation($who->id, 'telegram', $chat), $who, new NullCancellation());
    }

    public function testAProjectOverAParticipantOverTheAgentEachValueOnItsOwn(): void {
        $owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $private = $this->stack->conversations->openConversation($owner->id, 'telegram', '42');
        $this->stack->conversations->setPrivateChat($private, true);
        $group = $this->stack->conversations->openConversation($owner->id, 'telegram', '-100');
        $topic = $this->stack->conversations->openConversation($owner->id, 'telegram', '-200');
        $resolver = new ContextLimits(
            $this->limits,
            $this->stack->conversations,
            static fn(ConversationMeta $meta): ?int => $meta->chatId === '-200' ? 7 : null,
            static fn(): array => [100_000, null],
        );

        self::assertSame([100_000, null], $resolver->of($private), 'the agent\'s');
        $this->limits->save(ContextLimitRepository::PRINCIPAL, $owner->id, null, 60);
        self::assertSame([100_000, 60], $resolver->of($private), 'the participant\'s percent, the agent\'s tokens');
        self::assertSame([100_000, null], $resolver->of($group), 'a group without a project is not anyone\'s private chat');
        $this->limits->save(ContextLimitRepository::PROJECT, 7, 50_000, null);
        self::assertSame([50_000, null], $resolver->of($topic), 'the project\'s, not the participant\'s');
    }

    public function testTheOwnerOfAProjectSetsItsLimitsAndAMemberOnlySeesThem(): void {
        $owner = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $member = $this->stack->principals->createAgent('helper', Role::user());
        $projects = new InMemoryProjectRepository();
        $project = $projects->create('Новости', '');
        $projects->share($project->id, $member->id, 'write', null);
        $edit = new ProjectEditTool($projects, $this->stack->conversations);
        $edit->useLimits($this->limits);

        self::assertSame(sprintf(t('tool.project_edit.context_now'), 'Новости', ContextLimits::describe(null, null)), $edit->execute(['action' => 'context', 'project' => 'Новости'], $this->as($owner, 'a'))->content);
        self::assertFalse($edit->execute(['action' => 'context', 'project' => 'Новости', 'tokens' => 60_000, 'percent' => 70], $this->as($owner, 'a'))->isError);
        self::assertSame([60_000, 70], $this->limits->get(ContextLimitRepository::PROJECT, $project->id));
        self::assertTrue($edit->execute(['action' => 'context', 'project' => 'Новости', 'percent' => 50], $this->as($member, 'b'))->isError, 'a writer does not set them');
        self::assertTrue($edit->execute(['action' => 'context', 'project' => 'Новости', 'tokens' => 100], $this->as($owner, 'a'))->isError, 'too few tokens');
        self::assertTrue($edit->execute(['action' => 'context', 'project' => 'Новости', 'percent' => 99], $this->as($owner, 'a'))->isError);
        $edit->execute(['action' => 'context', 'project' => 'Новости', 'tokens' => 0], $this->as($owner, 'a'));
        self::assertSame([null, 70], $this->limits->get(ContextLimitRepository::PROJECT, $project->id), '0 — back to the agent\'s');
    }

    public function testAParticipantSetsTheLimitsOfTheirOwnPrivateChats(): void {
        $who = $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
        $prefs = new InMemoryParticipantPrefRepository();
        $tool = new MyPreferencesTool($prefs, new ParticipantClock($prefs, new \DateTimeZone('UTC'), null), $this->limits);

        $set = $tool->execute(['compact_percent' => 60], $this->as($who, 'p'));
        self::assertFalse($set->isError, $set->content);
        self::assertStringContainsString(ContextLimits::describe(null, 60), $set->content);
        self::assertSame([null, 60], $this->limits->get(ContextLimitRepository::PRINCIPAL, $who->id));
        self::assertTrue($tool->execute(['context_tokens' => 10], $this->as($who, 'p'))->isError);
    }
}
