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

        self::assertSame(['openai'], $openAi->providers());
        self::assertNull($openAi->openRouterKey());
        self::assertSame('sk-proj-secret', $openAi->openAiKey());
        self::assertSame('openai:gpt-6.1-terra', $openAi->model, 'no OpenRouter: an OpenAI model by default');
        self::assertSame('openai:gpt-6-luna', $openAi->cheapModel);
        self::assertStringNotContainsString('sk-proj-secret', print_r($openAi, true));
        self::assertSame(['openrouter', 'openai'], $both->providers());
        self::assertSame('z-ai/glm-5.3-flash', $both->model);
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
        self::assertSame('openai/gpt-5.6-luna', $settings->cheapModel);
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

        self::assertSame(30, $settings->maxTurns);
        self::assertSame(400000, $settings->tokenBudget);
        self::assertSame(900, $settings->runTimeout);
        self::assertSame(120, $settings->toolTimeout);
        self::assertSame(1800, $settings->approvalTimeout);
        self::assertSame(15, $settings->workerMaxTurns);
        self::assertSame(100_000, $settings->workerTokenBudget);
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
        self::assertSame(8 * 1024 ** 3, $limits->memoryBytes);
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
