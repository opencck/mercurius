<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Sandbox\SandboxStreams;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Amp\delay;

/**
 * Long-lived processes that play an MCP server over stdio: each line written is a JSON-RPC message; answers go to
 * the output, which receive() hands out with a short wait.
 */
final class FakeSandboxStreams implements SandboxStreams {
    /** @var list<array{int, string, array<string, string>}> principal, command, env of every spawn */
    public array $spawned = [];
    /** @var list<array<array-key, mixed>> every message written, in order */
    public array $written = [];
    /** @var list<array<array-key, mixed>> tools the server lists */
    public array $tools = [['name' => 'echo', 'description' => 'Echoes', 'inputSchema' => ['type' => 'object']]];
    /** @var array<string, string> output waiting per session */
    private array $output = [];
    /** @var array<string, array{int, string}> sessions that ended: exit code, stderr */
    private array $ended = [];
    /** @var array<string, bool> */
    public array $closed = [];
    /** answer tools/call after this many seconds */
    public float $callDelay = 0.0;

    public function spawn(int $principalId, SandboxLimits $limits, string $command, array $env, Cancellation $cancellation): string {
        $id = 's' . (count($this->spawned) + 1);
        $this->spawned[] = [$principalId, $command, $env];
        $this->output[$id] = '';
        return $id;
    }

    public function send(int $principalId, SandboxLimits $limits, string $session, string $data, Cancellation $cancellation): void {
        if (isset($this->ended[$session]) || isset($this->closed[$session])) {
            throw new SandboxFailure('Процесс завершился и больше не читает ввод.');
        }
        foreach (explode("\n", trim($data)) as $line) {
            /** @var array<array-key, mixed> $message */
            $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            $this->written[] = $message;
            $this->answer($session, $message);
        }
    }

    public function receive(int $principalId, SandboxLimits $limits, string $session, int $wait, Cancellation $cancellation): array {
        $until = microtime(true) + (float) min($wait, 1);
        while (($this->output[$session] ?? '') === '' && !isset($this->ended[$session]) && !isset($this->closed[$session]) && microtime(true) < $until) {
            delay(0.01, cancellation: $cancellation);
        }
        $out = $this->output[$session] ?? '';
        $this->output[$session] = '';
        $end = $out === '' ? ($this->ended[$session] ?? null) : null;
        if (isset($this->closed[$session]) && $out === '') {
            throw new SandboxFailure('Такого процесса нет: он завершился или закрыт.');
        }
        return ['stdout' => $out, 'exited' => $end !== null, 'exitCode' => $end[0] ?? null, 'stderr' => $end[1] ?? ''];
    }

    public function close(int $principalId, SandboxLimits $limits, string $session): void {
        $this->closed[$session] = true;
    }

    /** The server writes a line on its own: a notification or a request. */
    public function push(string $session, string $line): void {
        $this->output[$session] = ($this->output[$session] ?? '') . $line . "\n";
    }

    public function end(string $session, int $code, string $stderr): void {
        $this->ended[$session] = [$code, $stderr];
    }

    /** @param array<array-key, mixed> $message */
    private function answer(string $session, array $message): void {
        /** @var mixed $id */
        $id = $message['id'] ?? null;
        /** @var mixed $method */
        $method = $message['method'] ?? null;
        if ($id === null || !is_string($method)) {
            return;
        }
        $result = match ($method) {
            'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => (object) []]],
            'tools/list' => ['tools' => $this->tools],
            'tools/call' => ['content' => [['type' => 'text', 'text' => 'echo:' . json_encode($message['params'] ?? null, JSON_THROW_ON_ERROR)]]],
            default => null,
        };
        $reply = $result === null
            ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        if ($method === 'tools/call' && $this->callDelay > 0) {
            \Revolt\EventLoop::delay($this->callDelay, fn() => $this->push($session, json_encode($reply, JSON_THROW_ON_ERROR)));
            return;
        }
        $this->push($session, json_encode($reply, JSON_THROW_ON_ERROR));
    }
}
