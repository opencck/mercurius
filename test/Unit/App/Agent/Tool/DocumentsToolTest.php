<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Files\DocumentsTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Media\DocsService;
use OpenCCK\Infrastructure\Services\ServiceModules;
use OpenCCK\Test\Support\FakeHttpClient;
use OpenCCK\Test\Support\FakeSandbox;

/**
 * The agent makes a docx, xlsx or pptx by a description: the media-docs module builds it, the file lands in the sandbox.
 */
final class DocumentsToolTest extends AsyncTestCase {
    private FakeSandbox $sandbox;
    private FakeHttpClient $http;

    /** @param array<string, array{int, array<non-empty-string, string>, string}> $responses */
    private function tool(array $responses): DocumentsTool {
        $this->sandbox = new FakeSandbox();
        $this->http = new FakeHttpClient($responses + ['http://media-docs:8092/health' => [200, [], '{"status":"ok"}']]);
        $client = new HttpClient($this->http, []);
        $modules = new ServiceModules($client);
        $modules->add(DocsService::MODULE, 'http://media-docs:8092', 'token-0123456789abcdef');
        $modules->check();
        return new DocumentsTool($this->sandbox, new DocsService($client, $modules));
    }

    private static function context(): ToolContext {
        return new ToolContext(3, 9, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    public function testBuildsADocumentIntoTheSandbox(): void {
        $tool = $this->tool(['http://media-docs:8092/build' => [200, [], 'PKDOCX']]);

        $result = $tool->execute(['path' => 'out/report.docx', 'description' => ['title' => 'Отчёт', 'blocks' => [['type' => 'paragraph', 'text' => 'Всё хорошо']]]], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertSame('PKDOCX', $this->sandbox->files[1]['out/report.docx'] ?? null);
        self::assertStringContainsString('out/report.docx', $result->content);
        $sent = json_decode($this->http->bodies[1], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertSame('docx', $sent['format'], 'the format comes from the extension');
        self::assertSame('Отчёт', $sent['title']);
        self::assertSame('media-docs', $tool->service());
        self::assertSame(DangerClass::Write, $tool->danger([]));
    }

    public function testTheModuleRefusesADescription(): void {
        $tool = $this->tool(['http://media-docs:8092/build' => [422, [], '{"error":"block 1: type is heading, paragraph"}']]);

        $result = $tool->execute(['path' => 'a.xlsx', 'description' => ['sheets' => []]], self::context());

        self::assertTrue($result->isError);
        self::assertStringContainsString('block 1', $result->content);
        self::assertSame([], $this->sandbox->files[1] ?? []);
    }

    public function testOnlyDocxXlsxPptx(): void {
        $tool = $this->tool([]);

        $result = $tool->execute(['path' => 'a.pdf', 'description' => []], self::context());

        self::assertTrue($result->isError);
        self::assertStringContainsString('docx', $result->content);
        self::assertCount(1, $this->http->requested, 'only the health check');
    }
}
