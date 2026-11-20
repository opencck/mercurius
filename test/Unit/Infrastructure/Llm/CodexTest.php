<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Llm;

use Amp\Cancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use OpenCCK\App\Service\CodexCommands;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Infrastructure\Llm\ChatGpt\CodexAccount;
use OpenCCK\Infrastructure\Llm\ChatGpt\CodexCatalog;
use OpenCCK\Infrastructure\Llm\ChatGpt\CodexClient;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use Psr\Log\NullLogger;

use function Amp\ByteStream\buffer;
use function Amp\delay;
use function Mercurius\I18n\t;

/**
 * spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — Codex through the owner's ChatGPT subscription
 */
final class CodexTest extends AsyncTestCase {
    private Vault $vault;
    private Principal $owner;

    protected function setUp(): void {
        parent::setUp();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->owner = (new InMemoryPrincipalRepository())->ensureOwner('telegram', '42', 'Owner');
    }

    /**
     * A JWT whose claims are $claims; the signature is not checked here.
     *
     * @param array<string, mixed> $claims
     */
    private static function jwt(array $claims): string {
        $part = static fn(array $data): string => rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $part(['alg' => 'none']) . '.' . $part($claims) . '.sig';
    }

    private function account(CodexHttpFake $http): CodexAccount {
        $owner = $this->owner;
        return new CodexAccount(new HttpClient($http, []), $this->vault, static fn(): Principal => $owner);
    }

    public function testADeviceCodeSignInKeepsTheTokensInTheVault(): void {
        $access = self::jwt(['exp' => time() + 3600, 'https://api.openai.com/auth' => ['chatgpt_account_id' => 'acc-1']]);
        $http = new CodexHttpFake([
            'POST https://auth.openai.com/api/accounts/deviceauth/usercode' => [[200, '{"user_code":"ABCD-1234","device_auth_id":"dev-1","interval":"1"}']],
            'POST https://auth.openai.com/api/accounts/deviceauth/token' => [[403, '{}'], [200, '{"authorization_code":"code-1","code_verifier":"ver-1"}']],
            'POST https://auth.openai.com/oauth/token' => [[200, json_encode(['access_token' => $access, 'refresh_token' => 'ref-1'], JSON_THROW_ON_ERROR)]],
        ]);
        $account = $this->account($http);

        $device = $account->start();
        self::assertSame(['code' => 'ABCD-1234', 'id' => 'dev-1', 'interval' => 3], $device, 'the interval is at least 3 seconds');
        self::assertFalse($account->signedIn());

        $account->finish($device['id'], $device['code'], 0);

        self::assertTrue($account->signedIn());
        self::assertSame($access, $account->accessToken());
        self::assertStringContainsString('code_verifier=ver-1', $http->bodies[3] ?? '');
        self::assertStringContainsString('client_id=' . CodexAccount::CLIENT_ID, $http->bodies[3] ?? '');
        self::assertSame(['ChatGPT-Account-ID' => 'acc-1'], CodexAccount::accountHeaders($access));
    }

    public function testAnEndingTokenIsRefreshedAndTheNewRefreshTokenKept(): void {
        $old = self::jwt(['exp' => time() + 30]);
        $new = self::jwt(['exp' => time() + 3600]);
        $this->vault->put(CodexAccount::VAULT_NAME, 'token', json_encode(['access' => $old, 'refresh' => 'ref-old'], JSON_THROW_ON_ERROR), $this->owner);
        $http = new CodexHttpFake(['POST https://auth.openai.com/oauth/token' => [[200, json_encode(['access_token' => $new, 'refresh_token' => 'ref-new'], JSON_THROW_ON_ERROR)]]]);

        self::assertSame($new, $this->account($http)->accessToken());
        self::assertStringContainsString('grant_type=refresh_token&refresh_token=ref-old', $http->bodies[0] ?? '');
        self::assertStringContainsString('"refresh":"ref-new"', (string) $this->vault->reveal(CodexAccount::VAULT_NAME, $this->owner));
        self::assertSame($new, $this->account(new CodexHttpFake([]))->accessToken(), 'a fresh token is used as it is');
    }

