<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\VisitorService;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * A stranger writes: they wait, the owner gets one card and decides; /permissions lists saved grants.
 */
final class VisitorServiceTest extends AsyncTestCase {
    private AgentStack $stack;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Здравствуйте!')]));
        $this->stack->registry->register(new FakeTool('read'));
        // The owner exists before any stranger writes.
        $this->stack->principals->ensureOwner('telegram', '42', 'Owner');
    }

    /** @return list<string> */
    private function cards(): array {
        return array_values(array_filter($this->stack->channel->texts('send'), static fn(string $t): bool => str_starts_with($t, 'Новый собеседник')));
    }

    /** @return list<string> data of the buttons on the last message that has any */
    private function lastButtons(): array {
        $data = [];
        foreach ($this->stack->channel->buttons as $rows) {
            if ($rows === []) {
                continue;
            }
            $data = [];
            foreach ($rows as $row) {
                foreach ($row as $button) {
                    $data[] = $button->data;
                }
            }
        }
        self::assertNotSame([], $data);
        return $data;
    }

    public function testStrangerWaitsAndTheOwnerGetsACard(): void {
        $this->stack->say('привет, я Вася', '99');
        $this->stack->scheduler->awaitIdle();

        self::assertCount(1, $this->cards());
        self::assertStringContainsString('привет, я Вася', $this->cards()[0]);
        self::assertTrue($this->stack->channel->previewOff('привет, я Вася'), 'a link of a stranger is not opened by Telegram in the card');
        self::assertSame(['pv:2:t', 'pv:2:u', 'pv:2:a', 'pv:2:r'], $this->lastButtons());
        self::assertContains(\Mercurius\I18n\t(VisitorService::WAITING), $this->stack->channel->texts('send'));
        self::assertSame([], $this->stack->llm->requests);
        self::assertSame(PrincipalStatus::Pending, $this->stack->principals->find(2)?->status);
    }

    public function testACardTheMessengerDidNotTakeIsSentAgain(): void {
        $this->stack->channel->sendResults = [\Mercurius\Messenger\SendResult::failed(\Mercurius\Messenger\SendErrorKind::Transient, 'Allowed transfer timeout exceeded')];

        $this->stack->say('привет, я Роман', '99');
        $this->stack->scheduler->awaitIdle();

        self::assertCount(1, $this->cards(), 'a timeout of Telegram does not lose the card');
    }

    public function testSecondMessageBeforeTheDecisionSendsNoSecondCard(): void {
        $this->stack->say('привет', '99');
        $this->stack->say('ау?', '99');
        $this->stack->scheduler->awaitIdle();

        self::assertCount(1, $this->cards());
        self::assertSame([], $this->stack->llm->requests);
    }

    public function testTalkLetsTheVisitorChatWithoutTools(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:t');
        $this->stack->say('расскажи о себе', '99');
        $this->stack->scheduler->awaitIdle();

        $visitor = $this->stack->principals->find(2);
        self::assertNotNull($visitor);
        self::assertSame(Role::Visitor, $visitor->role);
        self::assertSame(PrincipalStatus::Active, $visitor->status);
        self::assertCount(1, $this->stack->llm->requests);
        self::assertSame([], $this->stack->llm->requests[0]->tools);
        self::assertSame('Разговор разрешён.', $this->stack->channel->answers[0][1]);
        self::assertStringContainsString('Разговор разрешён', implode("\n", $this->stack->channel->texts('edit')));
    }

    public function testUserAndAdminPressesGiveThatRole(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:a');

        self::assertSame(Role::Admin, $this->stack->principals->find(2)?->role);
    }

    public function testRejectedVisitorIsRefusedWithoutTheModel(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:r');
        $this->stack->say('ну пожалуйста', '99');
        $this->stack->scheduler->awaitIdle();

        self::assertSame(PrincipalStatus::Rejected, $this->stack->principals->find(2)?->status);
        self::assertContains(\Mercurius\I18n\t(ChatService::REFUSAL), $this->stack->channel->texts('send'));
        self::assertSame([], $this->stack->llm->requests);
    }

    public function testSecondDecisionIsAlreadyDecided(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:r');
        $this->stack->press('pv:2:a');

        self::assertSame('Уже решено.', $this->stack->channel->answers[1][1]);
        self::assertSame(PrincipalStatus::Rejected, $this->stack->principals->find(2)?->status);
    }

    public function testPermissionsListsGrantsAndRevokes(): void {
        $this->stack->approvals->addGrant(1, 'git-push:origin');

        $this->stack->say('/permissions');
        self::assertStringContainsString('git-push:origin', implode("\n", $this->stack->channel->texts('send')));
        self::assertSame(['pg:1:x'], $this->lastButtons());

        $this->stack->press('pg:1:x');
        self::assertFalse($this->stack->approvals->hasGrant(1, 'git-push:origin'));
        self::assertSame('Отозвано.', $this->stack->channel->answers[0][1]);
        self::assertSame([], $this->stack->llm->requests);
    }

    public function testPermissionsWithNoGrantsSaysSo(): void {
        $this->stack->say('/permissions');

        self::assertSame([\Mercurius\I18n\t(ChatService::NO_GRANTS)], $this->stack->channel->texts('send'));
    }

    public function testPermissionsIsForTheOwnerOnly(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:u');
        $this->stack->say('/permissions', '99');

        self::assertContains(\Mercurius\I18n\t(ChatService::OWNER_ONLY), $this->stack->channel->texts('send'));
        self::assertSame([], $this->stack->llm->requests);
    }

    /** @return list<string> texts sent to one chat */
    private function sentTo(string $chatId): array {
        $texts = [];
        foreach ($this->stack->channel->log as [$action, $chat, $text]) {
            if ($action === 'send' && $chat === $chatId) {
                $texts[] = $text;
            }
        }
        return $texts;
    }

    public function testVisitorIsToldWhenLetIn(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:u');

        self::assertContains(\Mercurius\I18n\t(VisitorService::LET_IN_USER), $this->sentTo('99'));
    }

    public function testVisitorIsToldWhenAllowedToTalk(): void {
        $this->stack->say('привет', '99');
        $this->stack->press('pv:2:t');

        self::assertContains(\Mercurius\I18n\t(VisitorService::LET_IN_TALK), $this->sentTo('99'));
    }

    public function testRefusedVisitorIsNotToldAnything(): void {
        $this->stack->say('привет', '99');
        $before = count($this->stack->channel->log);
        $this->stack->press('pv:2:r');

        self::assertSame([], array_filter(array_slice($this->stack->channel->log, $before), static fn(array $e): bool => $e[0] === 'send'));
    }
}
