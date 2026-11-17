<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use Mercurius\Messenger\SendErrorKind;
use Mercurius\Messenger\SendResult;
use OpenCCK\App\Agent\Tool\SendMessageTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryMessageRelayRepository;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * Personal messages to participants: the agent writes to someone's private chat on a request, and their answer comes
 * back to the conversation that asked.
 */
final class SendMessageToolTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryMessageRelayRepository $relays;
    private SendMessageTool $tool;
    private Principal $roman;

    private function stack(ScriptedLlm $llm): void {
        $this->stack = new AgentStack($llm);
        $this->relays = new InMemoryMessageRelayRepository();
        $this->stack->principals->ensureOwner('telegram', '42', 'Сергей Крупкин');
        $roman = $this->stack->principals->createVisitor('telegram', '77', 'Roman');
        $this->roman = $this->stack->principals->decideVisitor($roman->id, Role::User, PrincipalStatus::Active) ?? $roman;
        $arinin = $this->stack->principals->createVisitor('telegram', '78', 'Сергей Аринин');
        $this->stack->principals->decideVisitor($arinin->id, Role::User, PrincipalStatus::Active);
        $this->tool = new SendMessageTool($this->stack->principals, $this->stack->channel, $this->relays);
        $this->stack->registry->register($this->tool);
        $this->stack->useRelays($this->relays);
    }

    private function context(int $conversationId): ToolContext {
        $owner = $this->stack->principals->findByIdentity('telegram', '42');
        self::assertNotNull($owner);
        return new ToolContext(1, $conversationId, $owner, new NullCancellation(), approved: true);
    }

    /** @return list<string> texts sent to the chat */
    private function sentTo(string $chatId): array {
        $texts = [];
        foreach ($this->stack->channel->log as $entry) {
            if ($entry[0] === 'send' && $entry[1] === $chatId) {
                $texts[] = $entry[2];
            }
        }
        return $texts;
    }

    public function testWritingToSomeoneElseWaitsForTheOwnerAndIsGrantedPerPerson(): void {
        $this->stack(new ScriptedLlm([]));

        self::assertSame(DangerClass::Irreversible, $this->tool->danger(['to' => 'Roman', 'text' => 'Как дела?']));
        self::assertSame('message:' . $this->roman->id, $this->tool->grantKey(['to' => 'roman', 'text' => 'x']));
        self::assertStringContainsString('Roman', $this->tool->describe(['to' => 'Roman', 'text' => 'Как дела?']));
        self::assertSame(DangerClass::Write, $this->tool->danger(['to' => 'owner', 'text' => 'Итоги']), 'writing to the owner is asked by nobody');
        self::assertNull($this->tool->grantKey(['to' => 'owner', 'text' => 'x']));
    }

    public function testTheMessageGoesToThePrivateChatWithWhoAskedForIt(): void {
        $this->stack(new ScriptedLlm([]));
        $conversation = $this->stack->conversations->openConversation(1, 'telegram', '-100', '');

        $result = $this->tool->execute(['to' => 'Roman', 'text' => 'Как дела?', 'expect_reply' => true], $this->context($conversation));

        self::assertFalse($result->isError, $result->content);
        $sent = $this->sentTo('77');
        self::assertCount(1, $sent);
        self::assertStringContainsString('Как дела?', $sent[0]);
        self::assertStringContainsString('Сергей', $sent[0], 'the recipient sees on whose request the agent writes');
        self::assertCount(1, $this->relays->relays);
    }

    public function testTheAnswerOfSomeoneElseComesBackUnlessToldNot(): void {
        $this->stack(new ScriptedLlm([]));
        $conversation = $this->stack->conversations->openConversation(1, 'telegram', '-100', '');

        $this->tool->execute(['to' => 'Roman', 'text' => 'Как дела?'], $this->context($conversation));
        self::assertCount(1, $this->relays->relays, 'a question to someone waits for their answer by default');

        $this->tool->execute(['to' => 'Сергей Аринин', 'text' => 'К сведению', 'expect_reply' => false], $this->context($conversation));
        $this->tool->execute(['to' => 'owner', 'text' => 'Итоги'], $this->context($conversation));
        self::assertCount(1, $this->relays->relays, 'not when told so, and never for the owner: their chat is their own');
    }

    public function testAskingTheSamePersonAgainReplacesTheOldQuestion(): void {
        $this->stack(new ScriptedLlm([]));
        $conversation = $this->stack->conversations->openConversation(1, 'telegram', '-100', '');

        $this->tool->execute(['to' => 'Roman', 'text' => 'Как дела?'], $this->context($conversation));
        $this->tool->execute(['to' => 'Roman', 'text' => 'Как дела? (ещё раз)'], $this->context($conversation));

        self::assertCount(1, $this->relays->relays, 'one answer of Roman closes the question: a second open one would swallow his next message');
        self::assertSame('Как дела? (ещё раз)', array_values($this->relays->relays)[0]->question);
    }

    public function testANameMatchingSeveralPeopleIsAskedAgain(): void {
        $this->stack(new ScriptedLlm([]));
        $conversation = $this->stack->conversations->openConversation(1, 'telegram', '42', '');

        $result = $this->tool->execute(['to' => 'Сергей', 'text' => 'x'], $this->context($conversation));

        self::assertTrue($result->isError);
        self::assertStringContainsString('Сергей Аринин', $result->content);
        self::assertSame([], $this->sentTo('78'));
    }

    public function testSomeoneWhoNeverWroteToTheBotCannotBeReached(): void {
        $this->stack(new ScriptedLlm([]));
        $conversation = $this->stack->conversations->openConversation(1, 'telegram', '42', '');
        $this->stack->channel->sendResults[] = SendResult::failed(SendErrorKind::Forbidden, 'Forbidden: bot can\'t initiate conversation with a user');

        $result = $this->tool->execute(['to' => 'Roman', 'text' => 'x', 'expect_reply' => true], $this->context($conversation));

        self::assertTrue($result->isError);
        self::assertStringContainsString('/start', $result->content);
        self::assertSame([], $this->relays->relays, 'no answer is awaited from someone who got nothing');
    }

    public function testTheAnswerComesBackToTheConversationThatAsked(): void {
        $this->stack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'send_message', '{"to":"Roman","text":"Как дела?","expect_reply":true}')),
            ScriptedLlm::text('Спросил Романа, жду ответа.'),
            ScriptedLlm::text('Роман ответил: пока тихо.'),
        ]));
        $this->stack->approvals->addGrant(1, 'message:' . $this->roman->id);

        $this->stack->say('спроси Романа, как дела, и ответь мне');
        $this->stack->scheduler->awaitIdle();
        $this->stack->say('Пока тихо', '77');
        $this->stack->scheduler->awaitIdle();

        self::assertCount(3, $this->stack->llm->requests, 'Roman\'s answer is not a run of his own: it goes back to the owner');
        $last = $this->stack->llm->requests[2]->messages;
        $final = end($last);
        self::assertNotFalse($final);
        $prompt = $final->content;
        self::assertStringContainsString('Пока тихо', $prompt);
        self::assertStringContainsString('Roman', $prompt);
        self::assertStringContainsString('<untrusted-data', $prompt, 'the answer is someone else\'s text');
        self::assertNotEmpty($this->sentTo('77'), 'Roman got the question');
        self::assertContains('Роман ответил: пока тихо.', $this->stack->channel->texts('edit') + $this->sentTo('42'));
        self::assertNull($this->relays->takeOpen('telegram', '77', new \DateTimeImmutable('-1 day')), 'one answer per question');
    }
    public function testFilesGoToThePersonAfterTheText(): void {
        $this->stack(new ScriptedLlm([]));
        $sandbox = new \OpenCCK\Test\Support\FakeSandbox();
        $owner = $this->stack->principals->findByIdentity('telegram', '42');
        self::assertNotNull($owner);
        $sandbox->files[$owner->id]['screenshots/qr.png'] = 'QRPNG';
        $tool = new SendMessageTool($this->stack->principals, $this->stack->channel, $this->relays, 'telegram', $sandbox);
        $conversation = $this->stack->conversations->openConversation(1, 'telegram', '42', '');
        $policy = new \OpenCCK\Domain\Agent\ParticipantPolicy(null, new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120), new \OpenCCK\Domain\Agent\SandboxLimits(100, 10, 5, 1000, 1), true);
        $context = new ToolContext(1, $conversation, $owner, new NullCancellation(), $policy, approved: true);

        $result = $tool->execute(['to' => 'Roman', 'text' => 'Отсканируй QR', 'files' => ['screenshots/qr.png'], 'expect_reply' => false], $context);

        self::assertFalse($result->isError, $result->content);
        self::assertCount(1, $this->sentTo('77'));
        self::assertSame([['77', 'qr.png', 'QRPNG']], $this->stack->channel->files);
        self::assertTrue($tool->execute(['to' => 'Roman', 'text' => 'x', 'files' => ['none.png']], $context)->isError, 'a missing file stops the message before anything goes');
        self::assertCount(1, $this->sentTo('77'));
    }
}
