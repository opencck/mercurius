<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Sandbox;

use OpenCCK\Sandbox\Request;
use OpenCCK\Sandbox\SandboxError;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase {
    private const LIMITS = ['disk_bytes' => 100, 'processes' => 10, 'cpu_seconds' => 5, 'memory_bytes' => 1000, 'concurrent_commands' => 1];

    /** @param array<string, mixed> $body */
    private static function json(array $body): string {
        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    public function testParsesCommonFields(): void {
        $request = Request::parse('exec', self::json(['principal_id' => 7, 'call_id' => 'c1', 'limits' => self::LIMITS, 'command' => 'ls', 'timeout' => 5]));

        self::assertSame(7, $request->principalId);
        self::assertSame('c1', $request->callId);
        self::assertSame(10, $request->limits->processes);
        self::assertSame('ls', $request->string('command'));
        self::assertSame(5, $request->int('timeout', 30, 1, 600));
        self::assertSame(30, $request->int('missing', 30, 1, 600));
    }

    public function testRequestRejectsUnknownOp(): void {
        $this->expectException(SandboxError::class);
        Request::parse('rm', self::json(['principal_id' => 7, 'limits' => self::LIMITS]));
    }

    public function testRequestRequiresPrincipal(): void {
        try {
            Request::parse('read', self::json(['limits' => self::LIMITS, 'path' => 'a']));
            self::fail('No error');
        } catch (SandboxError $e) {
            self::assertSame(400, $e->status);
        }
    }

    public function testRequestRejectsBadJson(): void {
        $this->expectException(SandboxError::class);
        Request::parse('read', '{');
    }

    public function testIntOutOfRangeIsRejected(): void {
        $request = Request::parse('exec', self::json(['principal_id' => 7, 'limits' => self::LIMITS, 'timeout' => 9999]));

        $this->expectException(SandboxError::class);
        $request->int('timeout', 30, 1, 600);
    }

    public function testMissingStringIsRejected(): void {
        $request = Request::parse('exec', self::json(['principal_id' => 7, 'limits' => self::LIMITS]));

        $this->expectException(SandboxError::class);
        $request->string('command');
    }
}
