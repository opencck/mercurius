<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\App\Service\ClaudeCommands;
use OpenCCK\Infrastructure\Llm\Claude\ClaudeCatalog;
use OpenCCK\Infrastructure\Llm\Claude\ClaudeSubscription;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use Psr\Log\NullLogger;

use function Amp\ByteStream\buffer;

/**
 * /claude speaks with the module claude-code: the state of the sign-in and the plan, a sign-in by a link and a code,
 * a sign-out. The code reaches the module and nothing else.
 */
final class ClaudeCommandsTest extends AsyncTestCase {
    /** @var array<string, array{int, array<string, mixed>}> path => the answer of the module */
    private array $answers = [];
    /** @var list<array{string, string, string, ?string}> method, path, body, authorization */
    private array $asked = [];
    private bool $reloaded = false;

    private function http(): HttpClient {
        return new HttpClient(new class ($this) implements DelegateHttpClient {
            public function __construct(private readonly ClaudeCommandsTest $test) {}

            public function request(Request $request, Cancellation $cancellation): Response {
                return $this->test->answer($request);
            }
        }, []);
    }

    /** @internal the module as the test scripts it */
    public function answer(Request $request): Response {
        $path = $request->getUri()->getPath();
        $this->asked[] = [$request->getMethod(), $path, buffer($request->getBody()->getContent()), $request->getHeader('authorization')];
        [$status, $data] = $this->answers[$request->getMethod() . ' ' . $path] ?? [503, ['type' => 'error']];
        return new Response('1.1', $status, null, ['content-type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR), $request);
    }

    private function commands(ProviderPauses $pauses = new ProviderPauses(), bool $token = false): ClaudeCommands {
        $subscription = new ClaudeSubscription($this->http(), 'http://claude-code:8094/', 'module-token-0123456789');
        return new ClaudeCommands($subscription, static fn(): bool => $token, static fn(): string => 'stop', $pauses, new NullLogger(), function (): void {
            $this->reloaded = true;
        });
    }

    private function status(string $auth, bool $pending = false): void {
        $this->answers['GET /auth/status'] = [200, ['cli' => '2.1.330', 'usable' => true, 'auth' => $auth, 'plan' => $auth === 'none' ? '' : 'max', 'detail' => '', 'login_pending' => $pending]];
    }

    public function testTheStateSaysHowTheWorkGoesInAndHowThePlanStands(): void {
        $this->status('login');
        $this->answers['GET /usage'] = [200, ['rate_limits' => ['limits' => [['kind' => 'session', 'percent' => 64, 'resets_at' => '2030-01-01T05:00:00Z'], ['kind' => 'weekly_all', 'percent' => 12.4]]], 'extra_usage' => ['using' => false, 'resets_at' => null]]];
        $pauses = new ProviderPauses();
        $pauses->pause('claude', time() + 3600, ProviderPauses::LIMIT);

        $text = $this->commands($pauses)->handle('');

        self::assertStringContainsString('max', $text);
        self::assertStringContainsString('session 64% (2030-01-01T05:00:00Z), weekly_all 12%', $text);
        self::assertStringContainsString('stop', $text);
        self::assertStringContainsString('claude:', $text, 'the pause of the models is named');
        self::assertSame('Bearer module-token-0123456789', $this->asked[0][3]);
        self::assertSame('/auth/status', $this->asked[0][1], 'the base address lost its trailing slash');
    }

    public function testASignInGoesByALinkAndACodeAndLoadsTheModels(): void {
        $this->answers['POST /auth/login'] = [200, ['url' => 'https://claude.ai/oauth/authorize?code=true&state=s', 'wait_seconds' => 900]];
        $this->answers['POST /auth/login/code'] = [200, ['auth' => 'login', 'plan' => 'max']];
        $pauses = new ProviderPauses();
        $pauses->pause('claude', time() + 600, ProviderPauses::SIGN_IN);
        $commands = $this->commands($pauses);

        self::assertStringContainsString('https://claude.ai/oauth/authorize?code=true&state=s', $commands->handle('login'));
        self::assertTrue(ClaudeCommands::carriesSecret(' code abc#def'));
        self::assertFalse(ClaudeCommands::carriesSecret('login'));
        $done = $commands->handle('code  abc#def ');

        self::assertStringContainsString('claude:', $done);
        self::assertSame(['POST', '/auth/login/code', '{"code":"abc#def"}'], array_slice($this->asked[1], 0, 3));
        self::assertTrue($this->reloaded, 'the models of the subscription load at once');
        self::assertFalse($pauses->paused('claude'), 'a new sign-in brings the models back');
    }

    public function testARefusedCodeAndAModuleThatDoesNotAnswerAreSaidPlainly(): void {
        $this->answers['POST /auth/login/code'] = [422, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'no']]];
        $commands = $this->commands();

        self::assertStringContainsString('/claude login', $commands->handle('code wrong'));
        self::assertStringContainsString('/claude code', $commands->handle('code'));
        self::assertFalse($this->reloaded);
        self::assertStringContainsString('claude-code', $commands->handle(''), 'no answer of the module: the module is named');
    }

    public function testATokenWinsOverTheSignInAndTheModelsCostNothing(): void {
        $this->status('token');
        $this->answers['GET /usage'] = [503, ['type' => 'error']];
        self::assertStringContainsString('CLAUDE_CODE_OAUTH_TOKEN', $this->commands(token: true)->handle(''));

        $models = (new ClaudeCatalog(static fn(): array => [new ModelInfo('claude-sonnet-5-5', 'Claude Sonnet 5.5', 1_000_000, 0.000002, 0.00001, ['text', 'image'], ['text'], ['tools', 'reasoning'], 128_000, 0.0000001, 0.0000025)]))->fetch();
        self::assertSame([0.0, 0.0, 0.0, 0.0], [$models[0]->inputPricePerToken, $models[0]->outputPricePerToken, $models[0]->cacheReadPricePerToken, $models[0]->cacheWritePricePerToken]);
        self::assertSame([1_000_000, ['tools', 'reasoning']], [$models[0]->contextLength, $models[0]->supportedParameters]);
    }
}
