<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\AttachmentKind;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Agent\Media\AttachmentIntake;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * A message with files reaches the agent: a photo without a caption is no longer dropped, the files are described in
 * the message, and the run knows data from outside came in.
 */
final class ChatAttachmentsTest extends AsyncTestCase {
    private AgentStack $stack;
    private FakeSandbox $sandbox;
    private int $id = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Вижу файл.'), ScriptedLlm::text('Ещё.')]));
        $this->sandbox = new FakeSandbox();
        $intake = new AttachmentIntake($this->stack->channel, $this->sandbox, MediaSettings::fromEnv([]), new DateTimeZone('+03:00'));
        $this->stack->chat = new ChatService(new AccessService($this->stack->principals, 'telegram', '42', $this->stack->visitors), $this->stack->conversations, $this->stack->scheduler, $this->stack->channel, $this->stack->approvalService, intake: $intake, policies: $this->stack->policies);
    }

    /** @param list<Attachment> $attachments */
    private function send(string $text, array $attachments): void {
        $id = (string) ++$this->id;
        $this->stack->chat->handle(new IncomingMessage('telegram', '42', '42', 'owner', 'Owner', $text, $id, null, true, new DateTimeImmutable(), 'u' . $id, attachments: $attachments));
    }

    private function photo(string $id): Attachment {
        $this->stack->channel->downloads[$id] = 'JPEG' . $id;
        return new Attachment(AttachmentKind::Photo, $id, 'photo-' . $id . '.jpg', 'image/jpeg', 5, null, 800, 600);
    }

    private function asked(): string {
        $request = $this->stack->llm->requests[0] ?? null;
        self::assertNotNull($request, 'the model was asked');
        $messages = $request->messages;
        $last = end($messages);
        return $last === false ? '' : $last->content;
    }

    public function testAPhotoWithoutCaptionReachesTheAgent(): void {
        $this->send('', [$this->photo('p1')]);
        $this->stack->scheduler->awaitIdle();

        self::assertStringContainsString('MEDIA_VISION_MODELS', $this->asked());
        self::assertContains('Вижу файл.', [...$this->stack->channel->texts('send'), ...$this->stack->channel->texts('edit')], 'the agent answered');
    }

    public function testACaptionAndItsPhotoGoTogether(): void {
        $this->send('Что на фото?', [$this->photo('p1')]);
        $this->stack->scheduler->awaitIdle();

        $asked = $this->asked();
        self::assertStringContainsString('Что на фото?', $asked);
        self::assertGreaterThan(strpos($asked, 'Что на фото?'), strpos($asked, 'photo-p1.jpg'), 'the caption first, then the file');
    }

    public function testAnAlbumOfThreePhotosIsOneRun(): void {
        $this->send('Альбом', [$this->photo('a1')]);
        $this->send('', [$this->photo('a2')]);
        $this->send('', [$this->photo('a3')]);
        $this->stack->scheduler->awaitIdle();

        self::assertCount(1, $this->stack->llm->requests, 'one run for the album');
        self::assertSame(3, substr_count($this->asked(), 'MEDIA_VISION_MODELS'));
    }

    public function testAttachmentsMarkTheRunOutside(): void {
        $this->send('Посмотри', [$this->photo('p1')]);
        $this->stack->scheduler->awaitIdle();

        $run = $this->stack->runs->find(1);
        self::assertStringContainsString('attachments', $run->outside ?? '');
    }

    public function testACommandWithAFileIsStillACommand(): void {
        $this->send('/start', [$this->photo('p1')]);
        $this->stack->scheduler->awaitIdle();

        self::assertSame([], $this->stack->llm->requests);
        self::assertSame([], $this->sandbox->calls, 'the file of a command is not taken');
    }
}
