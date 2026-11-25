<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\AttachmentKind;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Service\SshKeyCommands;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemorySshKeyRepository;

/**
 * spec: brief/servers.md#ключи-ssh-в-песочнице — one's own key goes as a file straight into ~/.ssh, past the model
 */
final class SshKeyCommandsTest extends AsyncTestCase {
    private const PRIVATE = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmU=\n-----END OPENSSH PRIVATE KEY-----\n";
    private FakeChannel $channel;
    private FakeSandbox $sandbox;
    private InMemorySshKeyRepository $keys;
    private Member $member;

    protected function setUp(): void {
        parent::setUp();
        $this->channel = new FakeChannel();
        $this->sandbox = new FakeSandbox();
        $this->keys = new InMemorySshKeyRepository();
        $this->member = new Member(7, MemberKind::Human, Role::admin(), 'Owner');
    }

    private function commands(?SandboxLimits $limits = new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4)): SshKeyCommands {
        return new SshKeyCommands($this->channel, $this->sandbox, $this->keys, static fn(Member $m): ?SandboxLimits => $limits);
    }

    private function file(string $bytes): Attachment {
        $this->channel->downloads['f1'] = $bytes;
        return new Attachment(AttachmentKind::Document, 'f1', 'id_ed25519', 'application/octet-stream', strlen($bytes));
    }

    public function testAPrivateKeyGoesIntoTheSshFolderWithItsPublicPart(): void {
        $this->sandbox->outcome = new CommandOutcome(0, "KEY\tgithub\tSHA256:Zm9v\tssh-ed25519 AAAA me\n", '', false, false, 1);

        $answer = $this->commands()->add($this->member, 'github', $this->file(self::PRIVATE));

        self::assertSame(self::PRIVATE, $this->sandbox->files[7]['.ssh/github'] ?? null);
        self::assertStringContainsString("chmod 600 '.ssh/github'", implode("\n", $this->sandbox->calls));
        self::assertStringContainsString('SHA256:Zm9v', $answer);
        self::assertStringNotContainsString('b3BlbnNz', $answer, 'the private key is never repeated');
        self::assertSame('SHA256:Zm9v', $this->keys->find(7, 'github')?->fingerprint);
    }

    public function testAPublicKeyFileIsThePublicPart(): void {
        $this->sandbox->outcome = new CommandOutcome(0, "KEY\tgithub\tSHA256:Zm9v\tssh-ed25519 AAAA me\n", '', false, false, 1);

        $this->commands()->add($this->member, 'github', $this->file("ssh-ed25519 AAAA me\n"));

        self::assertSame("ssh-ed25519 AAAA me\n", $this->sandbox->files[7]['.ssh/github.pub'] ?? null);
    }

    public function testWhatIsNotAKeyOrHasABadNameIsRefused(): void {
        self::assertStringContainsString('/sshkey add', $this->commands()->add($this->member, 'github', null));
        self::assertNotSame('', $this->commands()->add($this->member, '../x', $this->file(self::PRIVATE)));
        $this->commands()->add($this->member, 'github', $this->file('hello'));
        self::assertSame([], $this->sandbox->files, 'nothing written');
        self::assertNotSame('', $this->commands(null)->add($this->member, 'github', $this->file(self::PRIVATE)), 'no sandbox');
        self::assertSame([], $this->sandbox->files);
    }
}
