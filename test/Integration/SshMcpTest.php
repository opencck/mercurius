<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Our SSH MCP (mcp/ssh) against the test server of the compose test profile (.docker/sshd-test), never a real one: the
 * process is started as the core starts it, requests go over its stdin, answers come back over its stdout.
 *
 * spec: brief/servers.md#ssh-как-предустановленный-mcp
 *
 * @group integration
 * @psalm-suppress PossiblyUndefinedArrayOffset each call is checked to have its answer (assertCount) before a result is read
 */
final class SshMcpTest extends TestCase {
    private const PASSWORD = 'tester-password-1';

    private string $host;
    private string $bin;

    protected function setUp(): void {
        $host = getenv('SSH_TEST_HOST');
        $dir = getenv('SSH_MCP_DIR');
        if (!is_string($host) || $host === '' || !is_string($dir) || !is_file($dir . '/bin/ssh-mcp')) {
            self::markTestSkipped('SSH_TEST_HOST or SSH_MCP_DIR is not set');
        }
        $this->host = $host;
        $this->bin = $dir . '/bin/ssh-mcp';
        // the container may still be starting: wait for its port
        for ($i = 0; $i < 30; $i++) {
            $socket = @fsockopen($host, 22, $code, $error, 1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(500_000);
        }
        self::fail('the test SSH server did not start');
    }

    /**
     * Starts the server, sends the calls, reads every answer until it ends.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @param list<array{string, array<string, mixed>}> $calls tool and arguments
     * @return list<array<array-key, mixed>> the results of the calls, in order
     */
    private function calls(array $args, array $env, array $calls): array {
        $lines = [json_encode(['jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize', 'params' => new \stdClass()], JSON_THROW_ON_ERROR)];
        foreach ($calls as $i => [$tool, $arguments]) {
            $lines[] = json_encode(['jsonrpc' => '2.0', 'id' => $i + 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        }
        $command = array_merge([PHP_BINARY, $this->bin], $args);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        fwrite($pipes[0], implode("\n", $lines) . "\n");
        fclose($pipes[0]);
        // the process ends when its stdin does: one that does not in a minute is a failure, not a hung suite
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        [$out, $err] = ['', ''];
        $deadline = microtime(true) + 60.0;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            [$write, $except] = [null, null];
            if (@stream_select($read, $write, $except, 1) > 0) {
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                self::fail('the MCP process did not end after its stdin did');
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $results = [];
        foreach (explode("\n", trim($out)) as $line) {
            /** @var mixed $answer */
            $answer = json_decode($line, true);
            if (!is_array($answer) || ($answer['id'] ?? 0) === 0 || !isset($answer['result']) || !is_array($answer['result'])) {
                continue;
            }
            /** @var array<array-key, mixed> $result */
            $result = $answer['result'];
            $results[] = $result;
        }
        self::assertCount(count($calls), $results, $err);
        return $results;
    }

    /**
     * @param list<string> $more
     * @return list<string>
     */
    private function args(?string $hostKey, array $more = []): array {
        return array_values(array_filter(['--host=' . $this->host, '--user=tester', $hostKey === null ? '' : '--host-key=' . $hostKey, ...$more]));
    }

    /** @param array<array-key, mixed> $result */
    private static function text(array $result): string {
        $content = isset($result['content']) && is_array($result['content']) ? $result['content'] : [];
        $first = isset($content[0]) && is_array($content[0]) ? $content[0] : [];
        return isset($first['text']) && is_string($first['text']) ? $first['text'] : '';
    }

    /**
     * @param array<array-key, mixed> $result
     * @return array<array-key, mixed>
     */
    private static function fields(array $result): array {
        $fields = $result['structuredContent'] ?? null;
        self::assertIsArray($fields, self::text($result));
        return $fields;
    }

    private function trusted(): string {
        [$result] = $this->calls($this->args(null), ['SSH_PASSWORD' => self::PASSWORD], [['run', ['command' => 'true']]]);
        self::assertSame(1, preg_match('~^UNKNOWN_HOST_KEY (SHA256:\S+) new$~', self::text($result), $m), self::text($result));
        return $m[1] ?? self::fail('no fingerprint');
    }

    public function testAnUnknownKeyStopsTheConnectionAndATrustedOneLetsItThrough(): void {
        $key = $this->trusted();

        [$run, $again] = $this->calls($this->args($key), ['SSH_PASSWORD' => self::PASSWORD], [
            ['run', ['command' => 'echo hi; id -un; echo oops >&2; exit 3']],
            ['run', ['command' => "cat; echo \"it's done\""]],
        ]);

        self::assertSame(['exit' => 3, 'stdout' => "hi\ntester\n", 'stderr' => "oops\n", 'cut' => false], self::fields($run));
        self::assertSame(['exit' => 0, 'stdout' => "it's done\n"], array_intersect_key(self::fields($again), ['exit' => 1, 'stdout' => 1]), 'stdin is closed, a quote is kept');
    }

    public function testSudoGetsThePasswordAndNoCommandReadsIt(): void {
        [$id, $cat] = $this->calls($this->args($this->trusted()), ['SSH_PASSWORD' => self::PASSWORD], [
            ['run', ['command' => 'sudo id -u']],
            ['run', ['command' => 'sudo cat']],
        ]);

        self::assertSame("0\n", self::fields($id)['stdout'] ?? null, self::text($id));
        self::assertSame(0, self::fields($cat)['exit'] ?? null);
        self::assertStringNotContainsString(self::PASSWORD, self::text($cat));
    }

    public function testTheServerStopsALongCommandAndCutsTheOutput(): void {
        [$long, $big] = $this->calls($this->args($this->trusted()), ['SSH_PASSWORD' => self::PASSWORD], [
            ['run', ['command' => 'sleep 30', 'timeout' => 1]],
            ['run', ['command' => 'head -c 200000 /dev/zero | tr "\0" a']],
        ]);

        self::assertSame(124, self::fields($long)['exit'] ?? null);
        $stdout = self::fields($big)['stdout'] ?? '';
        self::assertIsString($stdout);
        self::assertSame(65536, strlen($stdout));
        self::assertTrue(self::fields($big)['cut'] ?? false);
    }

    public function testAKeyGivenAsTextLogsInAndAJumpGoesThroughTheServerInBetween(): void {
        $key = $this->trusted();
        [$read] = $this->calls($this->args($key), ['SSH_PASSWORD' => self::PASSWORD], [['read_file', ['path' => '/home/tester/.ssh/id_ed25519']]]);
        $private = self::text($read);
        self::assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $private);

        [$byKey] = $this->calls($this->args($key), ['SSH_PRIVATE_KEY' => $private], [['run', ['command' => 'id -un']]]);
        // the test server is its own server in between: the tunnel from it leads back to its own port 22
        [$through] = $this->calls(
            ['--host=127.0.0.1', '--user=tester', '--host-key=' . $key, '--jump-host=' . $this->host, '--jump-user=tester', '--jump-host-key=' . $key],
            ['SSH_PASSWORD' => self::PASSWORD, 'SSH_JUMP_PASSWORD' => self::PASSWORD],
            [['run', ['command' => 'echo through']]],
        );

        self::assertSame("tester\n", self::fields($byKey)['stdout'] ?? null, self::text($byKey));
        self::assertSame("through\n", self::fields($through)['stdout'] ?? null, self::text($through));
    }

    public function testAFileIsWrittenThroughCat(): void {
        $content = "line 1\nстрока 2\n" . str_repeat('x', 100_000);

        [, $back] = $this->calls($this->args($this->trusted()), ['SSH_PASSWORD' => self::PASSWORD], [
            ['write_file', ['path' => '/tmp/written.txt', 'content' => $content]],
            ['read_file', ['path' => '/tmp/written.txt']],
        ]);

        self::assertSame($content, self::text($back));
    }

    public function testAChangedKeyAndAWrongPasswordAreRefused(): void {
        [$changed] = $this->calls($this->args('SHA256:somethingelse'), ['SSH_PASSWORD' => self::PASSWORD], [['run', ['command' => 'id']]]);
        [$wrong] = $this->calls($this->args($this->trusted()), ['SSH_PASSWORD' => 'wrong'], [['run', ['command' => 'id']]]);

        self::assertStringEndsWith(' changed', self::text($changed));
        self::assertTrue($wrong['isError'] ?? false);
        self::assertStringContainsString('login was refused', self::text($wrong));
    }
}
