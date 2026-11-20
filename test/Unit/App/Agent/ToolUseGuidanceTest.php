<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use OpenCCK\App\Agent\ToolUseGuidance;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/agent-loop.md#агентский-цикл-и-харнесс — models that tend to answer in prose get the rule to act through tools
 */
final class ToolUseGuidanceTest extends TestCase {
    public function testFamiliesThatAnswerInProseGetTheRuleByDefault(): void {
        $auto = new ToolUseGuidance('auto');

        self::assertTrue($auto->applies('openrouter:z-ai/glm-5.3-flash'));
        self::assertTrue($auto->applies('openrouter:qwen/qwen3.8-flash'));
        self::assertTrue($auto->applies('openai:gpt-6-luna'));
        self::assertFalse($auto->applies('openrouter:nvidia/nemotron-3.5-super-vl:free'), 'nemotron is left out as in hermes');
        self::assertFalse($auto->applies('anthropic:claude-opus-5'));
    }

    public function testTheOwnerTurnsItOnOffOrNamesTheModels(): void {
        self::assertTrue((new ToolUseGuidance('on'))->applies('anthropic:claude-opus-5'));
        self::assertFalse((new ToolUseGuidance('off'))->applies('openrouter:z-ai/glm-5.3'));
        $list = new ToolUseGuidance('nemotron, Muse');
        self::assertTrue($list->applies('openrouter:nvidia/nemotron-3.5-super-vl:free'));
        self::assertTrue($list->applies('openrouter:meta/muse-ember'));
        self::assertFalse($list->applies('openrouter:z-ai/glm-5.3'));
    }
}
