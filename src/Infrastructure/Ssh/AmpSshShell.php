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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\File\deleteFile;
use function Amp\File\write;
use function Amp\Ssh\connect;

use function Mercurius\I18n\t;

/**
 * SSH on amphp-ssh: connections kept in a pool, one per server with its login and route, so a series of commands goes
 * through one (and one to the server in between for a jump); the host key pinned,
 * the command wrapped in `timeout` on the server (the library cannot stop a remote command reliably), stdin given and
 * closed, output kept within a limit and drained past it.
 *
 * spec: brief/servers.md#фаза-7-по-частям — timeout on the server, output limit, stdin closed, a host key change refuses, routes
 * ref: references/amphp-ssh/src/functions.php@9d171ac:L37-L91 (connect) — no timeout of its own, so one is given, and a
 *      connector of the caller's choosing for proxies, VLESS and jumps; references/amphp-ssh/src/Process.php@9d171ac:
 *      L58-L144 — kill() is advice, join() only detaches, stdin needs end()
 */
final class AmpSshShell implements RemoteShell {
    private const CONNECT_SECONDS = 20;
    /** RAM, not disk, for the moment a private key must be a file */
    private const KEY_DIR = '/dev/shm';
    /** seconds between sweeps of idle connections */
    private const SWEEP_SECONDS = 30;

    private readonly ConnectionPool $pool;
    private ?string $sweeper = null;
    private int $nextId = 0;

    public function __construct(
        ?ConnectionPool $pool = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->pool = $pool ?? new ConnectionPool();
    }

    public function __destruct() {
        if ($this->sweeper !== null) {
            EventLoop::cancel($this->sweeper);
        }
        $this->pool->closeAll();
    }

    /** @return list<PooledConnection> the connections kept open now */
    public function pooled(): array {
        return $this->pool->open();
    }

    public function run(Connection $target, string $command, int $timeout, int $maxOutput, Cancellation $cancellation, #[SensitiveParameter] ?string $stdin = null): RemoteResult {
        $started = hrtime(true);
        $this->sweeper ??= EventLoop::unreference(EventLoop::repeat(self::SWEEP_SECONDS, function (): void {
            $this->pool->sweep();
        }));
        $key = self::key($target);
        $retried = false;
        while (true) {
            $lease = $this->pool->acquire($key, fn(): PooledConnection => $this->open($target, $cancellation));
            $ssh = $lease->resource;
            if (!$ssh instanceof SshResource) {
                $this->pool->release($lease, broken: true);
                throw new \LogicException('A pooled SSH connection holds no SSH resource');
            }
            $process = new Process($ssh, sprintf('timeout --kill-after=5 %d sh -c %s', $timeout, self::quote($command)));
            try {
                $process->start();
            } catch (Throwable $e) {
                // a kept connection that died quietly: nothing ran yet, so one fresh connection is tried
                $this->pool->release($lease, broken: true);
                if ($lease->commands > 1 && !$retried) {
                    $retried = true;
                    continue;
                }
                throw new RemoteFailure(t('ssh.amp_ssh_shell.oshibka_vypolneniya') . self::reason($e));
            }
            return $this->finish($lease, $process, $timeout, $maxOutput, $cancellation, $stdin, $started);
        }
    }

    private function finish(PooledConnection $lease, Process $process, int $timeout, int $maxOutput, Cancellation $cancellation, #[SensitiveParameter] ?string $stdin, int $started): RemoteResult {
        $broken = true;
        try {
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
            $broken = false;
            return new RemoteResult($exit === false ? null : $exit, $out->kept, $err->kept, $out->cut || $err->cut, intdiv(hrtime(true) - $started, 1_000_000));
        } catch (UnknownHostKey|RemoteFailure $e) {
            throw $e;
        } catch (CancelledException) {
            throw new RemoteFailure(sprintf(t('ssh.amp_ssh_shell.komanda_ne_zakonchilas_za_s'), $timeout));
        } catch (Throwable $e) {
            throw new RemoteFailure(t('ssh.amp_ssh_shell.oshibka_vypolneniya') . self::reason($e));
        } finally {
            // a command that failed or was stopped may leave its channel behind: that connection is not reused
            $this->pool->release($lease, $broken);
        }
    }

    /** Opens a connection for the pool: to the server in between first when there is one. */
    private function open(Connection $target, Cancellation $cancellation): PooledConnection {
        $jump = null;
        if ($target->via !== null) {
            $jump = $this->connect($target->via, $target->via->connector, $cancellation);
        }
        try {
            $ssh = $this->connect($target, $jump === null ? $target->connector : new TunnelConnector($jump), $cancellation);
        } catch (Throwable $e) {
            $jump?->close();
            throw $e;
        }
        $id = ++$this->nextId;
        $server = $target->server->name;
        $this->logger->info('SSH connection opened', ['server' => $server, 'connection' => $id]);
        return new PooledConnection(
            $id,
            static fn(): bool => !$ssh->isClosed() && ($jump === null || !$jump->isClosed()),
            function (PooledConnection $connection) use ($ssh, $jump, $server, $id): void {
                // each on its own: the server in between may be closed already, and that one must close still
                foreach ([$ssh, $jump] as $resource) {
                    try {
                        $resource?->close();
                    } catch (Throwable) {
                    }
                }
                $this->logger->info('SSH connection closed', ['server' => $server, 'connection' => $id, 'commands' => $connection->commands]);
            },
            $ssh,
        );
    }

    /**
     * What makes two commands share a connection: the same server, login, trusted key and route, the same server in
     * between. The secret is only hashed.
     */
    private static function key(Connection $target): string {
        $server = $target->server;
        $part = implode("\0", [$server->id, $server->host, $server->port, $server->username, $server->hostKey ?? '', $server->route ?? '', $target->kind, hash('sha256', $target->credential)]);
        return hash('sha256', $part . "\0" . ($target->via === null ? '' : self::key($target->via)));
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
                // the file is empty while it is open to others: rights first, then the key
                write($keyFile, '');
                chmod($keyFile, 0600);
                write($keyFile, $target->credential);
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
            throw new RemoteFailure(sprintf(t('ssh.amp_ssh_shell.ne_udalos_podklyuchitsya_k_za'), $server->address(), self::CONNECT_SECONDS));
        } catch (Throwable $e) {
            throw new RemoteFailure(sprintf(t('ssh.amp_ssh_shell.ne_udalos_podklyuchitsya_k'), $server->address(), self::reason($e)));
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
            throw new RemoteFailure(t('ssh.amp_ssh_shell.vyvod_komandy_ne_prochitan'));
        }
        return $value;
    }

    private static function reason(Throwable $e): string {
        return match (true) {
            $e instanceof \Amp\Ssh\Authentication\AuthenticationFailureException => t('ssh.amp_ssh_shell.vhod_otklonen_login_parol_ili'),
            $e instanceof \Amp\Socket\ConnectException => t('ssh.amp_ssh_shell.host_ne_otvechaet'),
            default => (new \ReflectionClass($e))->getShortName(),
        };
    }
}
