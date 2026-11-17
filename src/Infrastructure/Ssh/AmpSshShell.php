<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Ssh;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Future;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Ssh\Authentication\Authentication;
use Amp\Ssh\Authentication\PublicKey;
use Amp\Ssh\Authentication\UsernamePassword;
use Amp\Ssh\HostKey\HostKeyVerifier;
use Amp\Ssh\Process;
use Amp\TimeoutCancellation;
use OpenCCK\App\Server\RemoteFailure;
use OpenCCK\App\Server\RemoteResult;
use OpenCCK\App\Server\RemoteShell;
use OpenCCK\App\Server\UnknownHostKey;
use OpenCCK\Domain\Server\Server;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\File\deleteFile;
use function Amp\File\write;
use function Amp\Ssh\connect;

/**
 * SSH on amphp-ssh: one connection per command, the host key pinned, the command wrapped in `timeout` on the server
 * (the library cannot stop a remote command reliably), stdin closed, output kept within a limit and drained past it.
 *
 * spec: BRIEF.md#фаза-7-по-частям — timeout on the server, output limit, stdin from /dev/null, a host key change refuses
 * ref: references/amphp-ssh/src/functions.php@9d171ac:L37-L91 (connect) — no timeout of its own, so one is given;
 *      references/amphp-ssh/src/Process.php@9d171ac:L58-L144 — kill() is advice, join() only detaches, stdin needs end()
 */
final class AmpSshShell implements RemoteShell {
    private const CONNECT_SECONDS = 20;

    public function run(Server $server, #[SensitiveParameter] string $credential, string $kind, string $command, int $timeout, int $maxOutput, Cancellation $cancellation): RemoteResult {
        $started = hrtime(true);
        $keyFile = null;
        try {
            if ($kind === 'private_key') {
                // The library reads a key only from a file: a private one, removed right after the login.
                $keyFile = sys_get_temp_dir() . '/mercurius-key-' . bin2hex(random_bytes(12));
                write($keyFile, $credential);
                chmod($keyFile, 0600);
                $auth = new PublicKey($server->username, $keyFile);
            } else {
                $auth = new UsernamePassword($server->username, $credential);
            }
            $ssh = $this->connect($server, $auth, $cancellation);
        } finally {
            if ($keyFile !== null) {
                try {
                    deleteFile($keyFile);
                } catch (Throwable) {
                }
            }
        }
        try {
            $process = new Process($ssh, sprintf('timeout --kill-after=5 %d sh -c %s', $timeout, self::quote($command)));
            $process->start();
            $process->getStdin()->end();
            $deadline = new CompositeCancellation($cancellation, new TimeoutCancellation((float) $timeout + 15.0));
            $stdout = async(static fn(): Drained => self::drain($process->getStdout(), $maxOutput, $deadline));
            $stderr = async(static fn(): Drained => self::drain($process->getStderr(), $maxOutput, $deadline));
            $out = self::drained($stdout);
            $err = self::drained($stderr);
            $exit = $process->join($deadline);
            return new RemoteResult($exit === false ? null : $exit, $out->kept, $err->kept, $out->cut || $err->cut, intdiv(hrtime(true) - $started, 1_000_000));
        } catch (CancelledException) {
            throw new RemoteFailure(sprintf('команда не закончилась за %d с: соединение закрыто', $timeout));
        } catch (Throwable $e) {
            throw new RemoteFailure('ошибка выполнения: ' . self::reason($e));
        } finally {
            $ssh->close();
        }
    }

    private function connect(Server $server, Authentication $auth, Cancellation $cancellation): \Amp\Ssh\SshResource {
        $verifier = new class($server->hostKey) implements HostKeyVerifier {
            public function __construct(private readonly ?string $pinned) {}

            public function verify(string $host, int $port, string $format, string $key): void {
                $fingerprint = AmpSshShell::fingerprint($key);
                if ($this->pinned === null || !hash_equals($this->pinned, $fingerprint)) {
                    throw new UnknownHostKey($fingerprint, $this->pinned !== null);
                }
            }
        };
        try {
            return connect(
                $server->address(),
                $auth,
                cancellation: new CompositeCancellation($cancellation, new TimeoutCancellation(self::CONNECT_SECONDS)),
                hostKeyVerifier: $verifier,
            );
        } catch (UnknownHostKey $e) {
            throw $e;
        } catch (CancelledException) {
            throw new RemoteFailure(sprintf('не удалось подключиться к %s за %d с', $server->address(), self::CONNECT_SECONDS));
        } catch (Throwable $e) {
            throw new RemoteFailure(sprintf('не удалось подключиться к %s: %s', $server->address(), self::reason($e)));
        }
    }

    /** SHA256:… as ssh-keygen -lf shows it. */
    public static function fingerprint(string $key): string {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $key, true)), '=');
    }

    /** A word for the shell, single-quoted. */
    public static function quote(string $text): string {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }

    /**
     * Reads a stream to its end, keeping at most $max bytes: the rest is read and dropped so the command is not stuck
     * on a full window.
     *
     */
    private static function drain(ReadableStream $stream, int $max, Cancellation $cancellation): Drained {
        $kept = '';
        $total = 0;
        while (($chunk = $stream->read($cancellation)) !== null) {
            $total += strlen($chunk);
            if (strlen($kept) < $max) {
                $kept .= substr($chunk, 0, $max - strlen($kept));
            }
        }
        return new Drained($kept, $total > $max);
    }

    /** @param Future<mixed> $future */
    private static function drained(Future $future): Drained {
        $value = $future->await();
        if (!$value instanceof Drained) {
            throw new RemoteFailure('вывод команды не прочитан');
        }
        return $value;
    }

    private static function reason(Throwable $e): string {
        return match (true) {
            $e instanceof \Amp\Ssh\Authentication\AuthenticationFailureException => 'вход отклонён (логин, пароль или ключ)',
            $e instanceof \Amp\Socket\ConnectException => 'хост не отвечает',
            default => (new \ReflectionClass($e))->getShortName(),
        };
    }
}
