<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

use OpenCCK\Sandbox\SandboxError;
use OpenCCK\Sandbox\Streams;
use OpenCCK\Sandbox\Users;

use function Amp\delay;

/**
 * The streaming API on real processes: a long-lived process as the participant, its stdin, stdout by long polling,
 * the end, the limits.
 *
 * @group sandbox
 */
final class StreamsTest extends SandboxTestCase {
    private Streams $streams;

    protected function setUp(): void {
        parent::setUp();
        $this->streams = new Streams($this->runner, new Users($this->workspace), $this->workspace);
    }

    protected function tearDown(): void {
        $this->streams->stopAll();
        parent::tearDown();
    }

    /** @return string everything the session printed within $seconds */
    private function collect(int $principal, string $id, float $seconds): string {
        $out = '';
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            $got = $this->streams->receive($principal, $id, 65536, 0.5);
            $out .= $got['stdout'];
            if ($got['exited']) {
                break;
            }
        }
        return $out;
    }

    public function testALongLivedProcessAnswersEachLineAsTheParticipant(): void {
        $id = $this->streams->spawn(901, self::limits(), 'while read line; do echo "got:$line:$(id -u):$SECRET_X"; done', '.', ['SECRET_X' => 'v1']);

        $this->streams->send(901, $id, "one\n");
        $first = $this->collect(901, $id, 3.0);
        $this->streams->send(901, $id, "two\n");
        $second = $this->collect(901, $id, 3.0);

        self::assertSame('got:one:' . $this->workspace->uid(901) . ":v1\n", $first);
        self::assertSame('got:two:' . $this->workspace->uid(901) . ":v1\n", $second, 'stdin stays open between the calls');
    }

    public function testTheTokenIsNotInTheArgumentsOfTheProcess(): void {
        $id = $this->streams->spawn(902, self::limits(), 'sleep 30', '.', ['SECRET_Y' => 'do-not-show']);
        delay(0.3);

        $listing = $this->sh(903, 'ps -eo args')->stdout;

        // spec: brief/agent-loop.md#песочница — with hidepid another user does not see the process at all
        self::assertStringNotContainsString('sleep 30', $listing);
        self::assertStringNotContainsString('do-not-show', $listing);
        $this->streams->close(902, $id);
    }

    public function testTheEndIsReportedWithTheExitCodeAndStderr(): void {
        $id = $this->streams->spawn(904, self::limits(), 'echo out; echo bad >&2; exit 3', '.', []);

        $out = '';
        $end = null;
        for ($i = 0; $i < 20 && $end === null; $i++) {
            $got = $this->streams->receive(904, $id, 65536, 0.5);
            $out .= $got['stdout'];
            $end = $got['exited'] ? $got : null;
        }

        self::assertSame("out\n", $out);
        self::assertNotNull($end);
        self::assertSame(3, $end['exit_code']);
        self::assertStringContainsString('bad', $end['stderr']);
        self::assertSame(0, $this->streams->count(), 'an ended session is gone once read');
    }

    public function testASessionIsOnlyItsParticipantsAndTheirNumberIsCapped(): void {
        $id = $this->streams->spawn(905, self::limits(), 'sleep 30', '.', []);
        try {
            $this->streams->send(906, $id, "x\n");
            self::fail('another participant reached the session');
        } catch (SandboxError $e) {
            self::assertSame(404, $e->status);
        }
        for ($i = 1; $i < Streams::PER_PRINCIPAL; $i++) {
            $this->streams->spawn(905, self::limits(), 'sleep 30', '.', []);
        }
        $this->expectException(SandboxError::class);
        $this->streams->spawn(905, self::limits(), 'sleep 30', '.', []);
    }

    public function testAPathOrLoaderVariableIsRefused(): void {
        $this->expectException(SandboxError::class);
        $this->streams->spawn(907, self::limits(), 'true', '.', ['LD_PRELOAD' => '/tmp/x.so']);
    }
}
