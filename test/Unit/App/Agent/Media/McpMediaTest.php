<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Media;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Mcp\McpClient;
use Mercurius\Mcp\McpEndpoint;
use Mercurius\Mcp\McpServerInfo;
use OpenCCK\App\Agent\Model\ModelCatalogView;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\App\Agent\Model\ProviderRegistry;
use OpenCCK\App\Agent\Tool\Files\MediaMakeTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\App\Mcp\McpModelProviders;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Infrastructure\Media\MediaCall;
use OpenCCK\Infrastructure\Media\MediaModels;
use OpenCCK\Infrastructure\Media\McpMedia;
use OpenCCK\Test\Support\FakeHttpClient;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryMcpServerRepository;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\InMemorySecretRepository;
use Psr\Log\NullLogger;

/**
 * The models of a provider of an MCP stand in the categories of media: a picture, speech and a video are made by the
 * tools of its server with the fixed arguments of their kind, a picture is read the same way, a long call is waited
 * for, a quota takes the model off the choice, and every call is in the spend.
 *
 * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
 */
final class McpMediaTest extends AsyncTestCase {
    /** @var \ArrayObject<int, array{string, array<string, mixed>}> */
    private \ArrayObject $calls;
    private InMemoryLlmCallRepository $spend;
    private ProviderPauses $pauses;
    private MediaModels $media;
    private MediaSettings $settings;

    protected function setUp(): void {
        parent::setUp();
        $this->calls = new \ArrayObject();
        $calls = $this->calls;
        $client = new class ($calls) implements McpClient, McpServerInfo {
            private int $polls = 0;

            /** @param \ArrayObject<int, array{string, array<string, mixed>}> $calls */
            public function __construct(private readonly \ArrayObject $calls) {}

            public function serverCapabilities(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return ['experimental' => ['mercurius.models' => ['version' => 1]]];
            }

            public function tools(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return array_map(static fn(string $n): array => ['name' => $n, 'description' => $n, 'schema' => ['type' => 'object'], 'annotations' => []], ['models_list', 'image', 'speech', 'video_start', 'video_result', 'analyze']);
            }

            public function resources(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [];
            }

            public function readResource(McpEndpoint $server, ?string $token, string $uri, Cancellation $cancellation): string {
                return '';
            }

            public function call(McpEndpoint $server, ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
                if ($tool === 'models_list') {
                    return ['', false, [], ['models' => [
                        ['id' => 'pic', 'kind' => 'image', 'tool' => 'image', 'price' => ['unit' => 'call', 'usd' => 0.04]],
                        ['id' => 'busy-pic', 'kind' => 'image', 'tool' => 'image'],
                        ['id' => 'tts', 'kind' => 'voice', 'tool' => 'speech'],
                        ['id' => 'omni', 'kind' => 'film', 'tool' => 'video_start', 'result_tool' => 'video_result'],
                        ['id' => 'eye', 'kind' => 'vision', 'tool' => 'analyze'],
                    ]]];
                }
                $this->calls->append([$tool, $arguments]);
                return match ($tool) {
                    'image' => ($arguments['model'] ?? '') === 'busy-pic'
                        ? ['server_error (exit 1): HTTP status: 429 You exceeded your current quota', true, [], null]
                        : ['a.png', false, [['name' => 'a.png', 'type' => 'image/png', 'bytes' => 'PNG']], null],
                    'speech' => ['s.wav', false, [['name' => 's.wav', 'type' => 'audio/wav', 'bytes' => 'WAV']], null],
                    'video_start' => ["id: op-17\nstatus: started", false, [], null],
                    'video_result' => ++$this->polls < 2 ? ['not ready', false, [], ['done' => false]] : ['v.mp4', false, [['name' => 'v.mp4', 'type' => 'video/mp4', 'bytes' => 'MP4']], null],
                    'analyze' => ['На картинке кот.', false, [], null],
                    default => ['?', true, [], null],
                };
            }
        };
        $servers = new InMemoryMcpServerRepository();
        $registry = new ProviderRegistry(ModelCatalogView::KNOWN);
        $owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $manager = new McpManager($servers, $client, new Vault(new InMemorySecretRepository(), random_bytes(32)), new ToolRegistry(), static fn(string $c): Member => $owner, static function (string $t): void {
        }, new NullLogger());
        $providers = new McpModelProviders($registry, new InMemoryModelStores(), new NullLogger());
        $manager->useModelProviders($providers);
        $servers->add('owners', 'gem', 'https://gem.example/mcp', null);
        $manager->approve('owners', 'gem', new NullCancellation());
        $this->settings = MediaSettings::fromEnv([]);
        $this->settings->follow(static fn(string $kind): array => ['image' => ['mcp-gem:busy-pic', 'mcp-gem:pic'], 'voice' => ['mcp-gem:tts', 'openrouter:a/b'], 'film' => ['mcp-gem:omni'], 'vision' => ['mcp-gem:eye']][$kind] ?? []);
        $this->spend = new InMemoryLlmCallRepository();
        $this->media = new MediaModels(new HttpClient(new FakeHttpClient([]), []), null, null, $this->settings, static fn(string $n): ?string => null, $this->spend);
        $this->pauses = new ProviderPauses(null, static fn(): int => 1000);
        $this->media->useMcp(new McpMedia($providers, $this->pauses));
    }

