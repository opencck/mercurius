<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use Mercurius\Mcp\McpEndpoint;
use Amp\Cancellation;
use Amp\NullCancellation;
use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\App\Agent\Tool\ToolContext;
use Mercurius\Mcp\McpClient;
use OpenCCK\App\Mcp\McpTool;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;
use PHPUnit\Framework\TestCase;

/**
 * Files between the agent's sandbox and an MCP server: a file the server returns lands in inbox/, a path the agent
 * names goes to the server as its content.
 *
 * spec: brief/skills-mcp.md#почта-встроенный-mcp-сервер — an attachment of a letter in the sandbox and files of a letter from it
 */
final class McpFilesTest extends TestCase {
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /**
     * @param array<array-key, mixed> $schema
     * @param array{0: string, 1: bool, 2?: list<array{name: string, type: string, bytes: string}>} $answer
     */
    private function tool(FakeSandbox $sandbox, array $schema, array $answer): McpTool {
        $test = $this;
        $client = new class($test, $answer) implements McpClient {
            /** @param array{0: string, 1: bool, 2?: list<array{name: string, type: string, bytes: string}>} $answer */
            public function __construct(private readonly McpFilesTest $test, private readonly array $answer) {}

            public function tools(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [];
            }

            public function resources(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [];
            }

            public function readResource(McpEndpoint $server, ?string $token, string $uri, Cancellation $cancellation): string {
                return '';
            }

            public function call(McpEndpoint $server, ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
                $this->test->sent[] = $arguments;
                return $this->answer;
            }
        };
        $tool = new McpTool(new McpServer(1, 'mail', 'http://mail-mcp:8090/mcp', null, McpServer::ACTIVE, null), 'mail_x', 'mcp_mail_x', 'x', $schema, ['readOnlyHint' => true], $client, static fn(): ?string => null);
        $tool->useSandbox($sandbox, new DateTimeZone('UTC'), static fn(): DateTimeImmutable => new DateTimeImmutable('2026-11-19 12:00', new DateTimeZone('UTC')));
        return $tool;
    }

    private static function context(): ToolContext {
        $limits = new SandboxLimits(1_000_000_000, 64, 60, 0, 2);
        return new ToolContext(1, 1, new Principal(7, PrincipalKind::Human, Role::owner(), null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), $limits, true));
    }

    public function testAFileTheServerReturnsLandsInTheInbox(): void {
        $sandbox = new FakeSandbox();
        $tool = $this->tool($sandbox, ['type' => 'object'], ['Вложение письма 3: счёт.pdf.', false, [['name' => 'счёт.pdf', 'type' => 'application/pdf', 'bytes' => '%PDF']]]);

        $result = $tool->execute(['uid' => 3], self::context());

        self::assertFalse($result->isError);
        self::assertSame('%PDF', $sandbox->files[7]['inbox/2026-11-19/счёт.pdf'] ?? null);
        self::assertStringContainsString('inbox/2026-11-19/счёт.pdf', $result->content);
    }

    public function testFilesTheAgentNamesGoAsTheirContent(): void {
        $sandbox = new FakeSandbox();
        $sandbox->files[7]['report/отчёт.pdf'] = '%PDF-1.4';
        $schema = ['type' => 'object', 'properties' => ['to' => ['type' => 'array'], 'files' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'], 'mime_type' => ['type' => 'string'], 'content_base64' => ['type' => 'string'],
        ]]]]];
        $tool = $this->tool($sandbox, $schema, ['Письмо отправлено.', false]);

        $parameters = $tool->definition()->parameters;
        /** @var mixed $shown */
        $shown = is_array($parameters['properties'] ?? null) ? ($parameters['properties']['files'] ?? null) : null;
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 10], array_intersect_key(is_array($shown) ? $shown : [], ['type' => 1, 'items' => 1, 'maxItems' => 1]), 'the agent names paths');
        $result = $tool->execute(['to' => ['a@b.c'], 'files' => ['report/отчёт.pdf']], self::context());

        self::assertFalse($result->isError, $result->content);
        $sent = $this->sent[0] ?? [];
        self::assertSame([['name' => 'отчёт.pdf', 'mime_type' => 'application/pdf', 'content_base64' => base64_encode('%PDF-1.4')]], $sent['files'] ?? null);
        self::assertTrue($tool->execute(['to' => ['a@b.c'], 'files' => ['нет/такого.txt']], self::context())->isError, 'a missing file is an error, not an empty one');
    }
}
