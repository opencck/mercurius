<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use OpenCCK\App\Agent\Tool\Servers\ServerEditTool;
use OpenCCK\App\Agent\Tool\Servers\ServerRemoveTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\ServerCommands;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryCommandAuditRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemoryServerRepository;
use PHPUnit\Framework\TestCase;

/**
 * The registry of servers is changed in the dialogue: a field, the trusted host key, the whole server.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the registry of servers
 */
final class ServerEditToolsTest extends TestCase {
    private InMemoryServerRepository $servers;
    private Vault $vault;
    private ToolContext $context;
    private Member $owner;

    protected function setUp(): void {
        $this->servers = new InMemoryServerRepository();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $this->context = new ToolContext(1, 1, $this->owner, new NullCancellation(), approved: true);
        $this->vault->put('web-pass', 'password', 'x', $this->owner);
        $this->vault->put('web-key', 'private_key', 'y', $this->owner);
        $web = $this->servers->add('web', '10.0.0.5', 22, 'deploy', $this->vault->id('web-pass'), 'prod');
        $this->servers->setPendingHostKey($web->id, 'SHA256:old');
        $this->servers->decideHostKey($web->id, true);
    }

    public function testAFieldChangesAndANewAddressForgetsTheTrustedKey(): void {
        $tool = new ServerEditTool($this->servers, $this->vault);

        self::assertSame(DangerClass::Irreversible, $tool->danger(['name' => 'web']));
        self::assertSame('server-edit:web', $tool->grantKey(['name' => 'web']));
        $result = $tool->execute(['name' => 'web', 'secret' => 'web-key', 'group' => 'test'], $this->context);
        self::assertFalse($result->isError, $result->content);
        $web = $this->servers->byName('web');
        self::assertSame([$this->vault->id('web-key'), 'test', 'SHA256:old'], [$web?->secretId, $web?->group, $web?->hostKey], 'the key stays for the same address');

        $result = $tool->execute(['name' => 'web', 'host' => '10.0.0.6'], $this->context);
        self::assertFalse($result->isError, $result->content);
        self::assertSame(['10.0.0.6', null], [$this->servers->byName('web')?->host, $this->servers->byName('web')?->hostKey]);
        self::assertStringContainsString('ключ хоста', $result->content);
    }

    public function testAChangedHostKeyIsForgottenWithoutSql(): void {
        // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a server whose host key changed is fixed in the dialogue
        $result = (new ServerEditTool($this->servers, $this->vault))->execute(['name' => 'web', 'forget_host_key' => true], $this->context);

        self::assertFalse($result->isError, $result->content);
        self::assertNull($this->servers->byName('web')?->hostKey);
    }

    public function testWrongValuesAreRefused(): void {
        $tool = new ServerEditTool($this->servers, $this->vault);

        self::assertTrue($tool->execute(['name' => 'nope', 'group' => 'x'], $this->context)->isError, 'no such server');
        self::assertTrue($tool->execute(['name' => 'web', 'secret' => 'missing'], $this->context)->isError, 'no such secret');
        self::assertTrue($tool->execute(['name' => 'web', 'host' => 'a b'], $this->context)->isError, 'a bad host');
        self::assertTrue($tool->execute(['name' => 'web', 'route' => 'via:web'], $this->context)->isError, 'through itself');
        self::assertTrue($tool->execute(['name' => 'web'], $this->context)->isError, 'nothing to change');
    }

    public function testAServerOthersGoThroughIsNotRemoved(): void {
        $this->servers->add('db', '10.0.0.7', 22, 'pg', null, 'prod', 'via:web');
        $tool = new ServerRemoveTool($this->servers);

        self::assertSame('server-remove:web', $tool->grantKey(['name' => 'web']));
        $refused = $tool->execute(['name' => 'web'], $this->context);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('db', $refused->content);

        self::assertFalse($tool->execute(['name' => 'db'], $this->context)->isError);
        self::assertFalse($tool->execute(['name' => 'web'], $this->context)->isError);
        self::assertSame([], $this->servers->all());
    }

    public function testTheJournalSaysWhoRanEachCommand(): void {
        // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the participant in /audit
        $audit = new InMemoryCommandAuditRepository();
        $audit->append(['principal' => 7, 'run' => 3, 'server' => 'web', 'command' => 'uptime', 'class' => 'read', 'event' => 'done', 'exit' => 0, 'ms' => 5, 'error' => null]);
        $audit->names[7] = 'Аня';

        self::assertStringContainsString('Аня', (new ServerCommands($this->vault, $this->servers, $audit))->audit());
    }

    public function testTheOwnerRemovesAServerByCommand(): void {
        $commands = new ServerCommands($this->vault, $this->servers, new InMemoryCommandAuditRepository());

        self::assertStringContainsString('удалён', $commands->server('- web'));
        self::assertNull($this->servers->byName('web'));
        self::assertStringContainsString('нет', $commands->server('- web'));
    }
}
