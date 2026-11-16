<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use DateTimeImmutable;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Role as LlmRole;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeLlm;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use PHPUnit\Framework\TestCase;

final class ChatServiceTest extends TestCase {
    private InMemoryConversationRepository $conversations;
    private InMemoryLlmCallRepository $calls;
    private FakeChannel $channel;
    private float $now = 0.0;

    protected function setUp(): void {
        $this->conversations = new InMemoryConversationRepository();
        $this->calls = new InMemoryLlmCallRepository();
        $this->channel = new FakeChannel();
    }

    private function service(FakeLlm $llm, ?FakeChannel $channel = null): ChatService {
        return new ChatService(
            new AccessService(new InMemoryPrincipalRepository(), 'telegram', '42'),
            $this->conversations,
            $this->calls,
            $llm,
            $channel ?? $this->channel,
            'test/model',
            'low',
            1000,
            10,
            clock: function (): float {
                $this->now += 1.0;
                return $this->now;
            },
        );
    }

    private function message(string $text, string $userId = '42'): IncomingMessage {
        return new IncomingMessage('telegram', '42', $userId, 'owner', 'Owner', $text, '7', null, true, new DateTimeImmutable(), '1');
    }

    public function testStrangerIsRefusedWithoutCallingTheModel(): void {
        $llm = new FakeLlm();
        $this->service($llm)->handle($this->message('привет', '99'));

        self::assertSame([ChatService::REFUSAL], $this->channel->texts('send'));
        self::assertSame([], $llm->requests);
    }

    public function testAnswerIsStreamedIntoOneMessageAndRecorded(): void {
        $llm = new FakeLlm(['Привет', ', мир', '!']);
        $this->service($llm)->handle($this->message('скажи привет'));

        self::assertSame(['…'], $this->channel->texts('send'));
        $edits = $this->channel->texts('edit');
        self::assertSame('Привет, мир!', end($edits));
        self::assertCount(1, $this->calls->calls);
        self::assertSame('stop', $this->calls->calls[0]->finishReason);
        self::assertSame(10, $this->calls->calls[0]->inputTokens);
        self::assertSame(['скажи привет', 'Привет, мир!'], array_map(static fn($t) => $t->content, $this->conversations->messages[1]));
    }

    public function testHistoryIsSentWithSystemPrompt(): void {
        $llm = new FakeLlm(['ответ']);
        $service = $this->service($llm);
        $service->handle($this->message('первый'));
        $service->handle($this->message('второй'));

        $messages = $llm->requests[1]->messages;
        self::assertSame(LlmRole::System, $messages[0]->role);
        self::assertSame(['первый', 'ответ', 'второй'], array_map(static fn($m) => $m->content, array_slice($messages, 1)));
        self::assertSame('low', $llm->requests[1]->reasoningEffort);
    }

    public function testNewCommandStartsAFreshConversation(): void {
        $llm = new FakeLlm(['ответ']);
        $service = $this->service($llm);
        $service->handle($this->message('первый'));
        $service->handle($this->message('/new'));
        $service->handle($this->message('второй'));

        self::assertCount(2, $llm->requests[1]->messages);
        self::assertContains(ChatService::NEW_CONVERSATION, $this->channel->texts('send'));
    }

    public function testModelFailureIsReportedAndRecorded(): void {
        $llm = new FakeLlm(error: new LlmException(ErrorKind::RateLimited, 'slow down', 429));
        $this->service($llm)->handle($this->message('вопрос'));

        $edits = $this->channel->texts('edit');
        self::assertSame(ChatService::FAILURE, end($edits));
        self::assertSame('rate_limited', $this->calls->calls[0]->errorKind);
        self::assertCount(1, $this->conversations->messages[1]);
    }

    public function testLongAnswerIsSplitIntoContinuations(): void {
        $channel = new FakeChannel(maxLength: 20);
        $llm = new FakeLlm([str_repeat('слово ', 10)]);
        $this->service($llm, $channel)->handle($this->message('много'));

        self::assertGreaterThan(1, count($channel->texts('send')));
    }

    public function testNonTextMessageGetsAHint(): void {
        $this->service(new FakeLlm())->handle($this->message('   '));

        self::assertSame([ChatService::NOT_TEXT], $this->channel->texts('send'));
    }
}
