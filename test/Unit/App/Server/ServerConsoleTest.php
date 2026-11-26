<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Server;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Servers\SshRunTool;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\SshFixture;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the wrapper of the core around our MCP: the policy, the
 * journal, the host key, the process of the entry as the way to the server
 */
final class ServerConsoleTest extends AsyncTestCase {
    private SshFixture $ssh;
    private Member $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->ssh = new SshFixture();
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $this->ssh->entry('member:1', 'web', ['--host=10.0.0.5', '--user=deploy', '--group=prod']);
    }

    public function testAReadRunsThroughTheProcessOfTheEntryAndIsJournalledTwice(): void {
        [$ok, $text] = $this->ssh->console()->run($this->owner, 7, 'web', 'df -h', 30, new NullCancellation());

        self::assertTrue($ok);
        self::assertStringContainsString('код выхода: 0', $text);
        self::assertStringContainsString("stdout:\nok", $text);
        self::assertSame([['member:1|web', ['command' => 'df -h', 'timeout' => 30]]], $this->ssh->calls);
        self::assertSame(['start', 'done'], array_column($this->ssh->audit->records, 'event'));
        self::assertSame('member:1|web', $this->ssh->audit->records[0]['server'], 'the journal names the connection');
        self::assertSame('read', $this->ssh->audit->records[0]['class']);
        self::assertSame(7, $this->ssh->audit->records[1]['run']);
        self::assertSame(0, $this->ssh->audit->records[1]['exit']);
        self::assertNotNull($this->ssh->audit->records[1]['ms']);
        self::assertNull($this->ssh->audit->verify());
    }

    public function testAnEntryOfAnotherMemberIsNotReached(): void {
        $guest = new Member(2, MemberKind::Human, Role::user(), 'Гость');

        [$ok, $text] = $this->ssh->console()->run($guest, null, 'web', 'uptime', 30, new NullCancellation(), false, 'member:2');

        self::assertFalse($ok);
        self::assertStringContainsString('web', $text);
        self::assertSame([], $this->ssh->calls);
    }

    public function testTheContextOfTheRunComesFirst(): void {
        $this->ssh->entry('project:3', 'web', ['--host=10.9.9.9', '--user=app']);

        $this->ssh->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation(), false, 'project:3');
        $this->ssh->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation(), false, 'project:4');

        self::assertSame(['project:3|web', 'member:1|web'], array_column($this->ssh->calls, 0));
    }

    public function testNeverRunsAndIsJournalledAsRefused(): void {
        [$ok, $text] = $this->ssh->console()->run($this->owner, null, 'web', 'rm -rf /', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertStringContainsString('запрещена всегда', $text);
        self::assertSame([], $this->ssh->calls);
        self::assertSame(['refused'], array_column($this->ssh->audit->records, 'event'));
        self::assertNotNull($this->ssh->audit->records[0]['error'], 'the reason of the refusal is kept');
    }

    public function testNeverRunsWhenTheOwnerAllowedIt(): void {
        [$ok, $text] = $this->ssh->console()->run($this->owner, 3, 'web', 'rm -rf /', 30, new NullCancellation(), approved: true);

        self::assertTrue($ok, $text);
        self::assertSame(['rm -rf /'], $this->ssh->commands());
        self::assertSame('never', $this->ssh->audit->records[0]['class']);
    }

    public function testNeverCommandsWaitForTheOwnerAndMayBeGrantedForGood(): void {
        $tool = new SshRunTool($this->ssh->console());
        $args = ['server' => 'web', 'command' => 'rm -rf /'];

        self::assertSame(DangerClass::Irreversible, $tool->danger($args));
        self::assertStringStartsWith('ssh-never:v' . SshCommandClassifier::VERSION . ':web:', (string) $tool->grantKey($args));
        self::assertNotSame($tool->grantKey($args), $tool->grantKey(['server' => 'web', 'command' => 'rm -rf /home']));
        self::assertStringContainsString('ОПАСНО', $tool->describe($args));
    }

    public function testABrokenJournalStopsTheCommand(): void {
        $this->ssh->audit->broken = true;

        [$ok, $text] = $this->ssh->console()->run($this->owner, null, 'web', 'df -h', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertSame('Журнал команд недоступен — команда не выполнена.', $text);
        self::assertSame([], $this->ssh->calls);
    }

    public function testAnUnknownKeyGoesToTheOwnerAndWaits(): void {
        $this->ssh->answer = ['UNKNOWN_HOST_KEY SHA256:abc new', true, null];

        [$ok, $text] = $this->ssh->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertStringContainsString('ещё не знаком', $text);
        self::assertSame(['member:1|web', 'SHA256:abc', false, false], [$this->ssh->asked[0][0]->key, $this->ssh->asked[0][2], $this->ssh->asked[0][3], $this->ssh->asked[0][4]]);
        self::assertSame('SHA256:abc', $this->ssh->keys->of('member:1|web', '10.0.0.5:22')?->pending);
        self::assertSame(['start', 'refused'], array_column($this->ssh->audit->records, 'event'));
    }

    public function testAKeyOfTheServerInBetweenWaitsByItsOwnAddress(): void {
        $this->ssh->entry('member:1', 'inner', ['--host=192.168.1.5', '--user=app', '--jump-host=bastion', '--jump-user=ops']);
        $this->ssh->answer = ['UNKNOWN_HOST_KEY SHA256:jj new jump', true, null];

        [, $text] = $this->ssh->console()->run($this->owner, null, 'inner', 'uptime', 30, new NullCancellation());

        self::assertStringContainsString('bastion:22', $text);
        self::assertSame('SHA256:jj', $this->ssh->keys->of('member:1|inner', 'bastion:22')?->pending);
        self::assertTrue($this->ssh->asked[0][4]);
    }

    public function testTheOutputOfACommandCannotPassForARefusedKey(): void {
        $this->ssh->answer = ['UNKNOWN_HOST_KEY SHA256:fake new', false, ['exit' => 0, 'stdout' => "UNKNOWN_HOST_KEY SHA256:fake new\n", 'stderr' => '', 'cut' => false]];

        [$ok] = $this->ssh->console()->run($this->owner, null, 'web', 'cat x', 30, new NullCancellation());

        self::assertTrue($ok);
        self::assertSame([], $this->ssh->asked);
    }

    public function testFailuresAndSecretsInOutputAreMasked(): void {
        $this->ssh->answer = ['exit 1', true, ['exit' => 1, 'stdout' => 'token=sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef', 'stderr' => '', 'cut' => true]];

        [, $text] = $this->ssh->console()->run($this->owner, null, 'web', 'cat .env', 30, new NullCancellation());
        self::assertStringNotContainsString('0123456789abcdef0123', $text);
        self::assertStringContainsString('вывод обрезан', $text);

        $this->ssh->failure = new \DomainException('No connection to 10.0.0.5:22: the host does not answer');
        [$ok, $text] = $this->ssh->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation());
        self::assertFalse($ok);
        self::assertStringContainsString('does not answer', $text);
        $events = array_column($this->ssh->audit->records, 'event');
        self::assertSame('failed', end($events));
    }

    public function testAFailureOfTheServerSaidInWordsIsAFailure(): void {
        $this->ssh->answer = ['No connection to 10.0.0.5:22: the login was refused', true, null];

        [$ok, $text] = $this->ssh->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertStringContainsString('the login was refused', $text);
    }

    public function testTheToolAsksTheOwnerForChangesOnly(): void {
        $tool = new SshRunTool($this->ssh->console());

        self::assertSame(DangerClass::Read, $tool->danger(['server' => 'web', 'command' => 'docker ps']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['server' => 'web', 'command' => 'systemctl restart nginx']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['server' => 'web', 'command' => 'sudo id']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['server' => 'web', 'command' => 'reboot']), 'the owner decides even about the never list');
    }

    public function testAConfirmedGroupAsksEvenForReads(): void {
        $tool = new SshRunTool($this->ssh->console(['prod']));

        self::assertSame(DangerClass::Irreversible, $tool->danger(['server' => 'web', 'command' => 'uptime']));
        self::assertNotNull($tool->grantKey(['server' => 'web', 'command' => 'uptime']));
    }

    public function testGrantsCoverOneExactChangeAndNeverPrivileges(): void {
        $tool = new SshRunTool($this->ssh->console());

        $key = $tool->grantKey(['server' => 'Web', 'command' => 'systemctl restart nginx']);
        self::assertSame($key, $tool->grantKey(['server' => 'web', 'command' => ' systemctl restart nginx ']));
        self::assertNotSame($key, $tool->grantKey(['server' => 'web', 'command' => 'systemctl restart php']));
        self::assertStringContainsString(':v' . SshCommandClassifier::VERSION . ':', (string) $key);
        self::assertStringStartsWith('ssh-sudo:v' . SshCommandClassifier::VERSION . ':web:', (string) $tool->grantKey(['server' => 'web', 'command' => 'sudo systemctl restart nginx']));
        self::assertNull($tool->grantKey(['server' => 'web', 'command' => 'df -h']), 'a read needs no grant');
    }

    public function testTheJournalChainBreaksOnAChange(): void {
        $this->ssh->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation());
        $this->ssh->console()->run($this->owner, null, 'web', 'df -h', 30, new NullCancellation());

        $this->ssh->audit->tamper(2, 'uptime');

        self::assertSame(3, $this->ssh->audit->verify(), 'the start of "df -h" no longer matches its hash');
    }
}
