<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Server\Connection;
use OpenCCK\App\Server\RemoteFailure;
use OpenCCK\App\Server\RemoteResult;
use OpenCCK\App\Server\UnknownHostKey;
use OpenCCK\Domain\Server\Server;
use OpenCCK\Infrastructure\Ssh\AmpSshShell;

/**
 * SSH against the test server of the compose test profile (docker/sshd-test), never a real one.
 *
 * @group integration
 */
final class SshTest extends AsyncTestCase {
    private const PASSWORD = 'tester-password-1';

    private string $host;

    protected function setUp(): void {
        parent::setUp();
        $this->setTimeout(60_000);
        $host = getenv('SSH_TEST_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('SSH_TEST_HOST is not set');
        }
        $this->host = $host;
        // The container may still be starting: wait for its port.
        for ($i = 0; $i < 30; $i++) {
            try {
                \Amp\Socket\connect($host . ':22', cancellation: new \Amp\TimeoutCancellation(1))->close();
                return;
            } catch (\Throwable) {
                \Amp\delay(0.5);
            }
        }
        self::fail('the test SSH server did not start');
    }

    private function server(?string $key): Server {
        return new Server(1, 'test', $this->host, 22, 'tester', 1, 'test', $key, null);
    }

    private function trusted(): Server {
        try {
            (new AmpSshShell())->run(new Connection($this->server(null), self::PASSWORD, 'password'), 'true', 5, 100, new NullCancellation());
        } catch (UnknownHostKey $e) {
            return $this->server($e->fingerprint);
        }
        self::fail('an unknown key must not be accepted');
    }

    public function testTheFirstConnectionStopsAtTheUnknownKey(): void {
        try {
            (new AmpSshShell())->run(new Connection($this->server(null), self::PASSWORD, 'password'), 'id', 5, 100, new NullCancellation());
            self::fail('connected to an unknown host');
        } catch (UnknownHostKey $e) {
            self::assertStringStartsWith('SHA256:', $e->fingerprint);
            self::assertFalse($e->changed);
        }
    }

    public function testACommandRunsWithItsOutputAndCode(): void {
        $result = (new AmpSshShell())->run(new Connection($this->trusted(), self::PASSWORD, 'password'), 'echo hi; id -un; echo oops >&2; exit 3', 10, 10_000, new NullCancellation());

        self::assertSame(3, $result->exitCode);
        self::assertSame("hi\ntester\n", $result->stdout);
        self::assertSame("oops\n", $result->stderr);
        self::assertFalse($result->truncated);
    }

    public function testStdinIsClosedAndAQuoteIsKept(): void {
        $result = (new AmpSshShell())->run(new Connection($this->trusted(), self::PASSWORD, 'password'), "cat; echo \"it's done\"", 10, 10_000, new NullCancellation());

        self::assertSame(0, $result->exitCode, 'cat does not wait for input');
        self::assertSame("it's done\n", $result->stdout);
    }

    public function testThePasswordOfSudoOpensTheTicketAndReachesNoCommand(): void {
        $connection = new Connection($this->trusted(), self::PASSWORD, 'password');
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the way ServerConsole runs `sudo id -u` and `sudo cat`
        $id = (new AmpSshShell())->run($connection, "sudo -S -p '' -v && sudo -n id -u < /dev/null", 10, 10_000, new NullCancellation(), self::PASSWORD . "\n");
        self::assertSame("0\n", $id->stdout, $id->stderr);
        $cat = (new AmpSshShell())->run($connection, "sudo -S -p '' -v && sudo -n cat < /dev/null", 10, 10_000, new NullCancellation(), self::PASSWORD . "\n");
        self::assertSame(0, $cat->exitCode, $cat->stderr);
        self::assertStringNotContainsString(self::PASSWORD, $cat->stdout, 'the command does not read the password');
    }

    public function testTheServerStopsALongCommand(): void {
        $result = (new AmpSshShell())->run(new Connection($this->trusted(), self::PASSWORD, 'password'), 'sleep 30', 1, 1000, new NullCancellation());

        self::assertSame(RemoteResult::TIMED_OUT, $result->exitCode);
        self::assertLessThan(10_000, $result->durationMs);
    }

    public function testOutputIsKeptWithinTheLimit(): void {
        $result = (new AmpSshShell())->run(new Connection($this->trusted(), self::PASSWORD, 'password'), 'head -c 200000 /dev/zero | tr "\0" a', 10, 1000, new NullCancellation());

        self::assertSame(0, $result->exitCode);
        self::assertSame(1000, strlen($result->stdout));
        self::assertTrue($result->truncated);
    }

    public function testAPrivateKeyLogsIn(): void {
        $server = $this->trusted();
        $key = (new AmpSshShell())->run(new Connection($server, self::PASSWORD, 'password'), 'cat ~/.ssh/id_ed25519', 10, 10_000, new NullCancellation())->stdout;
        self::assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $key);

        $result = (new AmpSshShell())->run(new Connection($server, $key, 'private_key'), 'id -un', 10, 1000, new NullCancellation());

        self::assertSame("tester
", $result->stdout);
        self::assertSame([], (array) glob('/dev/shm/mercurius-key-*'), 'the key file is gone');
    }

    public function testAJumpGoesThroughTheServerInBetween(): void {
        $server = $this->trusted();
        // The test server is its own server in between: the tunnel from it leads back to its own port 22.
        $self = new Server(2, 'inner', '127.0.0.1', 22, 'tester', 1, 'test', $server->hostKey, null, 'via:test');
        $target = new Connection($self, self::PASSWORD, 'password', null, new Connection($server, self::PASSWORD, 'password'));

        $result = (new AmpSshShell())->run($target, 'echo through', 10, 1000, new NullCancellation());

        self::assertSame("through
", $result->stdout);
    }

    public function testAFileIsWrittenThroughCat(): void {
        $connection = new Connection($this->trusted(), self::PASSWORD, 'password');
        $content = "line 1
строка 2
" . str_repeat('x', 100_000);

        (new AmpSshShell())->run($connection, "cat > '/tmp/written.txt'", 10, 100, new NullCancellation(), $content);
        $back = (new AmpSshShell())->run($connection, 'cat /tmp/written.txt', 10, 200_000, new NullCancellation());

        self::assertSame($content, $back->stdout);
    }

    public function testAChangedKeyAndAWrongPasswordAreRefused(): void {
        try {
            (new AmpSshShell())->run(new Connection($this->server('SHA256:somethingelse'), self::PASSWORD, 'password'), 'id', 5, 100, new NullCancellation());
            self::fail('a changed key was accepted');
        } catch (UnknownHostKey $e) {
            self::assertTrue($e->changed);
        }
        $this->expectException(RemoteFailure::class);
        $this->expectExceptionMessage('вход отклонён');
        (new AmpSshShell())->run(new Connection($this->trusted(), 'wrong', 'password'), 'id', 5, 100, new NullCancellation());
    }
}
