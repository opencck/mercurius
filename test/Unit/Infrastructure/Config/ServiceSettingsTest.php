<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Config;

use OpenCCK\Infrastructure\Config\AgentSettings;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Config\SandboxSettings;
use OpenCCK\Infrastructure\Config\TraceSettings;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use PHPUnit\Framework\TestCase;

final class ServiceSettingsTest extends TestCase {
    private const TOKEN = '123456:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testMemoryHrrWeight(): void {
        self::assertSame(0.3, \OpenCCK\Infrastructure\Config\MemorySettings::fromEnv([])->hrrWeight);
        self::assertSame(0.0, \OpenCCK\Infrastructure\Config\MemorySettings::fromEnv(['MEMORY_HRR_WEIGHT' => '0'])->hrrWeight);
        $this->expectException(\OpenCCK\Infrastructure\Config\ConfigException::class);
        \OpenCCK\Infrastructure\Config\MemorySettings::fromEnv(['MEMORY_HRR_WEIGHT' => '1.5']);
    }

    public function testTelegramPollingWithoutAppUrl(): void {
        $settings = TelegramSettings::fromEnv(['TELEGRAM_BOT_TOKEN' => self::TOKEN, 'TELEGRAM_ADMIN_ID' => '42'], null);

        self::assertSame('42', $settings->ownerId);
        self::assertNull($settings->webhookUrl);
        self::assertNull($settings->webhookSecret());
        self::assertSame(self::TOKEN, $settings->token());
    }

    public function testTelegramWebhookNeedsSecret(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('TELEGRAM_WEBHOOK_SECRET');
        TelegramSettings::fromEnv(['TELEGRAM_BOT_TOKEN' => self::TOKEN, 'TELEGRAM_ADMIN_ID' => '42'], 'https://bot.example.com');
    }

    public function testTelegramWebhook(): void {
        $settings = TelegramSettings::fromEnv([
            'TELEGRAM_BOT_TOKEN' => self::TOKEN,
            'TELEGRAM_ADMIN_ID' => '42',
            'TELEGRAM_WEBHOOK_SECRET' => 'abcdefghijklmnop_1',
        ], 'https://bot.example.com');

        self::assertSame('https://bot.example.com/telegram/webhook', $settings->webhookUrl);
        self::assertSame('abcdefghijklmnop_1', $settings->webhookSecret());
    }

    public function testTelegramSecretsAreHidden(): void {
        $dump = print_r(TelegramSettings::fromEnv(['TELEGRAM_BOT_TOKEN' => self::TOKEN, 'TELEGRAM_ADMIN_ID' => '42'], null), true);

        self::assertStringNotContainsString('AAAAAAAA', $dump);
    }

    public function testBadOwnerId(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('TELEGRAM_ADMIN_ID');
        TelegramSettings::fromEnv(['TELEGRAM_BOT_TOKEN' => self::TOKEN, 'TELEGRAM_ADMIN_ID' => '@owner'], null);
    }

    public function testLlmDefaults(): void {
        $settings = LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'sk-or-secret']);

        self::assertSame('z-ai/glm-5.3-flash', $settings->model);
        self::assertSame('low', $settings->reasoningEffort);
        self::assertSame(4096, $settings->maxTokens);
        self::assertSame(40, $settings->historyLimit);
        self::assertNull($settings->routeUri());
        self::assertStringNotContainsString('sk-or-secret', print_r($settings, true));
    }

    public function testWithoutLlmModelTheStartingModelIsTheFirstFallbackOne(): void {
        // spec: brief/models.md#запасные-модели — a new installation names the fallback models only
        $fallback = LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'sk-or', 'LLM_FALLBACK_MODELS' => 'anthropic:x, openrouter:nvidia/nemotron-3.5-super-vl:free']);
        $own = LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'sk-or', 'LLM_MODEL' => 'author/own', 'LLM_FALLBACK_MODELS' => 'nvidia/nemotron-3.5-super-vl:free']);

        self::assertSame('openrouter:nvidia/nemotron-3.5-super-vl:free', $fallback->model, 'the first one a provider with a key serves');
        self::assertSame('author/own', $own->model, 'LLM_MODEL, when set, stays');
    }

    public function testLlmRouteAndOverrides(): void {
        $settings = LlmSettings::fromEnv([
            'OPENROUTER_API_KEY' => 'sk-or-secret',
            'VLESS_URI' => 'vless://id@host:443?type=ws',
            'LLM_MODEL' => 'openai/gpt-5.6-luna',
            'LLM_REASONING_EFFORT' => 'NONE',
            'LLM_MAX_TOKENS' => '1000',
        ]);

        self::assertSame('vless://id@host:443?type=ws', $settings->routeUri());
        self::assertSame('openai/gpt-5.6-luna', $settings->model);
        self::assertSame('none', $settings->reasoningEffort);
        self::assertSame(1000, $settings->maxTokens);
        self::assertStringNotContainsString('id@host', print_r($settings, true));
    }

    public function testEitherProviderKeyIsEnough(): void {
        $openAi = LlmSettings::fromEnv(['OPENAI_API_KEY' => 'sk-proj-secret']);
        $both = LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'sk-or', 'OPENAI_API_KEY' => 'sk-proj']);

        self::assertSame(['openai', 'codex'], $openAi->providers(), 'one key, two APIs: Chat Completions and Responses');
        self::assertNull($openAi->openRouterKey());
        self::assertSame('sk-proj-secret', $openAi->openAiKey());
        self::assertSame('openai:gpt-6.1-terra', $openAi->model, 'no OpenRouter: an OpenAI model by default');
        self::assertSame('openai:gpt-6.1-terra', $openAi->cheapModel, 'no cheap model of its own: the starting model serves');
        self::assertStringNotContainsString('sk-proj-secret', print_r($openAi, true));
        self::assertSame(['openrouter', 'openai', 'codex'], $both->providers());
        self::assertSame('z-ai/glm-5.3-flash', $both->model);
    }

    public function testAnAnthropicKeyAloneIsEnough(): void {
        // spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
        $anthropic = LlmSettings::fromEnv(['ANTHROPIC_API_KEY' => 'sk-ant-secret']);
        $all = LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'sk-or', 'OPENAI_API_KEY' => 'sk-proj', 'ANTHROPIC_API_KEY' => 'sk-ant', 'LLM_MODEL' => 'anthropic:claude-opus-5-5']);

        self::assertSame(['anthropic'], $anthropic->providers());
        self::assertSame('sk-ant-secret', $anthropic->anthropicKey());
        self::assertSame('anthropic:claude-sonnet-5-5', $anthropic->model, 'only Anthropic: a Claude model by default');
        self::assertSame('anthropic:claude-sonnet-5-5', $anthropic->cheapModel, 'no cheap model of its own: the starting model serves');
        self::assertStringNotContainsString('sk-ant-secret', print_r($anthropic, true));
        self::assertSame(['openrouter', 'openai', 'codex', 'anthropic'], $all->providers());
        self::assertSame('anthropic:claude-opus-5-5', $all->model);
    }

    public function testABedrockKeyAloneIsEnough(): void {
        // spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
        $bedrock = LlmSettings::fromEnv(['AWS_BEARER_TOKEN_BEDROCK' => 'bedrock-secret']);
        $eu = LlmSettings::fromEnv(['AWS_BEARER_TOKEN_BEDROCK' => 'b', 'BEDROCK_REGION' => 'eu-central-1']);

        self::assertSame(['bedrock'], $bedrock->providers());
        self::assertSame('bedrock-secret', $bedrock->bedrockKey());
        self::assertSame('us-east-1', $bedrock->bedrockRegion, 'the region with every Claude model');
        self::assertSame('eu-central-1', $eu->bedrockRegion);
        self::assertSame('bedrock:anthropic.claude-sonnet-5-5', $bedrock->model);
        self::assertSame('bedrock:anthropic.claude-sonnet-5-5', $bedrock->cheapModel, 'no cheap model of its own: the starting model serves');
        self::assertStringNotContainsString('bedrock-secret', print_r($bedrock, true));
    }

    private static function serviceAccount(?string $project = 'my-project-1', ?string $key = null): string {
        return json_encode(array_filter([
            'type' => 'service_account',
            'project_id' => $project,
            'client_email' => 'agent@my-project-1.iam.gserviceaccount.com',
            // a key made for tests only; it opens nothing
            'private_key' => $key ?? (string) file_get_contents(__DIR__ . '/../../../Fixtures/test-only-rsa.pem'),
        ], static fn(?string $v): bool => $v !== null), JSON_THROW_ON_ERROR);
    }

    public function testAGeminiKeyAloneIsEnough(): void {
        // spec: brief/models.md#провайдеры-gemini-и-vertex
        $gemini = LlmSettings::fromEnv(['GEMINI_API_KEY' => 'AIza-secret']);

        self::assertSame(['gemini'], $gemini->providers());
        self::assertSame('AIza-secret', $gemini->geminiKey());
        self::assertSame('gemini:gemini-4-hydrogen', $gemini->model);
        self::assertStringNotContainsString('AIza-secret', print_r($gemini, true));
    }

    public function testAKeyOfKimiAloneIsEnough(): void {
        // spec: brief/models.md#kimi-ключ-api-и-подписка
        $platform = LlmSettings::fromEnv(['MOONSHOT_API_KEY' => 'sk-platform-secret']);
        $subscription = LlmSettings::fromEnv(['KIMI_CODE_API_KEY' => 'sk-kimi-secret', 'KIMI_CODE_BASE_URL' => 'https://api.kimi.com/coding/v1/']);

        self::assertSame(['moonshot'], $platform->providers());
        self::assertSame('moonshot:kimi-k3.1-pro', $platform->model);
        self::assertSame('https://api.moonshot.ai/v1', $platform->moonshotBaseUrl);
        self::assertSame('https://api.kimi.ai/coding/v1', $platform->kimiCodeBaseUrl, 'outside China by default');
        self::assertSame(['kimi'], $subscription->providers());
        self::assertSame('kimi:kimi-for-coding', $subscription->model);
        self::assertSame('https://api.kimi.com/coding/v1', $subscription->kimiCodeBaseUrl);
        self::assertStringNotContainsString('sk-kimi-secret', print_r($subscription, true));
    }

    /**
     * @dataProvider foreignKimiAddresses
     * @param array<string, string> $env
     */
    public function testAnAddressOfKimiOutsideItsListIsRefused(array $env, string $name): void {
        try {
            LlmSettings::fromEnv($env);
            self::fail('expected a refusal');
        } catch (ConfigException $e) {
            self::assertStringContainsString($name, $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function foreignKimiAddresses(): iterable {
        yield 'the platform' => [['MOONSHOT_API_KEY' => 'k', 'MOONSHOT_BASE_URL' => 'https://evil.example/v1'], 'MOONSHOT_BASE_URL'];
        yield 'the subscription' => [['KIMI_CODE_API_KEY' => 'k', 'KIMI_CODE_BASE_URL' => 'https://api.moonshot.ai/v1'], 'KIMI_CODE_BASE_URL'];
    }

    public function testAVertexServiceAccountAloneIsEnough(): void {
        // spec: brief/models.md#провайдеры-gemini-и-vertex
        $vertex = LlmSettings::fromEnv(['VERTEX_CREDENTIALS' => self::serviceAccount()]);
        $placed = LlmSettings::fromEnv(['VERTEX_CREDENTIALS' => self::serviceAccount(null), 'VERTEX_PROJECT_ID' => 'other-project', 'VERTEX_REGION' => 'us-central1']);

        self::assertSame(['vertex'], $vertex->providers());
        self::assertSame('vertex:google/gemini-4-hydrogen', $vertex->model);
        self::assertSame('', $vertex->vertexProject, 'the project of the service account serves');
        self::assertSame('global', $vertex->vertexRegion);
        self::assertSame('other-project', $placed->vertexProject);
        self::assertSame('us-central1', $placed->vertexRegion);
        self::assertStringNotContainsString('PRIVATE KEY', print_r($vertex, true));
    }

    /**
     * @dataProvider brokenVertex
     * @param array<string, string> $env
     */
    public function testVertexSettingsThatCannotWorkAreRefusedWithoutRepeatingTheKey(array $env, string $name): void {
        try {
            LlmSettings::fromEnv($env);
            self::fail('expected a refusal');
        } catch (ConfigException $e) {
            self::assertStringContainsString($name, $e->getMessage());
            self::assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function brokenVertex(): iterable {
        yield 'not json' => [['VERTEX_CREDENTIALS' => 'SECRET'], 'VERTEX_CREDENTIALS'];
        yield 'a user, not a service account' => [['VERTEX_CREDENTIALS' => '{"type":"authorized_user","refresh_token":"SECRET"}'], 'VERTEX_CREDENTIALS'];
        yield 'a key that is not a key' => [['VERTEX_CREDENTIALS' => self::serviceAccount('my-project-1', 'SECRET')], 'VERTEX_CREDENTIALS'];
        yield 'no project anywhere' => [['VERTEX_CREDENTIALS' => self::serviceAccount(null)], 'VERTEX_CREDENTIALS'];
        yield 'a region that is not a region' => [['GEMINI_API_KEY' => 'k', 'VERTEX_REGION' => 'evil.example/x'], 'VERTEX_REGION'];
        yield 'a project that is not a project' => [['GEMINI_API_KEY' => 'k', 'VERTEX_PROJECT_ID' => 'x/../y'], 'VERTEX_PROJECT_ID'];
    }

    public function testABedrockRegionThatIsNotARegionIsRefused(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('BEDROCK_REGION');
        LlmSettings::fromEnv(['AWS_BEARER_TOKEN_BEDROCK' => 'b', 'BEDROCK_REGION' => 'evil.example/x']);
    }

    public function testSomeProviderKeyIsNeeded(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        LlmSettings::fromEnv([]);
    }

    public function testModelOfAProviderWithoutAKeyIsRefused(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('LLM_MODEL');
        LlmSettings::fromEnv(['OPENAI_API_KEY' => 'sk-proj', 'LLM_MODEL' => 'z-ai/glm-5.3-flash']);
    }

    public function testACodexModelGoesWithTheOpenAiKey(): void {
        // spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
        $settings = LlmSettings::fromEnv(['OPENAI_API_KEY' => 'sk-proj', 'LLM_MODEL' => 'codex:gpt-6.1-codex']);

        self::assertSame('codex:gpt-6.1-codex', $settings->model);
    }

    public function testOpenAiModelIsAccepted(): void {
        $settings = LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'k', 'OPENAI_API_KEY' => 'sk-proj', 'LLM_MODEL' => 'openai:gpt-6-luna']);

        self::assertSame('openai:gpt-6-luna', $settings->model);
    }

    public function testSpendCapsAndWorkerCap(): void {
        $off = \OpenCCK\Infrastructure\Config\AgentSettings::fromEnv([]);
        $set = \OpenCCK\Infrastructure\Config\AgentSettings::fromEnv(['AGENT_DAILY_SPEND_CAP' => '5', 'AGENT_MONTHLY_SPEND_CAP' => '50.5', 'AGENT_MAX_WORKERS_PER_RUN' => '4']);

        self::assertSame(0.0, $off->dailySpendCap, 'no cap until the owner sets one');
        self::assertSame(10, $off->maxWorkersPerRun);
        self::assertSame(5.0, $set->dailySpendCap);
        self::assertSame(50.5, $set->monthlySpendCap);
        self::assertSame(4, $set->maxWorkersPerRun);
    }

    public function testNegativeSpendCapIsRefused(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('AGENT_DAILY_SPEND_CAP');
        \OpenCCK\Infrastructure\Config\AgentSettings::fromEnv(['AGENT_DAILY_SPEND_CAP' => '-1']);
    }

    public function testLlmBadEffort(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('LLM_REASONING_EFFORT');
        LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'k', 'LLM_REASONING_EFFORT' => 'extreme']);
    }

    public function testCompactionSettings(): void {
        $settings = LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'k']);

        self::assertSame(131072, $settings->contextWindow);
        // spec: brief/models.md#списки-моделей-по-категориям — no model is built in: the starting one serves the auxiliary calls
        self::assertSame($settings->model, $settings->cheapModel);
        self::assertSame('author/cheap', LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'k', 'LLM_CHEAP_MODEL' => 'author/cheap'])->cheapModel);
        self::assertSame(16384, LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'k', 'LLM_CONTEXT_WINDOW' => '16384'])->contextWindow);
    }

    public function testContextWindowHasAFloor(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('LLM_CONTEXT_WINDOW');
        LlmSettings::fromEnv(['OPENROUTER_API_KEY' => 'k', 'LLM_CONTEXT_WINDOW' => '100']);
    }

    public function testTraceDefaults(): void {
        $settings = TraceSettings::fromEnv([]);

        self::assertSame(14, $settings->retentionDays);
        self::assertNull($settings->metricsToken());
        self::assertSame('s3cret-token', TraceSettings::fromEnv(['METRICS_TOKEN' => 's3cret-token'])->metricsToken());
    }

    public function testTraceRetentionIsAtMostAYear(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('TRACE_RETENTION_DAYS');
        TraceSettings::fromEnv(['TRACE_RETENTION_DAYS' => '366']);
    }

    public function testAgentDefaults(): void {
        $settings = AgentSettings::fromEnv([]);

        self::assertSame(750, $settings->maxTurns);
        self::assertSame(1_000_000, $settings->tokenBudget);
        self::assertSame(28_800, $settings->runTimeout);
        self::assertSame(120, $settings->toolTimeout);
        self::assertSame(1800, $settings->approvalTimeout);
        self::assertSame(375, $settings->workerMaxTurns);
        self::assertSame(500_000, $settings->workerTokenBudget);
    }

    public function testApprovalTimeoutIsAtMostADay(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('AGENT_APPROVAL_TIMEOUT');
        AgentSettings::fromEnv(['AGENT_APPROVAL_TIMEOUT' => '86401']);
    }

    public function testAgentToolTimeoutCappedAt600(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('AGENT_TOOL_TIMEOUT');
        AgentSettings::fromEnv(['AGENT_TOOL_TIMEOUT' => '601']);
    }

    public function testSandboxDefaults(): void {
        $settings = SandboxSettings::fromEnv([]);
        $limits = $settings->ownerLimits();

        self::assertSame('http://sandbox:9000', $settings->url);
        self::assertNull($settings->token());
        self::assertSame(5 * 1024 ** 3, $limits->diskBytes);
        self::assertSame(128, $limits->processes);
        self::assertSame(600, $limits->cpuSeconds);
        self::assertSame(0, $limits->memoryBytes, 'no address-space limit by default');
        self::assertSame(4, $limits->concurrentCommands);
    }

    public function testSandboxSizesAcceptUnits(): void {
        self::assertSame(536870912, SandboxSettings::fromEnv(['SANDBOX_WORKSPACE_LIMIT' => '512m'])->ownerLimits()->diskBytes);
        self::assertSame(1000, SandboxSettings::fromEnv(['SANDBOX_WORKSPACE_LIMIT' => '1000'])->ownerLimits()->diskBytes);
    }

    public function testSandboxRejectsBadSize(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('SANDBOX_WORKSPACE_LIMIT');
        SandboxSettings::fromEnv(['SANDBOX_WORKSPACE_LIMIT' => '5x']);
    }

    public function testSandboxTokenIsHidden(): void {
        $settings = SandboxSettings::fromEnv(['SANDBOX_TOKEN' => 'super-secret-token-value']);

        self::assertSame('super-secret-token-value', $settings->token());
        self::assertStringNotContainsString('super-secret', print_r($settings, true));
    }

    public function testAgentRejectsNonPositive(): void {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('AGENT_MAX_TURNS');
        AgentSettings::fromEnv(['AGENT_MAX_TURNS' => '0']);
    }
}
