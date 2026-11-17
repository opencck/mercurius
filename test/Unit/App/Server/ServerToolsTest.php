<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Server;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\AddressGuard;
use OpenCCK\App\Agent\Tool\FileWriteTool;
use OpenCCK\App\Agent\Tool\SqlQueryTool;
use OpenCCK\App\Agent\Tool\TcpProbeTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Server\Connection;
use OpenCCK\App\Server\RemoteResult;
use OpenCCK\App\Server\RemoteShell;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\App\Service\ServerCommands;
use OpenCCK\App\Vault\Vault;
use OpenCCK\CommandPolicy\CommandClassifier;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryCommandAuditRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemoryServerRepository;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

final class ServerToolsTest extends AsyncTestCase {
    private InMemoryCommandAuditRepository $audit;
    private Vault $vault;
    private Principal $owner;
    private ServerConsole $console;
    /** @var list<array{string, ?string}> command, stdin */
    public array $ran = [];

    protected function setUp(): void {
        parent::setUp();
        $servers = new InMemoryServerRepository();
        $this->audit = new InMemoryCommandAuditRepository();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->owner = new Principal(1, PrincipalKind::Human, Role::Owner, null);
        $servers->add('db', '10.0.0.7', 22, 'ops', $this->vault->put('ops-pass', 'password', 'login-password-1', $this->owner), 'prod');
        $this->vault->put('pg', 'password', 'pg-password-1', $this->owner);
        $test = $this;
        $this->console = new ServerConsole($servers, $this->vault, new CommandClassifier(), $this->audit, new class($test) implements RemoteShell {
            public function __construct(private readonly ServerToolsTest $test) {}

            public function run(Connection $target, string $command, int $timeout, int $maxOutput, Cancellation $cancellation, ?string $stdin = null): RemoteResult {
                $this->test->ran[] = [$command, $stdin];
                return new RemoteResult(0, "id\tname\n1\tfoo\n", '', false, 3);
            }
        }, static function (): void {
        }, new NullLogger());
    }

    private function context(): ToolContext {
        return new ToolContext(5, 1, $this->owner, new NullCancellation());
    }

    public function testAReadRunsInAReadOnlyTransactionWithThePasswordOnStdin(): void {
        $tool = new SqlQueryTool($this->console, $this->vault);
        $args = ['server' => 'db', 'engine' => 'postgres', 'database' => 'shop', 'user' => 'app', 'secret' => 'pg', 'query' => 'select id, name from users limit 1;'];

        self::assertSame(DangerClass::Read, $tool->danger($args));
        $result = $tool->execute($args, $this->context());

        self::assertFalse($result->isError);
        self::assertStringContainsString("1\tfoo", $result->content);
        [$command, $stdin] = $this->ran[0];
        self::assertStringStartsWith('IFS= read -r PGPASSWORD; export PGPASSWORD; exec psql ', $command);
        self::assertStringNotContainsString('pg-password-1', $command, 'never on the command line');
        self::assertSame("pg-password-1\nBEGIN READ ONLY;\nselect id, name from users limit 1;\nROLLBACK;\n", $stdin);
        self::assertSame('sql postgres app@127.0.0.1/shop: select id, name from users limit 1', $this->audit->records[0]['command']);
        self::assertSame('read', $this->audit->records[0]['class']);
    }

    public function testAReadCannotLeaveItsTransaction(): void {
        $tool = new SqlQueryTool($this->console, $this->vault);
        $base = ['server' => 'db', 'engine' => 'mysql', 'database' => 'shop', 'user' => 'app'];

        foreach (['select 1; delete from users', 'commit', 'SET SESSION TRANSACTION READ WRITE', 'select pg_terminate_backend(42)', 'select sleep(100)'] as $query) {
            self::assertTrue($tool->execute($base + ['query' => $query], $this->context())->isError, $query);
        }
        self::assertSame([], $this->ran);
        self::assertTrue($tool->execute(['query' => 'select 1', 'database' => 'shop; rm -rf /'] + $base, $this->context())->isError, 'names are checked');
    }

    public function testAWriteWaitsForTheOwnerAndRunsAsIs(): void {
        $tool = new SqlQueryTool($this->console, $this->vault);
        $args = ['server' => 'db', 'engine' => 'mysql', 'database' => 'shop', 'user' => 'app', 'query' => 'update users set name = \'x\' where id = 1', 'write' => true];

        self::assertSame(DangerClass::Irreversible, $tool->danger($args));
        $tool->execute($args, $this->context());

        self::assertStringStartsWith('exec mysql -h 127.0.0.1 -u app -D shop', $this->ran[0][0]);
        self::assertSame("update users set name = 'x' where id = 1;\n", $this->ran[0][1]);
        self::assertSame('change', $this->audit->records[0]['class']);
    }

    public function testAFileGoesOnStdinOfCatAndForbiddenPlacesAreRefused(): void {
        $tool = new FileWriteTool($this->console, new CommandClassifier());

        $result = $tool->execute(['server' => 'db', 'path' => "/etc/app/it's.conf", 'content' => "a=1\n"], $this->context());

        self::assertFalse($result->isError);
        self::assertSame(["cat > '/etc/app/it'\\''s.conf'", "a=1\n"], $this->ran[0]);
        self::assertSame("file write /etc/app/it's.conf (4 байт)", $this->audit->records[0]['command']);
        self::assertTrue($tool->execute(['server' => 'db', 'path' => '/root/.ssh/authorized_keys', 'content' => 'k', 'append' => true], $this->context())->isError);
        self::assertTrue($tool->execute(['server' => 'db', 'path' => "/tmp/a\nb", 'content' => 'k'], $this->context())->isError);
        self::assertCount(1, $this->ran);
    }

    public function testTheProbeDoesNotMapInternalNetworks(): void {
        $tool = new TcpProbeTool(new AddressGuard(static fn(string $host): array => ['10.0.0.5']));

        self::assertTrue($tool->execute(['host' => 'internal.example.com', 'port' => 22], $this->context())->isError);
        self::assertTrue($tool->execute(['host' => 'postgres', 'port' => 5432], $this->context())->isError);
    }

    public function testASecretPastedIntoAMessageIsKeptAndHiddenFromTheAgent(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('ок')]));
        $stack->useServers(new ServerCommands($this->vault, new InMemoryServerRepository(), $this->audit));

        $stack->say('добавь сервер, вот ключ sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef');
        $stack->scheduler->awaitIdle();

        self::assertCount(1, $stack->channel->deleted);
        $last = array_slice($stack->llm->requests[0]->messages, -1)[0] ?? null;
        self::assertNotNull($last);
        $seen = $last->content;
        self::assertStringNotContainsString('0123456789abcdef0123', $seen);
        self::assertMatchesRegularExpression('/в хранилище секретов как «msg-\d+»/u', $seen);
        $name = 'msg-' . $stack->channel->deleted[0][1];
        self::assertStringContainsString('0123456789abcdef0123', (string) $this->vault->reveal($name, $this->owner));
    }
}
