<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Server;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\SshRunTool;
use OpenCCK\App\Server\RemoteFailure;
use OpenCCK\App\Server\RemoteResult;
use OpenCCK\App\Server\RemoteShell;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\App\Server\UnknownHostKey;
use OpenCCK\App\Vault\Vault;
use OpenCCK\CommandPolicy\CommandClassifier;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Server\Server;
use OpenCCK\Test\Support\InMemoryCommandAuditRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemoryServerRepository;
use Psr\Log\NullLogger;

final class ServerConsoleTest extends AsyncTestCase {
    private InMemoryServerRepository $servers;
    private InMemoryCommandAuditRepository $audit;
    private Vault $vault;
    private Principal $owner;
    /** @var list<array{string, string, string}> server, kind, command */
    private array $ran = [];
    /** @var list<array{string, string, bool}> */
    private array $asked = [];
    private RemoteResult $answer;
    private ?\Throwable $failure = null;

    protected function setUp(): void {
        parent::setUp();
        $this->servers = new InMemoryServerRepository();
        $this->audit = new InMemoryCommandAuditRepository();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->owner = new Principal(1, PrincipalKind::Human, Role::Owner, null);
        $this->answer = new RemoteResult(0, "ok\n", '', false, 12);
        $secret = $this->vault->put('web-pass', 'password', 'hunter2-long-pass', $this->owner);
        $this->servers->add('web', '10.0.0.5', 22, 'deploy', $secret, 'prod');
    }

    private function console(): ServerConsole {
        $shell = new class($this) implements RemoteShell {
            public function __construct(private readonly ServerConsoleTest $test) {}

            public function run(Server $server, string $credential, string $kind, string $command, int $timeout, int $maxOutput, Cancellation $cancellation): RemoteResult {
                return $this->test->remote($server, $credential, $kind, $command);
            }
        };
        return new ServerConsole($this->servers, $this->vault, new CommandClassifier(), $this->audit, $shell, function (Server $s, string $f, bool $changed): void {
            $this->asked[] = [$s->name, $f, $changed];
        }, new NullLogger());
    }

    public function remote(Server $server, string $credential, string $kind, string $command): RemoteResult {
        $this->ran[] = [$server->name, $kind, $command];
        self::assertSame('hunter2-long-pass', $credential);
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->answer;
    }

    public function testAReadRunsAndIsJournalledTwice(): void {
        [$ok, $text] = $this->console()->run($this->owner, 7, 'web', 'df -h', 30, new NullCancellation());

        self::assertTrue($ok);
        self::assertStringContainsString('код выхода: 0', $text);
        self::assertStringContainsString("stdout:\nok", $text);
        self::assertSame([['web', 'password', 'df -h']], $this->ran);
        self::assertSame(['start', 'done'], array_column($this->audit->records, 'event'));
        self::assertSame('read', $this->audit->records[0]['class']);
        self::assertSame(7, $this->audit->records[1]['run']);
        self::assertNull($this->audit->verify());
    }

    public function testNeverRunsAndIsJournalledAsRefused(): void {
        [$ok, $text] = $this->console()->run($this->owner, null, 'web', 'rm -rf /', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertStringContainsString('запрещена всегда', $text);
        self::assertSame([], $this->ran);
        self::assertSame(['refused'], array_column($this->audit->records, 'event'));
    }

    public function testABrokenJournalStopsTheCommand(): void {
        $this->audit->broken = true;

        [$ok, $text] = $this->console()->run($this->owner, null, 'web', 'df -h', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertSame('Журнал команд недоступен — команда не выполнена.', $text);
        self::assertSame([], $this->ran);
    }

    public function testAnUnknownKeyGoesToTheOwnerAndWaits(): void {
        $this->failure = new UnknownHostKey('SHA256:abc', false);

        [$ok, $text] = $this->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertStringContainsString('ещё не знаком', $text);
        self::assertSame([['web', 'SHA256:abc', false]], $this->asked);
        self::assertSame('SHA256:abc', $this->servers->byName('web')?->pendingHostKey);
        self::assertSame(['start', 'refused'], array_column($this->audit->records, 'event'));
    }

    public function testFailuresAndSecretsInOutputAreMasked(): void {
        $this->answer = new RemoteResult(1, 'token=sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef', '', true, 5);

        [, $text] = $this->console()->run($this->owner, null, 'web', 'cat .env', 30, new NullCancellation());
        self::assertStringNotContainsString('0123456789abcdef0123', $text);
        self::assertStringContainsString('вывод обрезан', $text);

        $this->failure = new RemoteFailure('не удалось подключиться к 10.0.0.5:22: хост не отвечает');
        [$ok, $text] = $this->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation());
        self::assertFalse($ok);
        self::assertStringContainsString('хост не отвечает', $text);
        $events = array_column($this->audit->records, 'event');
        self::assertSame('failed', end($events));
    }

    public function testAnotherParticipantCannotUseTheOwnersSecret(): void {
        $guest = new Principal(2, PrincipalKind::Human, Role::User, 'Гость');

        [$ok, $text] = $this->console()->run($guest, null, 'web', 'uptime', 30, new NullCancellation());

        self::assertFalse($ok);
        self::assertStringContainsString('недоступен', $text);
        self::assertSame([], $this->ran);
    }

    public function testTheToolAsksTheOwnerForChangesOnly(): void {
        $tool = new SshRunTool($this->console());

        self::assertSame(DangerClass::Read, $tool->danger(['server' => 'web', 'command' => 'docker ps']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['server' => 'web', 'command' => 'systemctl restart nginx']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['server' => 'web', 'command' => 'sudo id']));
        self::assertSame(DangerClass::Write, $tool->danger(['server' => 'web', 'command' => 'reboot']), 'refused, not asked');
    }

    public function testTheJournalChainBreaksOnAChange(): void {
        $this->console()->run($this->owner, null, 'web', 'uptime', 30, new NullCancellation());
        $this->console()->run($this->owner, null, 'web', 'df -h', 30, new NullCancellation());

        $this->audit->tamper(2, 'uptime');

        self::assertSame(3, $this->audit->verify(), 'the start of "df -h" no longer matches its hash');
    }
}
