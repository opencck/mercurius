<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mail;

use OpenCCK\App\Mail\MailEntries;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Test\Support\InMemoryMcpServerRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов — a mailbox is an entry of .mcp.json of our mail MCP, known by
 * its installed code; a call finds it in the context of the run, then among the caller's own
 */
final class MailEntriesTest extends TestCase {
    private InMemoryMcpServerRepository $servers;
    private MailEntries $entries;

    protected function setUp(): void {
        $this->servers = new InMemoryMcpServerRepository();
        $sources = ['mail' => 'https://github.com/opencck/mercurius-mail-mcp', 'other' => 'https://example.com/mail.git'];
        $this->entries = new MailEntries($this->servers, static fn(string $install): ?string => $sources[$install] ?? null);
    }

    /** @param list<string> $args */
    private function entry(string $context, string $name, array $args, string $install = 'mail'): McpServer {
        $this->servers->add($context, $name, 'stdio:php', null);
        $this->servers->setApproval($context, $name, 'h', 1, ['command' => 'php', 'args' => ["/workspace/mcp/$install/current/bin/mail-mcp-stdio", ...$args], 'env' => ['MAIL_PASSWORD' => '{PASS}']]);
        return $this->servers->update($context, $name, McpServer::CONNECTED, 't') ?? self::fail();
    }

    public function testAnEntryOfOurMailIsAMailbox(): void {
        $box = $this->entries->target($this->entry('member:1', 'work', ['--host=mail.example.com', '--user=me@example.com', '--from=Me <me@example.com>']));

        self::assertNotNull($box);
        self::assertSame(['member:1|work', 'work', 'mail.example.com', 'me@example.com', 'Me <me@example.com>'], [$box->key, $box->name, $box->host, $box->user, $box->from]);
        self::assertNull($this->entries->target($this->entry('member:1', 'theirs', ['--host=a', '--user=b'], 'other')));
    }

    private static function host(\OpenCCK\App\Mail\MailTarget|string $box): ?string {
        return $box instanceof \OpenCCK\App\Mail\MailTarget ? $box->host : null;
    }

    public function testTheMailboxOfACallIsNamedOrTheOnlyOne(): void {
        $this->entry('member:1', 'own', ['--host=own.example', '--user=u']);
        $this->entry('project:2', 'team', ['--host=team.example', '--user=t']);

        self::assertSame('team.example', self::host($this->entries->pick(1, 'project:2', null)), 'with no name: the mailbox of the project first');
        self::assertSame('team.example', self::host($this->entries->pick(1, 'project:2', 'TEAM')));
        self::assertSame('own.example', self::host($this->entries->pick(1, 'project:2', 'own')));
        self::assertSame('own.example', self::host($this->entries->pick(1, null, null)), 'the only one of a private chat');
        self::assertIsString($this->entries->pick(3, null, null), 'none');
        self::assertIsString($this->entries->pick(1, 'project:2', 'nope'));
    }
}