    private static function context(): ToolContext {
        return new ToolContext(3, 9, new Member(1, MemberKind::Human, Role::admin(), null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    public function testAPictureIsDrawnByTheToolOfTheServerAndAQuotaTakesTheModelOff(): void {
        $call = new MediaCall(3, 9, new NullCancellation());

        $busy = $this->media->draw('mcp-gem:busy-pic', 'кот', '1:1', $call);
        $drawn = $this->media->draw('mcp-gem:pic', 'кот', '1:1', $call);

        self::assertSame([], $busy->images);
        self::assertNotNull($this->pauses->blocking(\OpenCCK\Domain\Agent\ModelRef::parse('mcp-gem:busy-pic')), 'a quota takes the model off the choice');
        self::assertSame('PNG', $drawn->images[0]->bytes ?? null);
        self::assertSame(['image', ['model' => 'pic', 'prompt' => 'кот', 'aspect_ratio' => '1:1']], $this->calls[1] ?? null, 'the fixed arguments of the kind and the model');
        self::assertSame(['mcp-gem:busy-pic', 'mcp-gem:pic'], array_map(static fn($c): string => $c->model, $this->spend->calls), 'every call is in the spend');
        self::assertSame(0.04, $this->spend->calls[1]->costUsd ?? null, 'the price of a call from the catalog of the server');
        self::assertStringContainsString('кот', (string) $this->media->look('mcp-gem:eye', [['bytes' => 'PNG', 'mime' => 'image/png']], 'что тут?', $call)->text);
    }

    public function testSpeechAndAVideoAreMadeByTheirToolsAndSavedInTheSandbox(): void {
        $sandbox = new FakeSandbox();
        $voice = new MediaMakeTool($sandbox, $this->media, $this->settings, 'voice');
        $film = new MediaMakeTool($sandbox, $this->media, $this->settings, 'film');

        self::assertTrue($voice->available());
        self::assertSame(['mcp-gem:tts'], $this->settings->models('voice'), 'only models of providers of MCP make speech');
        $spoken = $voice->execute(['text' => 'привет', 'name' => 'hello'], self::context());
        $made = $film->execute(['prompt' => 'кот бежит', 'name' => 'cat'], self::context());

        self::assertFalse($spoken->isError, $spoken->content);
        self::assertSame('WAV', $sandbox->files[1]['audio/hello.wav'] ?? null);
        self::assertFalse($made->isError, $made->content);
        self::assertSame('MP4', $sandbox->files[1]['video/cat.mp4'] ?? null, 'the result of a long call is waited for');
        self::assertSame(['video_result', ['id' => 'op-17']], $this->calls[2] ?? null);
        self::assertSame(DangerClass::Irreversible, $film->danger([]), 'a video is asked of the owner');
        self::assertSame(DangerClass::Write, $voice->danger([]));
    }
}
