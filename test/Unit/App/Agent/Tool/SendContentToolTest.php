<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Messaging\Recipients;
use OpenCCK\App\Agent\Tool\Messaging\SendContentTool;
use OpenCCK\App\Agent\Tool\Messaging\SendFileTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

/**
 * Everything the messenger can carry: photos, audio, video, voice, animations, a location, a contact, a poll, an
 * article — to this chat or to a participant.
 */
final class SendContentToolTest extends AsyncTestCase {
    private FakeChannel $channel;
    private FakeSandbox $sandbox;
    private InMemoryConversationRepository $conversations;
    private InMemoryPrincipalRepository $principals;
    private Principal $owner;
    private Principal $kostya;
    private int $topic;

    protected function setUp(): void {
        parent::setUp();
        $this->channel = new FakeChannel();
        $this->sandbox = new FakeSandbox();
        $this->conversations = new InMemoryConversationRepository();
        $this->principals = new InMemoryPrincipalRepository();
        $this->owner = $this->principals->ensureOwner('telegram', '42', 'Сергей');
        $visitor = $this->principals->createVisitor('telegram', '55', 'Konstantin');
        $this->kostya = $this->principals->decideVisitor($visitor->id, Role::user(), PrincipalStatus::Active) ?? $visitor;
        $this->topic = $this->conversations->openConversation($this->owner->id, 'telegram', '-100500', '119');
    }

    private function context(): ToolContext {
        return new ToolContext(1, $this->topic, $this->owner, new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100, 10, 5, 1000, 1), true), approved: true);
    }

    private function files(): SendFileTool {
        return new SendFileTool($this->sandbox, $this->channel, $this->conversations, new Recipients($this->principals));
    }

    private function content(): SendContentTool {
        return new SendContentTool($this->channel, $this->conversations, new Recipients($this->principals));
    }

    public function testAFileGoesAsItsKindGuessedByTheName(): void {
        $this->sandbox->files[$this->owner->id] = ['shots/qr.png' => 'PNG', 'music/song.mp3' => 'MP3', 'clip.mp4' => 'MP4', 'deck.pptx' => 'PK'];

        foreach (['shots/qr.png', 'music/song.mp3', 'clip.mp4', 'deck.pptx'] as $path) {
            self::assertFalse($this->files()->execute(['path' => $path], $this->context())->isError, $path);
        }
        $this->files()->execute(['path' => 'music/song.mp3', 'as' => 'voice'], $this->context());

        self::assertSame(['photo', 'audio', 'video', 'voice'], array_column($this->channel->content, 1));
        self::assertSame(['-100500', 'deck.pptx', 'PK'], $this->channel->files[0], 'anything else is a document');
    }

    public function testAFileGoesToAParticipantWithTheOwnersCard(): void {
        $this->sandbox->files[$this->owner->id]['shots/qr.png'] = 'QR';
        $args = ['path' => 'shots/qr.png', 'to' => 'Konstantin'];

        self::assertSame(DangerClass::Irreversible, $this->files()->danger($args));
        self::assertSame('message:' . $this->kostya->id, $this->files()->grantKey($args));
        self::assertSame(DangerClass::Write, $this->files()->danger(['path' => 'shots/qr.png']), 'to this chat it is an answer');

        $this->files()->execute($args, $this->context());

        self::assertSame(['55', 'photo'], array_slice($this->channel->content[0], 0, 2));
        self::assertNull($this->channel->content[0][3], 'a private chat has no topic');
    }

    public function testALocationAContactAndAPoll(): void {
        $tool = $this->content();

        $tool->execute(['kind' => 'location', 'latitude' => 12.93, 'longitude' => 100.88], $this->context());
        $tool->execute(['kind' => 'contact', 'phone' => '+66123', 'first_name' => 'Сергей'], $this->context());
        $tool->execute(['kind' => 'poll', 'question' => 'Куда?', 'options' => ['Пляж', 'Горы'], 'multiple' => true], $this->context());

        self::assertSame([['-100500', 'location', ['latitude' => 12.93, 'longitude' => 100.88], '119'], ['-100500', 'contact', ['phone' => '+66123', 'first_name' => 'Сергей', 'last_name' => null], '119'], ['-100500', 'poll', ['question' => 'Куда?', 'options' => ['Пляж', 'Горы'], 'anonymous' => true, 'multiple' => true], '119']], $this->channel->content);
        self::assertTrue($tool->execute(['kind' => 'poll', 'question' => 'Один?', 'options' => ['Да']], $this->context())->isError, 'a poll needs two answers');
        self::assertTrue($tool->execute(['kind' => 'location', 'latitude' => 120.0, 'longitude' => 0.0], $this->context())->isError, 'no such place');
    }

    public function testAnArticleIsATitledFormattedText(): void {
        $this->content()->execute(['kind' => 'article', 'title' => 'Итоги недели', 'text' => "Сделано:\n- **деплой**\n- тесты"], $this->context());

        $sent = $this->channel->log[0] ?? null;
        self::assertNotNull($sent);
        self::assertSame('-100500', $sent[1]);
        self::assertStringStartsWith("**Итоги недели**\n\n", $sent[2]);
        self::assertSame(\Mercurius\Messenger\ParseMode::Markdown, $this->channel->modes[0][2] ?? null);
        self::assertSame('119', $this->channel->threads[0] ?? null);
    }
}
