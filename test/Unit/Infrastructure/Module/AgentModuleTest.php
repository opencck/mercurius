<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Module;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Infrastructure\Module\AgentModule;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

use function Amp\delay;

final class AgentModuleTest extends AsyncTestCase {
    private static function status(AgentStack $stack): ?RunStatus {
        return $stack->runs->find(1)?->status;
    }

    public function testStopKeepsRunAndStartResumesIt(): void {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'slow', '{}')), ScriptedLlm::text('готово')]));
        $stack->registry->register(new FakeTool('slow', delay: 0.5));
        $module = new AgentModule($stack->scheduler, new NullLogger());

        $stack->say('задача');
        delay(0.1);
        $module->stop();
        self::assertSame(RunStatus::Running, self::status($stack));

        $module->start();
        $stack->scheduler->awaitIdle();
        self::assertSame(RunStatus::Done, self::status($stack));
    }
}
