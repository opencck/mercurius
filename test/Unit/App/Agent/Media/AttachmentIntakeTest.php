<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Media;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\AttachmentKind;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Agent\Media\AttachmentIntake;
use OpenCCK\App\Agent\Media\IntakeResult;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeSandbox;

/**
 * What came with a message goes into the sandbox, and the agent gets a line per file: the text of documents, or a
 * note that the model for this kind is not set and how to set it.
 */
final class AttachmentIntakeTest extends AsyncTestCase {
    private FakeChannel $channel;
    private FakeSandbox $sandbox;
    private Principal $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->channel = new FakeChannel();
        $this->sandbox = new FakeSandbox();
        $this->owner = new Principal(1, PrincipalKind::Human, Role::Owner, 'Сергей');
    }

    /** @param array<string, string> $env */
    private function intake(array $env = []): AttachmentIntake {
        return new AttachmentIntake($this->channel, $this->sandbox, MediaSettings::fromEnv($env), new DateTimeZone('+03:00'), static fn(): DateTimeImmutable => new DateTimeImmutable('2026-11-17 12:00:00+03:00'));
    }

    private static function policy(bool $sandbox = true): ParticipantPolicy {
        return new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), $sandbox ? new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4) : null, true);
    }

    /** @param list<Attachment> $attachments */
    private static function message(array $attachments, string $text = ''): IncomingMessage {
        return new IncomingMessage('telegram', '42', '42', 'owner', 'Сергей', $text, '7', null, true, new DateTimeImmutable(), 'u7', attachments: $attachments);
    }

    private function take(Attachment ...$attachments): IntakeResult {
        return $this->intake()->intake($this->owner, self::policy(), self::message(array_values($attachments)), new NullCancellation());
    }

    private static function document(string $name, string $id = 'f1', ?int $size = null): Attachment {
        return new Attachment(AttachmentKind::Document, $id, $name, null, $size);
    }

    public function testAPhotoIsKeptAndTheAgentIsToldNoVisionModelIsSet(): void {
        $this->channel->downloads['p1'] = 'JPEG';

        $result = $this->take(new Attachment(AttachmentKind::Photo, 'p1', 'photo-7.jpg', 'image/jpeg', 4, null, 1280, 853));

        self::assertSame('JPEG', $this->sandbox->files[1]['inbox/2026-11-17/photo-7.jpg'] ?? null);
        self::assertStringContainsString('inbox/2026-11-17/photo-7.jpg', $result->text);
        self::assertStringContainsString('1280×853', $result->text);
        self::assertStringContainsString('MEDIA_VISION_MODELS', $result->text, 'the agent can say what to set');
        self::assertTrue($result->outside);
    }

    public function testVoiceAndVideoNameTheirModels(): void {
        $this->channel->downloads['v'] = 'OGG';
        $this->channel->downloads['w'] = 'MP4';

        $result = $this->take(new Attachment(AttachmentKind::Voice, 'v', 'voice-7.ogg', 'audio/ogg', 3, 4), new Attachment(AttachmentKind::Video, 'w', 'video-7.mp4', 'video/mp4', 3, 30));

        self::assertStringContainsString('MEDIA_SPEECH_MODELS', $result->text);
        self::assertStringContainsString('MEDIA_VIDEO_MODELS', $result->text);
    }

    public function testATextFileComesInsideTheUntrustedFrame(): void {
        $this->channel->downloads['f1'] = "# Заметки\nключ sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef";

        $result = $this->take(self::document('notes.md'));

        self::assertStringContainsString('<untrusted-data source="file:notes.md">', $result->text);
        self::assertStringContainsString('# Заметки', $result->text);
        self::assertStringNotContainsString('0123456789abcdef0123', $result->text, 'secrets are masked');
    }

    public function testAnOfficeDocumentIsExtractedInTheSandbox(): void {
        $this->channel->downloads['f1'] = 'PK...';
        $this->sandbox->outcome = new CommandOutcome(0, 'Отчёт за неделю', '', false, false, 5);

        $result = $this->take(self::document('report.docx'));

        self::assertContains("exec 1 python3 /opt/mercurius/extract_text.py 'inbox/2026-11-17/report.docx' 20000 @. t30", $this->sandbox->calls);
        self::assertStringContainsString('Отчёт за неделю', $result->text);
        self::assertStringContainsString('<untrusted-data source="file:report.docx">', $result->text);
    }

    public function testAnUnsupportedOrBrokenDocumentSaysSo(): void {
        $this->channel->downloads['f1'] = 'PK...';
        $this->sandbox->outcome = new CommandOutcome(1, '', 'BadZipFile: File is not a zip file', false, false, 5);

        self::assertStringContainsString('BadZipFile', $this->take(self::document('report.docx'))->text);
    }

    public function testALongTextIsCutWithThePath(): void {
        $this->channel->downloads['f1'] = str_repeat('а', 21_000);

        $result = $this->take(self::document('long.txt'));

        self::assertStringContainsString(str_repeat('а', 20_000), $result->text);
        self::assertStringNotContainsString(str_repeat('а', 20_001), $result->text);
        self::assertStringContainsString('inbox/2026-11-17/long.txt', $result->text);
    }

    public function testNoSandboxNoFilesKept(): void {
        $this->channel->downloads['f1'] = 'PK...';
        $this->channel->downloads['f2'] = 'просто текст';

        $result = $this->intake()->intake($this->owner, self::policy(false), self::message([self::document('report.docx'), self::document('note.txt', 'f2')]), new NullCancellation());

        self::assertSame([], $this->sandbox->calls, 'nothing goes into a sandbox that is not there');
        self::assertStringContainsString('просто текст', $result->text, 'a text file needs no sandbox');
        self::assertStringNotContainsString('inbox/', $result->text);
    }

    public function testLimitsOfSizeAndCount(): void {
        $big = self::document('big.zip', 'big', 21_000_000);
        $lost = self::document('lost.txt', 'lost');
        $many = array_map(static fn(int $i): Attachment => self::document("n{$i}.txt", "n{$i}"), range(1, 10));
        foreach (range(1, 10) as $i) {
            $this->channel->downloads["n{$i}"] = 'x';
        }

        $result = $this->take($big, $lost, ...$many);

        self::assertStringContainsString('20 МБ', $result->text);
        self::assertStringContainsString('не скачался', $result->text);
        self::assertStringContainsString('больше 10', $result->text, 'the 11th and 12th are not taken');
        self::assertStringNotContainsString('n10.txt →', $result->text);
    }

    public function testANameCannotLeaveTheInbox(): void {
        $this->channel->downloads['f1'] = 'x';

        $this->take(self::document('../../etc/passwd'));

        self::assertSame(['inbox/2026-11-17/_.._etc_passwd'], array_keys($this->sandbox->files[1] ?? []));
    }

    public function testTheSameNameTheSameDayGetsANumber(): void {
        $this->channel->downloads['f1'] = 'first';
        $this->channel->downloads['f2'] = 'second';

        $this->take(self::document('report.txt'));
        $this->take(self::document('report.txt', 'f2'));

        self::assertSame(['inbox/2026-11-17/report.txt' => 'first', 'inbox/2026-11-17/report (2).txt' => 'second'], $this->sandbox->files[1] ?? []);
    }

    public function testCp1251TextIsConverted(): void {
        $this->channel->downloads['f1'] = (string) mb_convert_encoding('Привет из Windows', 'Windows-1251', 'UTF-8');

        self::assertStringContainsString('Привет из Windows', $this->take(self::document('old.txt'))->text);
    }

    public function testNothingAttachedNothingSaid(): void {
        $result = $this->take();

        self::assertSame(['', false], [$result->text, $result->outside]);
    }

    public function testTheFileOfTheMessageRepliedToComesToo(): void {
        $this->channel->downloads['old'] = 'JPEG';
        $photo = new Attachment(AttachmentKind::Photo, 'old', 'photo-5.jpg', 'image/jpeg', 4, null, 640, 480);
        $message = new IncomingMessage('telegram', '42', '42', 'owner', 'Сергей', 'что тут?', '7', '5', true, new DateTimeImmutable(), 'u7', attachments: [$photo], repliedAttachments: [$photo]);

        $result = $this->intake()->intake($this->owner, self::policy(), new IncomingMessage('telegram', '42', '42', 'owner', 'Сергей', 'что тут?', '8', '5', true, new DateTimeImmutable(), 'u8', repliedAttachments: [$photo]), new NullCancellation());
        self::assertStringContainsString('photo-5.jpg', $result->text);
        self::assertStringContainsString('ответ', $result->text, 'the agent knows it is the file of the message replied to');

        $twice = $this->intake()->intake($this->owner, self::policy(), $message, new NullCancellation());
        self::assertSame(1, substr_count($twice->text, '📎'), 'the same file once');
    }
}
