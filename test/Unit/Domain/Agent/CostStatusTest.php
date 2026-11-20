<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Domain\Agent;

use OpenCCK\Domain\Agent\TraceSpan;
use PHPUnit\Framework\TestCase;

/**
 * How sure a cost is: OpenRouter reports it, for other providers it is an estimate by catalog prices, without either
 * it is unknown. The same rule for traces and for llm_calls.
 *
 * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
 */
final class CostStatusTest extends TestCase {
    public function testTheProviderTellsWhetherACostIsReportedOrEstimated(): void {
        self::assertSame(TraceSpan::COST_ACTUAL, TraceSpan::costStatusOf('z-ai/glm-5.3', 0.01));
        self::assertSame(TraceSpan::COST_ACTUAL, TraceSpan::costStatusOf('openrouter:z-ai/glm-5.3', 0.01));
        self::assertSame(TraceSpan::COST_ESTIMATED, TraceSpan::costStatusOf('openai:gpt-5.5', 0.01));
        self::assertSame(TraceSpan::COST_UNKNOWN, TraceSpan::costStatusOf('openai:gpt-5.5', null));
    }
}
