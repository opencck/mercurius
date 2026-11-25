<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Messaging\SendFileTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryConversationRepository;

/**
 * A file the agent made in the sandbox goes to the chat it was asked in, into the same forum topic.
 */
final class SendFileToolTest extends AsyncTestCase {
    private FakeSandbox $sandbox;
    private FakeChannel $channel;
    private InMemoryConversationRepository $conversations;
    private SendFileTool $tool;

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandbox();
        $this->channel = new FakeChannel();
        $this->conversations = new InMemoryConversationRepository();
        $this->tool = new SendFileTool($this->sandbox, $this->channel, $this->conversations);
    }

    private function context(int $conversation, bool $withSandbox = true): ToolContext {
        return new ToolContext(3, $conversation, new Member(7, MemberKind::Human, Role::owner(), null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), $withSandbox ? new SandboxLimits(100, 10, 5, 1000, 1) : null, true), 'c1');
    }

    public function testTheFileGoesToTheTopicItWasAskedIn(): void {
        $this->sandbox->files[7]['out/demo.pptx'] = "PK\x03\x04binary";
        $topic = $this->conversations->openConversation(7, 'telegram', '-100500', '119');

        $result = $this->tool->execute(['path' => 'out/demo.pptx', 'caption' => 'Презентация'], $this->context($topic));

        self::assertFalse($result->isError, $result->content);
        self::assertSame([['-100500', 'demo.pptx', "PK\x03\x04binary"]], $this->channel->files);
        self::assertSame('119', $this->channel->fileThreads[0] ?? null);
        self::assertStringContainsString('demo.pptx', $result->content);
        self::assertSame(DangerClass::Write, $this->tool->danger(['path' => 'x']), 'like an answer in the same chat: no card');
    }

    public function testInATaskOfAnotherAgentTheFileGoesWithTheAnswer(): void {
        $this->sandbox->files[7]['out/report.pdf'] = 'PDF';
        $task = $this->conversations->openConversation(7, 'a2a', 'a2a-7-ctx-1');
        $answered = [];
        $tool = new SendFileTool($this->sandbox, $this->channel, $this->conversations, answerTask: static function (int $runId, string $name, string $bytes) use (&$answered): bool {
            $answered[] = [$runId, $name, $bytes];
            return true;
        });

        $result = $tool->execute(['path' => 'out/report.pdf'], $this->context($task));

        self::assertFalse($result->isError, $result->content);
        self::assertSame([[3, 'report.pdf', 'PDF']], $answered);
        self::assertSame([], $this->channel->files, 'not to a chat');
    }

    public function testAMissingFileOrNoSandboxIsAnError(): void {
        $chat = $this->conversations->openConversation(7, 'telegram', '42');

        self::assertTrue($this->tool->execute(['path' => 'none.pptx'], $this->context($chat))->isError);
        self::assertTrue($this->tool->execute(['path' => 'none.pptx'], $this->context($chat, false))->isError);
        self::assertSame([], $this->channel->files);
    }

    public function testAFileTheMessengerDidNotTakeIsReported(): void {
        $this->sandbox->files[7]['a.txt'] = 'x';
        $chat = $this->conversations->openConversation(7, 'telegram', '42');
        $this->channel->sendFileFails = true;

        $result = $this->tool->execute(['path' => 'a.txt'], $this->context($chat));

        self::assertTrue($result->isError);
    }
}
