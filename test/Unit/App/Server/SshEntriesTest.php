<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Server;

use OpenCCK\App\Server\SshEntries;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Test\Support\InMemoryMcpServerRepository;
use OpenCCK\Test\Support\InMemorySshHostKeyRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — our MCP is known by its installed code, not by the name of the
 * entry; a server of an entry is its arguments; the trusted host key goes to the process at its start
 */
final class SshEntriesTest extends TestCase {
    private InMemoryMcpServerRepository $servers;
    private InMemorySshHostKeyRepository $keys;
    private SshEntries $entries;

    protected function setUp(): void {
        $this->servers = new InMemoryMcpServerRepository();
        $this->keys = new InMemorySshHostKeyRepository();
        $sources = ['ssh' => 'https://github.com/opencck/mercurius-mcp-ssh', 'other' => 'https://github.com/someone/ssh-mcp'];
        $this->entries = new SshEntries($this->servers, static fn(string $install): ?string => $sources[$install] ?? null, $this->keys);
    }

    /** @param list<string> $args */
    private function entry(string $context, string $name, array $args, string $install = 'ssh', string $status = McpServer::CONNECTED): McpServer {
        $entry = ['command' => 'php', 'args' => ["/workspace/mcp/$install/current/bin/ssh-mcp", ...$args], 'env' => ['SSH_PASSWORD' => '{PASS}']];
        $this->servers->add($context, $name, 'stdio:php', null);
        $this->servers->setApproval($context, $name, 'h', 1, $entry);
        return $this->servers->update($context, $name, $status, 't') ?? self::fail();
    }

    public function testAnEntryOfOurInstallIsOursAndItsServerComesFromItsArguments(): void {
        $ours = $this->entry('member:1', 'base', ['--host=srv.example', '--user=root', '--port=2222', '--group=prod', '--jump-host=bastion', '--jump-user=ops']);
        $foreign = $this->entry('member:1', 'theirs', ['--host=a', '--user=b'], 'other');

        $target = $this->entries->target($ours);

        self::assertNotNull($target);
        self::assertSame(['member:1|base', 'base', 'srv.example:2222', 'root', 'prod', 'bastion:22', 'ops'], [$target->key, $target->name, $target->address(), $target->user, $target->group, $target->jumpAddress(), $target->jumpUser]);
        self::assertNull($this->entries->target($foreign), 'a third-party SSH MCP gets no wrapper');
    }

    public function testANameIsLookedForInTheContextOfTheRunThenInTheCallersOwn(): void {
        $this->entry('member:1', 'base', ['--host=own', '--user=u']);
        $this->entry('project:2', 'base', ['--host=project', '--user=u']);
        $this->entry('member:1', 'mine', ['--host=mine', '--user=u']);
        $this->entry('member:3', 'other', ['--host=other', '--user=u']);
        $this->entry('member:1', 'off', ['--host=off', '--user=u'], 'ssh', McpServer::DISABLED);

        self::assertSame('project', $this->entries->find('base', 1, 'project:2')?->host);
        self::assertSame('mine', $this->entries->find('MINE', 1, 'project:2')?->host);
        self::assertNull($this->entries->find('other', 1, 'project:2'), 'another member\'s entry is not the caller\'s');
        self::assertNull($this->entries->find('off', 1, null), 'an entry turned off is not used');
        self::assertSame(['base', 'mine'], array_map(static fn($t): string => $t->name, $this->entries->visible(1, 'member:1')));
    }

    public function testTheTrustedKeysGoToTheProcessAsArguments(): void {
        $server = $this->entry('member:1', 'base', ['--host=srv', '--user=u', '--jump-host=jump', '--jump-user=j', '--jump-port=2200']);
        $this->keys->pin('member:1|base', 'srv:22', 'SHA256:target');
        $this->keys->pin('member:1|base', 'jump:2200', 'SHA256:jump');

        self::assertSame(['--host-key=SHA256:target', '--jump-host-key=SHA256:jump'], $this->entries->keyArgs($server));
        self::assertSame([], $this->entries->keyArgs($this->entry('member:1', 'new', ['--host=n', '--user=u'])), 'no key trusted yet: none is given');
    }
}