    public function testNotSignedInIsAnErrorOfAccess(): void {
        $this->expectException(LlmException::class);
        $this->account(new CodexHttpFake([]))->accessToken();
    }

    public function testTheBackendGetsNoOutputLimitOrTemperature(): void {
        $request = new ChatRequest('gpt-5.5', [Message::user('hi')], [], 500, 0.3, 'high', ['x' => 1]);

        $codex = CodexClient::forCodex($request);

        self::assertNull($codex->maxTokens);
        self::assertNull($codex->temperature);
        self::assertSame('high', $codex->reasoningEffort);
        self::assertSame(['x' => 1], $codex->extra);
    }

    public function testTheCatalogKeepsVisibleModelsByPriority(): void {
        $models = CodexCatalog::models('{"models":[{"slug":"gpt-5.5","priority":2,"context_window":272000},{"slug":"gpt-6-sol","priority":1},{"slug":"old","visibility":"hide"},{"slug":"gpt-5.5","priority":9}]}');

        self::assertSame(['gpt-6-sol', 'gpt-5.5'], array_map(static fn($m): string => $m->id, $models));
        self::assertSame(272000, $models[1]->contextLength);
        self::assertSame(0.0, $models[0]->inputPricePerToken, 'the subscription pays');
    }

    public function testTheCommandGivesThePageAndTheCodeAndSaysWhenItWorked(): void {
        $access = self::jwt(['exp' => time() + 3600]);
        $http = new CodexHttpFake([
            'POST https://auth.openai.com/api/accounts/deviceauth/usercode' => [[200, '{"user_code":"WXYZ-9","device_auth_id":"dev-2","interval":3}']],
            'POST https://auth.openai.com/api/accounts/deviceauth/token' => [[200, '{"authorization_code":"c","code_verifier":"v"}']],
            'POST https://auth.openai.com/oauth/token' => [[200, json_encode(['access_token' => $access, 'refresh_token' => 'r'], JSON_THROW_ON_ERROR)]],
        ]);
        $channel = new FakeChannel();
        $commands = new CodexCommands($this->account($http), $channel, new NullLogger());

        self::assertSame(t('codex.not_signed_in'), $commands->handle('42', ''));
        self::assertSame(sprintf(t('codex.login'), CodexAccount::VERIFY_URL, 'WXYZ-9'), $commands->handle('42', 'login'));
        delay(3.5);

        self::assertStringContainsString(t('codex.done'), implode("\n", $channel->texts('send')));
        self::assertSame(t('codex.signed_in'), $commands->handle('42', ''));
        self::assertSame(t('codex.signed_out'), $commands->handle('42', 'logout'));
        self::assertSame(t('codex.not_signed_in'), $commands->handle('42', ''));
    }
}

/** Answers by "METHOD url", each answer in turn (the last one repeats). */
final class CodexHttpFake implements DelegateHttpClient {
    /** @var list<string> */
    public array $bodies = [];
    /** @var array<string, int> */
    private array $served = [];

    /** @param array<string, list<array{int, string}>> $answers */
    public function __construct(private readonly array $answers) {}

    public function request(Request $request, Cancellation $cancellation): Response {
        $uri = $request->getUri();
        $key = $request->getMethod() . ' ' . $uri->getScheme() . '://' . $uri->getHost() . $uri->getPath();
        $this->bodies[] = buffer($request->getBody()->getContent());
        $list = $this->answers[$key] ?? [[404, '{}']];
        $index = min($this->served[$key] ?? 0, count($list) - 1);
        $this->served[$key] = ($this->served[$key] ?? 0) + 1;
        [$status, $body] = $list[$index];
        return new Response('1.1', $status, null, ['content-type' => 'application/json'], $body, $request);
    }
}
