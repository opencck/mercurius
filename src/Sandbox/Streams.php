<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * Long-lived processes of participants behind the executor: spawn, send to stdin, receive stdout by long polling,
 * close. A session belongs to the participant who spawned it; there are at most a few per participant; one nobody
 * touched for half an hour is killed, and so is one that ended and was read.
 *
 * spec: brief/agent-loop.md#песочница — the streaming API of the executor (stdio MCP servers run on it)
 */
final class Streams {
    public const PER_PRINCIPAL = 4;
    /** in each pool: the members' processes and the MCP servers' are counted apart */
    public const TOTAL = 32;
    /** holders from here on are the processes of MCP servers */
    public const MCP_BASE = 900_000;
    public const POOL_MEMBERS = 'members';
    public const POOL_MCP = 'mcp';
    public const IDLE_SECONDS = 1800.0;
    public const BUFFER_BYTES = 4 * 1024 * 1024;
    public const RECEIVE_MAX = 1024 * 1024;
    public const WAIT_MAX = 25;
    public const ENV_MAX = CommandRunner::ENV_MAX;

    /** @var array<string, StreamSession> */
    private array $sessions = [];

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly Users $users,
        private readonly Workspace $workspace,
    ) {}

    /** @return self::POOL_* the count a holder's sessions go into */
    public static function pool(int $principalId): string {
        return $principalId >= self::MCP_BASE ? self::POOL_MCP : self::POOL_MEMBERS;
    }

    /**
     * @param array<string, string> $env extra variables (a server's token): they go into the environment of the
     *                                     process, never into its arguments, which other users could see in /proc
     * @throws SandboxError
     */
    public function spawn(int $principalId, SandboxLimits $limits, string $command, string $cwd, array $env): string {
        // spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — MCP servers have a count of their own
        $pool = self::pool($principalId);
        if (count(array_filter($this->sessions, static fn(StreamSession $s): bool => self::pool($s->principalId) === $pool)) >= self::TOTAL) {
            throw new SandboxError(sprintf(t('sandbox.streams.too_many'), self::TOTAL), 429);
        }
        $own = count(array_filter($this->sessions, static fn(StreamSession $s): bool => $s->principalId === $principalId));
        if ($own >= self::PER_PRINCIPAL) {
            throw new SandboxError(sprintf(t('sandbox.streams.too_many'), self::PER_PRINCIPAL), 429);
        }
        CommandRunner::checkEnv($env);
        $this->users->ensure($principalId);
        $id = bin2hex(random_bytes(16));
        $base = $this->runner->baseEnv($principalId);
        $this->sessions[$id] = new StreamSession(
            $id,
            $principalId,
            $this->runner->streamArgv($principalId, $limits, $cwd),
            $this->workspace->home($principalId),
            $base + $env,
            self::BUFFER_BYTES,
            prelude: CommandRunner::input($command),
        );
        return $id;
    }

    /** @throws SandboxError */
    public function send(int $principalId, string $id, string $data): void {
        $this->session($principalId, $id)->send($data);
    }

    /**
     * @param positive-int $max
     * @return array{stdout: string, exited: bool, exit_code: ?int, stderr: string}
     * @throws SandboxError
     */
    public function receive(int $principalId, string $id, int $max, float $wait): array {
        $received = $this->session($principalId, $id)->receive(min($max, self::RECEIVE_MAX), min($wait, (float) self::WAIT_MAX));
        if ($received['exited']) {
            $this->close($principalId, $id);
        }
        return $received;
    }

    public function close(int $principalId, string $id): bool {
        $session = $this->sessions[$id] ?? null;
        if ($session === null || $session->principalId !== $principalId) {
            return false;
        }
        unset($this->sessions[$id]);
        $session->stop();
        return true;
    }

    /**
     * Closes every session of a holder: the processes an application left behind when it restarted.
     *
     * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам — orphans are cleared before a new start
     */
    public function closeAll(int $principalId): int {
        $closed = 0;
        foreach ($this->sessions as $id => $session) {
            if ($session->principalId === $principalId) {
                unset($this->sessions[$id]);
                $session->stop();
                $closed++;
            }
        }
        return $closed;
    }

    /** Kills sessions nobody touched for IDLE_SECONDS. */
    public function reap(?float $now = null): int {
        $now ??= microtime(true);
        $reaped = 0;
        foreach ($this->sessions as $id => $session) {
            if ($now - $session->lastUsed >= self::IDLE_SECONDS) {
                unset($this->sessions[$id]);
                $session->stop();
                $reaped++;
            }
        }
        return $reaped;
    }

    public function stopAll(): void {
        foreach ($this->sessions as $session) {
            $session->stop();
        }
        $this->sessions = [];
    }

    public function count(): int {
        return count($this->sessions);
    }

    /** @throws SandboxError */
    private function session(int $principalId, string $id): StreamSession {
        $session = $this->sessions[$id] ?? null;
        // someone else's session looks the same as a missing one
        if ($session === null || $session->principalId !== $principalId) {
            throw new SandboxError(t('sandbox.streams.no_session'), 404);
        }
        return $session;
    }
}
