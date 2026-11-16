<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Module;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Infrastructure\Module\AgentModule;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\FakeKeyedTool;
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

    private static function waiting(): AgentStack {
        $stack = new AgentStack(new ScriptedLlm([ScriptedLlm::calls(new ToolCall('c1', 'push', '{}')), ScriptedLlm::text('понял')]));
        $stack->registry->register(new FakeKeyedTool('push', 'git-push:origin'));
        $stack->say('отправь');
        $stack->scheduler->awaitIdle();
        return $stack;
    }

    public function testStartClosesApprovalsThatExpiredWhileDown(): void {
        $stack = self::waiting();
        $stack->approvals->expireNow(1);
        $module = new AgentModule($stack->scheduler, new NullLogger(), $stack->approvalService, 60.0);

        $module->start();
        $stack->scheduler->awaitIdle();
        $module->stop();

        self::assertSame(ApprovalStatus::Expired, $stack->approvals->find(1)?->status);
        self::assertSame(RunStatus::Done, self::status($stack));
    }

    public function testTimerExpiresApprovalsWhileRunning(): void {
        $stack = self::waiting();
        $module = new AgentModule($stack->scheduler, new NullLogger(), $stack->approvalService, 0.05);
        $module->start();
        self::assertSame(ApprovalStatus::Pending, $stack->approvals->find(1)?->status);

        $stack->approvals->expireNow(1);
        delay(0.2);
        $stack->scheduler->awaitIdle();
        $module->stop();

        self::assertSame(ApprovalStatus::Expired, $stack->approvals->find(1)?->status);
    }
}
