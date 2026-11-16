<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Mercurius\Llm\ToolCall;
use OpenCCK\App\Agent\Tool\ToolCallValidator;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Test\Support\FakeTool;
use PHPUnit\Framework\TestCase;

final class ToolCallValidatorTest extends TestCase {
    private ToolCallValidator $validator;

    protected function setUp(): void {
        $registry = new ToolRegistry();
        $registry->register(new FakeTool('read', DangerClass::Read, [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ]));
        $registry->register(new FakeTool('write', DangerClass::Write, ['type' => 'object']));
        $registry->register(new FakeTool('secret'));
        $this->validator = new ToolCallValidator($registry);
    }

    private function policy(): ParticipantPolicy {
        return new ParticipantPolicy(['read', 'write'], new RunLimits(30, 1000, 900, 120), null, true);
    }

    public function testValidCallKeepsArgumentsAndDanger(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'write', '{"a":1}')], $this->policy());

        self::assertFalse($outcome->truncated);
        self::assertTrue($outcome->hasValid());
        self::assertNull($outcome->calls[0]->error);
        self::assertSame(['a' => 1], $outcome->calls[0]->args);
        self::assertSame(DangerClass::Write, $outcome->calls[0]->danger);
    }

    public function testUnknownToolReturnsAllowedNames(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'nope', '{}')], $this->policy());

        self::assertSame('Неизвестный инструмент «nope». Доступны: read, write.', $outcome->calls[0]->error);
        self::assertNull($outcome->calls[0]->tool);
        self::assertFalse($outcome->hasValid());
    }

    public function testToolForbiddenByPolicyIsUnknown(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'secret', '{}')], $this->policy());

        self::assertStringStartsWith('Неизвестный инструмент «secret»', (string) $outcome->calls[0]->error);
    }

    public function testMixedBatchKeepsValidCalls(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'nope', '{}'), new ToolCall('c2', 'read', '{"q":"x"}')], $this->policy());

        self::assertNotNull($outcome->calls[0]->error);
        self::assertNull($outcome->calls[1]->error);
        self::assertTrue($outcome->hasValid());
    }

    public function testInvalidJsonIsNeverExecuted(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'read', '{"q": x}')], $this->policy());

        self::assertFalse($outcome->truncated);
        self::assertNull($outcome->calls[0]->args);
        self::assertStringStartsWith('Аргументы не разобраны как JSON', (string) $outcome->calls[0]->error);
    }

    public function testTruncatedArgumentsFlagTheBatch(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'read', '{"q": "abc')], $this->policy());

        self::assertTrue($outcome->truncated);
    }

    public function testDuplicateCallsCollapse(): void {
        $outcome = $this->validator->validate([
            new ToolCall('c1', 'read', '{"q":"x"}'),
            new ToolCall('c2', 'read', '{ "q" : "x" }'),
        ], $this->policy());

        self::assertNull($outcome->calls[0]->error);
        self::assertSame('Повтор вызова c1 в этом же ответе: смотри его результат.', $outcome->calls[1]->error);
    }

    public function testDuplicateIdsBecomeUnique(): void {
        $outcome = $this->validator->validate([
            new ToolCall('call_0', 'read', '{"q":"a"}'),
            new ToolCall('call_0', 'read', '{"q":"b"}'),
            new ToolCall('call_1', 'read', '{"q":"c"}'),
        ], $this->policy(), ['call_1']);

        self::assertSame(['call_0', 'call_0-2', 'call_1-2'], array_map(static fn($c) => $c->call->id, $outcome->calls));
    }

    public function testSchemaErrorsAreListed(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'read', '{"q": 5}')], $this->policy());

        self::assertSame('Аргументы не прошли проверку: q: ожидается string.', $outcome->calls[0]->error);
    }

    public function testEmptyArgumentsBecomeEmptyObject(): void {
        $outcome = $this->validator->validate([new ToolCall('c1', 'write', '  ')], $this->policy());

        self::assertNull($outcome->calls[0]->error);
        self::assertSame([], $outcome->calls[0]->args);
        self::assertSame('{}', $outcome->calls[0]->call->argumentsJson);
    }
}
