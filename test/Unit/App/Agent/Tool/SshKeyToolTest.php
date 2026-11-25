<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Tool\Sandbox\SshKeyTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Ssh\SshKey;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemorySshKeyRepository;

/**
 * spec: brief/servers.md#ключи-ssh-в-песочнице — keys of the participant's ~/.ssh: create, list, show, delete, the
 * secret with a passphrase; the private key never leaves the sandbox
 */
final class SshKeyToolTest extends AsyncTestCase {
    private const PUB = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq mercurius';
    private FakeSandbox $sandbox;
    private InMemorySshKeyRepository $keys;
    private Vault $vault;
    private Member $member;

    protected function setUp(): void {
        parent::setUp();
        $this->sandbox = new FakeSandbox();
        $this->keys = new InMemorySshKeyRepository();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->member = new Member(7, MemberKind::Human, Role::admin(), 'Owner');
    }

    private function tool(): SshKeyTool {
        return new SshKeyTool($this->sandbox, $this->sandbox, $this->keys, $this->vault);
    }

    private function context(): ToolContext {
        return new ToolContext(3, 9, $this->member, new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    public function testANewKeyIsMadeInTheSandboxAndOnlyItsPublicPartIsKept(): void {
        $this->sandbox->outcome = new CommandOutcome(0, "KEY\tdeploy\tSHA256:Zm9v\t" . self::PUB . "\n", '', false, false, 1);

        $result = $this->tool()->execute(['action' => 'create', 'name' => 'deploy'], $this->context());

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString("ssh-keygen -q -t ed25519 -N '' -C 'mercurius' -f '.ssh/deploy'", $this->sandbox->calls[0] ?? '');
        self::assertStringContainsString('chmod 600', $this->sandbox->calls[0] ?? '');
        self::assertStringContainsString(self::PUB, $result->content);
        self::assertStringContainsString('SHA256:Zm9v', $result->content);
        self::assertSame('SHA256:Zm9v', $this->keys->find(7, 'deploy')?->fingerprint);
    }

    public function testANameWithAPathIsRefused(): void {
        foreach (['../x', '.ssh', 'a/b', 'UPPER', ''] as $name) {
            $result = $this->tool()->execute(['action' => 'create', 'name' => $name], $this->context());
            self::assertTrue($result->isError, $name);
        }
        self::assertSame([], $this->sandbox->calls);
    }

    public function testTheListTakesInKeysPutThereByHandAndDropsTheGoneOnes(): void {
        $this->keys->save(new SshKey(7, 'old', 'SHA256:old', 'ssh-ed25519 OLD'));
        $this->keys->save(new SshKey(7, 'git', 'SHA256:git', 'ssh-ed25519 GIT'));
        $this->keys->setPassphrase(7, 'git', 'git-pass');
        $this->sandbox->outcome = new CommandOutcome(0, "KEY\tgit\tSHA256:git\tssh-ed25519 GIT\nKEY\thand\tSHA256:hand\tssh-rsa HAND\n", '', false, false, 1);

        $result = $this->tool()->execute(['action' => 'list'], $this->context());

        self::assertSame(['git', 'hand'], array_map(static fn(SshKey $k): string => $k->name, $this->keys->of(7)));
        self::assertSame('git-pass', $this->keys->find(7, 'git')?->passphraseSecret, 'the passphrase secret stays');
        self::assertStringContainsString('hand', $result->content);
        self::assertStringContainsString('git-pass', $result->content, 'the name of the secret, never its value');
    }

    public function testAPassphraseIsASecretOfTheVaultNamedHere(): void {
        $this->keys->save(new SshKey(7, 'git', 'SHA256:git', 'ssh-ed25519 GIT'));

        self::assertTrue($this->tool()->execute(['action' => 'passphrase', 'name' => 'git', 'secret' => 'none'], $this->context())->isError, 'no such secret');
        $this->vault->put('git-pass', 'password', 'hunter22', $this->member);
        $result = $this->tool()->execute(['action' => 'passphrase', 'name' => 'git', 'secret' => 'git-pass'], $this->context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame('git-pass', $this->keys->find(7, 'git')?->passphraseSecret);
        self::assertStringNotContainsString('hunter22', $result->content);
    }

    public function testDeletingIsIrreversibleAndRemovesBothFiles(): void {
        $this->keys->save(new SshKey(7, 'git', 'SHA256:git', 'ssh-ed25519 GIT'));
        $tool = $this->tool();

        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'delete', 'name' => 'git']));
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'list']));
        $result = $tool->execute(['action' => 'delete', 'name' => 'git'], $this->context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame(["exec 7 rm -f '.ssh/git' '.ssh/git.pub' @. t30"], $this->sandbox->calls);
        self::assertNull($this->keys->find(7, 'git'));
    }
}
