<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Ssh;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Future;
use Amp\Socket\SocketConnector;
use Amp\Ssh\Authentication\PublicKey;
use Amp\Ssh\Authentication\UsernamePassword;
use Amp\Ssh\HostKey\HostKeyVerifier;
use Amp\Ssh\Process;
use Amp\Ssh\SshResource;
use Amp\TimeoutCancellation;
use OpenCCK\App\Server\Connection;
use OpenCCK\App\Server\RemoteFailure;
use OpenCCK\App\Server\RemoteResult;
use OpenCCK\App\Server\RemoteShell;
use OpenCCK\App\Server\UnknownHostKey;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\File\deleteFile;
use function Amp\File\write;
use function Amp\Ssh\connect;

/**
 * SSH on amphp-ssh: one connection per command (and one to the server in between for a jump), the host key pinned,
 * the command wrapped in `timeout` on the server (the library cannot stop a remote command reliably), stdin given and
 * closed, output kept within a limit and drained past it.
 *
 * spec: BRIEF.md#фаза-7-по-частям — timeout on the server, output limit, stdin closed, a host key change refuses, routes
 * ref: references/amphp-ssh/src/functions.php@9d171ac:L37-L91 (connect) — no timeout of its own, so one is given, and a
 *      connector of the caller's choosing for proxies, VLESS and jumps; references/amphp-ssh/src/Process.php@9d171ac:
 *      L58-L144 — kill() is advice, join() only detaches, stdin needs end()
 */
final class AmpSshShell implements RemoteShell {
    private const CONNECT_SECONDS = 20;
    /** RAM, not disk, for the moment a private key must be a file */
    private const KEY_DIR = '/dev/shm';

    public function run(Connection $target, string $command, int $timeout, int $maxOutput, Cancellation $cancellation, #[SensitiveParameter] ?string $stdin = null): RemoteResult {
        $started = hrtime(true);
        $jump = null;
        $ssh = null;
        try {
            if ($target->via !== null) {
                $jump = $this->connect($target->via, $target->via->connector, $cancellation);
            }
            $ssh = $this->connect($target, $jump === null ? $target->connector : new TunnelConnector($jump), $cancellation);
            $process = new Process($ssh, sprintf('timeout --kill-after=5 %d sh -c %s', $timeout, self::quote($command)));
            $process->start();
            if ($stdin !== null) {
                $process->getStdin()->write($stdin);
            }
            $process->getStdin()->end();
            $deadline = new CompositeCancellation($cancellation, new TimeoutCancellation((float) $timeout + 15.0));
            $stdout = async(static fn(): Drained => self::drain($process->getStdout(), $maxOutput, $deadline));
            $stderr = async(static fn(): Drained => self::drain($process->getStderr(), $maxOutput, $deadline));
            $out = self::drained($stdout);
            $err = self::drained($stderr);
            $exit = $process->join($deadline);
            return new RemoteResult($exit === false ? null : $exit, $out->kept, $err->kept, $out->cut || $err->cut, intdiv(hrtime(true) - $started, 1_000_000));
        } catch (UnknownHostKey|RemoteFailure $e) {
            throw $e;
        } catch (CancelledException) {
            throw new RemoteFailure(sprintf('команда не закончилась за %d с: соединение закрыто', $timeout));
        } catch (Throwable $e) {
            throw new RemoteFailure('ошибка выполнения: ' . self::reason($e));
        } finally {
            $ssh?->close();
            $jump?->close();
        }
    }

    private function connect(Connection $target, ?SocketConnector $connector, Cancellation $cancellation): SshResource {
        $server = $target->server;
        $verifier = new class($server->hostKey) implements HostKeyVerifier {
            public function __construct(private readonly ?string $pinned) {}

            public function verify(string $host, int $port, string $format, string $key): void {
                $fingerprint = AmpSshShell::fingerprint($key);
                if ($this->pinned === null || !hash_equals($this->pinned, $fingerprint)) {
                    throw new UnknownHostKey($fingerprint, $this->pinned !== null);
                }
            }
        };
        $keyFile = null;
        try {
            if ($target->kind === 'private_key') {
                // The library reads a key only from a file: a private one in RAM, removed right after the login.
                $keyFile = (is_dir(self::KEY_DIR) && is_writable(self::KEY_DIR) ? self::KEY_DIR : sys_get_temp_dir()) . '/mercurius-key-' . bin2hex(random_bytes(12));
                write($keyFile, $target->credential);
                chmod($keyFile, 0600);
                $auth = new PublicKey($server->username, $keyFile);
            } else {
                $auth = new UsernamePassword($server->username, $target->credential);
            }
            return connect(
                $server->address(),
                $auth,
                cancellation: new CompositeCancellation($cancellation, new TimeoutCancellation(self::CONNECT_SECONDS)),
                hostKeyVerifier: $verifier,
                connector: $connector,
            );
        } catch (UnknownHostKey $e) {
            throw new UnknownHostKey($e->fingerprint, $e->changed, $server->name);
        } catch (CancelledException) {
            throw new RemoteFailure(sprintf('не удалось подключиться к %s за %d с', $server->address(), self::CONNECT_SECONDS));
        } catch (Throwable $e) {
            throw new RemoteFailure(sprintf('не удалось подключиться к %s: %s', $server->address(), self::reason($e)));
        } finally {
            if ($keyFile !== null) {
                try {
                    deleteFile($keyFile);
                } catch (Throwable) {
                }
            }
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
