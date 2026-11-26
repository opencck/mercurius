<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\App\Server\SshEntries;
use OpenCCK\App\Server\SshTarget;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Ssh\SshHostKey;
use Psr\Log\NullLogger;

/**
 * SSH entries of our MCP and a console over them; the process of an entry is a fake that records each call and gives
 * the answer set for it.
 */
final class SshFixture {
    public readonly InMemoryMcpServerRepository $servers;
    public readonly InMemorySshHostKeyRepository $keys;
    public readonly InMemoryCommandAuditRepository $audit;
    public readonly SshEntries $entries;
    /** @var list<array{string, array<string, mixed>}> the entry and the arguments of each call */
    public array $calls = [];
    /** @var list<array{SshTarget, SshHostKey, string, bool, bool}> */
    public array $asked = [];
    /** @var array{string, bool, array<array-key, mixed>|null} */
    public array $answer = ['exit 0', false, ['exit' => 0, 'stdout' => "ok\n", 'stderr' => '', 'cut' => false]];
    public ?\Throwable $failure = null;

    public function __construct() {
        $this->servers = new InMemoryMcpServerRepository();
        $this->keys = new InMemorySshHostKeyRepository();
        $this->audit = new InMemoryCommandAuditRepository();
        $this->entries = new SshEntries($this->servers, static fn(string $install): ?string => $install === 'ssh' ? 'https://github.com/opencck/mercurius-mcp-ssh' : null, $this->keys);
    }

    /** @param list<string> $args */
    public function entry(string $context, string $name, array $args): McpServer {
        $this->servers->add($context, $name, 'stdio:php', null);
        $this->servers->setApproval($context, $name, 'h', 1, ['command' => 'php', 'args' => ['/workspace/mcp/ssh/current/bin/ssh-mcp', ...$args], 'env' => ['SSH_PASSWORD' => '{PASS}']]);
        return $this->servers->update($context, $name, McpServer::CONNECTED, 't') ?? throw new \LogicException('no entry');
    }

    /** @param list<string> $confirmGroups */
    public function console(array $confirmGroups = []): ServerConsole {
        return new ServerConsole(
            $this->entries,
            new SshCommandClassifier(),
            $this->audit,
            function (SshTarget $target, array $args, Cancellation $cancellation): array {
                $this->calls[] = [$target->key, $args];
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                return $this->answer;
            },
            $this->keys,
            function (SshTarget $target, SshHostKey $row, string $fingerprint, bool $changed, bool $jump): void {
                $this->asked[] = [$target, $row, $fingerprint, $changed, $jump];
            },
            new NullLogger(),
            $confirmGroups,
        );
    }

    /** @return list<string> the commands each call ran */
    public function commands(): array {
        $commands = [];
        foreach ($this->calls as [, $args]) {
            $commands[] = isset($args['command']) && is_string($args['command']) ? $args['command'] : '';
        }
        return $commands;
    }
}
