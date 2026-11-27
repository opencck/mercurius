<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\App\Service\AntigravityCommands;
use OpenCCK\Infrastructure\Llm\Antigravity\AntigravitySubscription;
use OpenCCK\Infrastructure\Llm\ProviderRouter;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\ByteStream\buffer;
use function Mercurius\I18n\t;

/**
 * /antigravity speaks with the module antigravity: the state of the plan and its quota, a sign-in that starts only
 * after the owner read what the terms of Google say, the code that reaches the module and nothing else, and the
 * models taken off the choice until the owner acts.
 *
 * spec: brief/models.md#google-по-подписке
 */
final class AntigravityCommandsTest extends AsyncTestCase {
    /** @var array<string, array{int, array<string, mixed>}> path => the answer of the module */
    private array $answers = [];
    /** @var list<array{string, string, string, ?string}> method, path, body, authorization */
    private array $asked = [];
    private bool $reloaded = false;

    private function http(): HttpClient {
        return new HttpClient(new class ($this) implements DelegateHttpClient {
            public function __construct(private readonly AntigravityCommandsTest $test) {}

            public function request(Request $request, Cancellation $cancellation): Response {
                return $this->test->answer($request);
            }
        }, []);
    }

    /** @internal the module as the test scripts it */
    public function answer(Request $request): Response {
        $path = $request->getUri()->getPath();
        $this->asked[] = [$request->getMethod(), $path, buffer($request->getBody()->getContent()), $request->getHeader('authorization')];
        [$status, $data] = $this->answers[$request->getMethod() . ' ' . $path] ?? [503, ['error' => ['message' => 'x']]];
        return new Response('1.1', $status, null, ['content-type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR), $request);
    }

    private function subscription(): AntigravitySubscription {
        return new AntigravitySubscription($this->http(), 'http://antigravity:8095/', 'module-token-0123456789');
    }

    private function commands(ProviderPauses $pauses = new ProviderPauses()): AntigravityCommands {
        return new AntigravityCommands($this->subscription(), $pauses, new NullLogger(), function (): void {
            $this->reloaded = true;
        });
    }

    private function status(string $auth, bool $pending = false): void {
        $this->answers['GET /auth/status'] = [200, ['cli' => '1.5.18', 'usable' => true, 'auth' => $auth, 'detail' => '', 'login_pending' => $pending]];
    }

    public function testTheStateSaysWhetherThePlanIsSignedInAndHowItsQuotaStands(): void {
        $this->status('login');
        $this->answers['GET /usage'] = [200, ['stale' => false, 'groups' => [
            ['name' => 'Gemini Models', 'buckets' => [['window' => '5h', 'remaining' => 0.25, 'resets_at' => 1893474000], ['window' => 'weekly', 'remaining' => 0.9, 'resets_at' => null]]],
            ['name' => "Claude <b>and</b> GPT\nmodels", 'buckets' => []],
        ]]];
        $pauses = new ProviderPauses();
        $pauses->pause('antigravity:gemini-3.8-flash', time() + 3600, ProviderPauses::LIMIT);

        $text = $this->commands($pauses)->handle('');

        self::assertStringContainsString('1.5.18', $text);
        self::assertStringContainsString('• Gemini Models: 5h 75% (2030-01-01 05:00 UTC), weekly 10%', $text);
        self::assertStringContainsString('• Claude bandb GPTmodels: —', $text, 'a name of a group is shown by its plain characters');
        self::assertStringContainsString('antigravity:', $text, 'the model off the choice is told');
        self::assertSame('Bearer module-token-0123456789', $this->asked[0][3]);

        $this->status('none');
        self::assertStringContainsString('/antigravity login', $this->commands()->handle(''));
        $this->answers = [];
        self::assertStringContainsString('antigravity', $this->commands()->handle(''), 'a module that does not answer is named');
    }

    public function testASignInStartsOnlyAfterTheOwnerReadWhatTheTermsSay(): void {
        $this->status('none');
        $this->answers['POST /auth/login'] = [200, ['url' => 'https://accounts.google.com/o/oauth2/auth?x=1', 'wait_seconds' => 60]];

        $warning = $this->commands()->handle('login');

        self::assertStringContainsString(AntigravityCommands::TERMS, $warning);
        self::assertStringContainsString('/antigravity login accept', $warning);
        self::assertSame([], array_filter($this->asked, static fn(array $a): bool => $a[1] === '/auth/login'), 'no link before the owner agreed');

        $link = $this->commands()->handle('login accept');

        self::assertStringContainsString('https://accounts.google.com/o/oauth2/auth?x=1', $link);
        self::assertStringContainsString('60', $link, 'the owner is told how short the wait is');

        $this->answers['POST /auth/login'] = [200, ['url' => 'https://evil.example/login']];
        self::assertStringNotContainsString('evil.example', $this->commands()->handle('login accept'), 'only a link of Google is passed on');
    }

    public function testTheCodeReachesTheModuleAndTheModelsLoadAtOnce(): void {
        $this->answers['POST /auth/code'] = [200, ['auth' => 'login']];
        $pauses = new ProviderPauses();
        $pauses->pauseFor('antigravity', 600, ProviderPauses::SIGN_IN);
        self::assertTrue(AntigravityCommands::carriesSecret('code 4/0Ab-secret'));
        self::assertFalse(AntigravityCommands::carriesSecret('login accept'));

        $text = $this->commands($pauses)->handle('code 4/0Ab-secret');

        self::assertStringNotContainsString('4/0Ab-secret', $text);
        self::assertSame(['POST', '/auth/code', '{"code":"4\/0Ab-secret"}'], array_slice($this->asked[0], 0, 3));
        self::assertTrue($this->reloaded);
        self::assertFalse($pauses->paused('antigravity'));

        $this->answers['POST /auth/code'] = [422, ['error' => ['message' => 'no']]];
        $calls = count($this->asked);
        self::assertStringContainsString('/antigravity login accept', $this->commands()->handle('code wrong'));
        self::assertCount($calls + 1, $this->asked);
        self::assertStringContainsString('/antigravity code', $this->commands()->handle('code'));
    }

    public function testTheOwnerSignsOutToSignInAgainWithThisAccountOrAnother(): void {
        // spec: brief/models.md#google-по-подписке — seen live: Google refused the account, and a new sign-in answered
        //       only that it had failed: the plan was signed in already
        $this->status('login');
        $pauses = new ProviderPauses();
        $pauses->pauseFor('antigravity', 3600, ProviderPauses::ACCOUNT);

        $again = $this->commands($pauses)->handle('login accept');
        self::assertStringContainsString('/antigravity logout', $again, 'a plan signed in already says how to sign in anew');
        self::assertSame([], array_filter($this->asked, static fn(array $a): bool => $a[1] === '/auth/login'), 'and asks the module for no link');

        $this->answers['POST /auth/logout'] = [200, ['auth' => 'none']];
        $out = $this->commands($pauses)->handle('logout');
        self::assertSame(t('antigravity.logged_out'), $out);
        self::assertFalse($pauses->paused('antigravity'), 'a pause of the old account does not stand for the new one');

        $this->answers['POST /auth/logout'] = [503, ['error' => ['message' => 'no']]];
        self::assertSame(t('antigravity.logout_failed'), $this->commands()->handle('logout'));
    }

    public function testTheModelsOfThePlanCostNothingAndCarryTheWindowOfOneMessage(): void {
        $this->answers['GET /v1/models'] = [200, ['data' => [
            ['id' => 'gemini-3.8-flash', 'efforts' => ['low', 'medium', 'high'], 'context_window' => 60000, 'input' => ['text'], 'quota_group' => 'Gemini Models'],
            ['id' => 'claude-sonnet-4-6', 'efforts' => [], 'context_window' => 120000, 'input' => ['text']],
            ['id' => '--evil', 'efforts' => [], 'context_window' => 1],
        ]]];

        $models = $this->subscription()->models();

        self::assertSame(['gemini-3.8-flash', 'claude-sonnet-4-6'], array_map(static fn($m): string => $m->id, $models));
        self::assertSame([60000, 0.0, 0.0], [$models[0]->contextLength, $models[0]->inputPricePerToken, $models[0]->outputPricePerToken]);
        self::assertTrue($models[0]->supportsTools());
        self::assertTrue($models[0]->supports('reasoning'));
        self::assertFalse($models[1]->supports('reasoning'), 'a model without levels takes no effort');
        $this->answers = [];
        $this->expectException(\RuntimeException::class);
        $this->subscription()->models();
    }

    public function testAPlanWhoseProviderAsksTheOwnerToActIsOffUntilTheOwnerPutsItBack(): void {
        // spec: brief/models.md#google-по-подписке — a 403 of the module is not a lost sign-in asked again in ten minutes
        $told = [];
        $pauses = new ProviderPauses(static function (string $provider, string $why) use (&$told): void {
            $told[] = [$provider, $why];
        });
        $llm = new ScriptedLlm([new LlmException(ErrorKind::Auth, 'Antigravity (403): Google asks the owner of the account to act', 403)]);
        $router = new ProviderRouter(['antigravity' => $llm], null, $pauses);
        $request = new ChatRequest('antigravity:gemini-3.8-flash', [Message::user('x')]);

        foreach ([1, 2] as $try) {
            try {
                $router->complete($request);
                self::fail('expected a refusal');
            } catch (LlmException $e) {
                self::assertSame(403, $e->status, 'try ' . $try);
            }
        }

        self::assertCount(1, $llm->requests, 'the plan is not called again');
        self::assertSame([['antigravity', ProviderPauses::ACCOUNT]], $told, 'the owner hears once');
        $pause = $pauses->of('antigravity');
        self::assertNotNull($pause);
        self::assertSame(ProviderPauses::ACCOUNT, $pause['why']);
        self::assertGreaterThan(time() + 86400, $pause['until']);

        $this->status('login');
        $this->answers['GET /usage'] = [200, ['stale' => false, 'groups' => []]];
        self::assertStringContainsString('/antigravity resume', $this->commands($pauses)->handle(''));
        $this->commands($pauses)->handle('resume');
        self::assertFalse($pauses->paused('antigravity'));
    }
}
