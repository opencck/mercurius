<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Llm;

use Amp\Http\Client\HttpClientBuilder;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Llm\ProviderClients;
use PHPUnit\Framework\TestCase;

final class ProviderClientsTest extends TestCase {
    public function testEveryProviderWithAKeyGetsAClientAndACatalog(): void {
        // spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
        $http = HttpClientBuilder::buildDefault();
        $all = ProviderClients::build(LlmSettings::fromEnv([
            'OPENROUTER_API_KEY' => 'or', 'OPENAI_API_KEY' => 'oa', 'ANTHROPIC_API_KEY' => 'an', 'AWS_BEARER_TOKEN_BEDROCK' => 'br',
        ]), $http, null);
        $claude = ProviderClients::build(LlmSettings::fromEnv(['ANTHROPIC_API_KEY' => 'an']), $http, null);

        self::assertSame(['openrouter', 'openai', 'codex', 'anthropic', 'bedrock'], array_keys($all->clients));
        self::assertSame(array_keys($all->clients), array_keys($all->catalogs));
        self::assertSame(['openrouter'], array_keys($all->toolReliable), 'only OpenRouter reports how models call tools');
        self::assertSame(['anthropic'], array_keys($claude->clients));
        self::assertSame([], $claude->toolReliable);
    }
}
