<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\ContextMemory;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\MemoryRulesNotice;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryMemoryRuleRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use Psr\Log\NullLogger;

/**
 * The notice of a change of the rules of a MEMORY.md: off — silent, nothing kept; on — the first look is the starting
 * point, a change is told with «Убрать», which puts the section back unless it changed again.
 *
 * spec: brief/memory.md#сообщение-об-изменении-правил
 */
final class MemoryRulesNoticeTest extends AsyncTestCase {
    private FakeSandbox $sandbox;
    private FakeChannel $channel;
    private InMemoryMemoryRuleRepository $rules;
    private InMemoryConversationRepository $conversations;
    private InMemoryPrincipalRepository $principals;
    private Principal $owner;
    private bool $on = true;
    private MemoryRulesNotice $notice;

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandbox();
        $this->channel = new FakeChannel();
        $this->rules = new InMemoryMemoryRuleRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->principals = new InMemoryPrincipalRepository();
        $this->owner = $this->principals->ensureOwner('telegram', '100', 'Owner');
        $this->notice = new MemoryRulesNotice(
            new ContextMemory($this->sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), new InMemoryProjectRepository(), fn(): int => $this->owner->id),
            $this->rules,
            $this->conversations,
            new ConversationAudience($this->conversations),
            $this->channel,
            $this->principals,
            new OwnerDirectory($this->principals, 'telegram', '100'),
            fn(): bool => $this->on,
            new NullLogger(),
        );
    }

    private function finished(int $conversationId): Run {
        return new Run(1, $conversationId, $this->owner->id, null, 'm', \OpenCCK\Domain\Agent\RunStatus::Done, \OpenCCK\Domain\Agent\StopReason::Answered, new \OpenCCK\Domain\Agent\RunLimits(10, 1000, 60, 30));
    }

    private function write(string $text): void {
        $this->sandbox->files[$this->owner->id]['MEMORY.md'] = $text;
    }

    public function testOffNothingIsToldNorKept(): void {
        $this->on = false;
        $chat = $this->conversations->openConversation($this->owner->id, 'telegram', '100');
        $this->conversations->setPrivateChat($chat, true);
        $this->rules->keep('x', 'old');
        $this->write("## Правила\nкоротко");

        $this->notice->afterRun($this->finished($chat), $this->owner);

        self::assertSame([], $this->channel->texts('send'));
        self::assertSame([], $this->rules->snapshots, 'with the notice off nothing is kept');
    }

    public function testAChangeIsToldAndTakenBack(): void {
        $chat = $this->conversations->openConversation($this->owner->id, 'telegram', '100');
        $this->conversations->setPrivateChat($chat, true);
        $this->write("## Правила\n- Отвечай коротко\n## Заметки\nдочь Аня");
        $this->notice->afterRun($this->finished($chat), $this->owner);
        self::assertSame([], $this->channel->texts('send'), 'the first look is the starting point');

        $this->write("## Правила\n- Отвечай коротко\n- Всегда по-английски\n## Заметки\nдочь Аня, сын Петя");
        $this->notice->afterRun($this->finished($chat), $this->owner);

        $told = $this->channel->texts('send');
        self::assertCount(1, $told);
        self::assertStringContainsString('+ - Всегда по-английски', $told[0]);
        $button = end($this->channel->buttons)[0][0] ?? null;
        self::assertNotNull($button);

        $this->notice->handleCallback(new IncomingCallback('telegram', 'cb1', '100', '100', 'Owner', '1', $button->data, '7'));

        $file = $this->sandbox->files[$this->owner->id]['MEMORY.md'] ?? '';
        self::assertStringContainsString('- Отвечай коротко', $file);
        self::assertStringNotContainsString('по-английски', $file, 'the rule is taken back');
        self::assertStringContainsString('сын Петя', $file, 'the notes stay as they are now');
    }

    public function testAnOldButtonDoesNotOverwriteANewerChange(): void {
        $chat = $this->conversations->openConversation($this->owner->id, 'telegram', '100');
        $this->conversations->setPrivateChat($chat, true);
        $this->write("## Правила\n- Отвечай коротко");
        $this->notice->afterRun($this->finished($chat), $this->owner);
        $this->write("## Правила\n- Отвечай подробно");
        $this->notice->afterRun($this->finished($chat), $this->owner);
        $button = end($this->channel->buttons)[0][0] ?? null;
        self::assertNotNull($button);
        $this->write("## Правила\n- Отвечай подробно\n- И с примерами");

        $this->notice->handleCallback(new IncomingCallback('telegram', 'cb1', '100', '100', 'Owner', '1', $button->data, '7'));

        self::assertStringContainsString('И с примерами', $this->sandbox->files[$this->owner->id]['MEMORY.md'] ?? '');
        self::assertTrue(end($this->channel->answers)[2] ?? false, 'told why, as an alert');
    }
}
