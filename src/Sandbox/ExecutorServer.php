<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\DeferredCancellation;
use Amp\Http\HttpStatus;
use Amp\Http\Server\Request as HttpRequest;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The executor's HTTP API. POST /v1/<operation> with a JSON body and a bearer token; GET /health without one.
 * Refusals keep their text for the model; anything unexpected becomes a generic error, the class goes to the log.
 *
 * spec: brief/agent-loop.md#песочница — executor behind a token, only the sandbox network
 */
final class ExecutorServer implements RequestHandler {
    /** folders the archive of the working folders leaves out, by name at any depth */
    private const ARCHIVE_SKIPPED = ['node_modules', '__pycache__', '.venv', 'venv', 'site-packages', '.cache', '.npm', '.tox', '.nox', '.pytest_cache', '.mypy_cache', '.ruff_cache'];

    /** a file of 25 MB in base64 with its JSON: files the agent receives and sends go through here */
    private const MAX_BODY = 40 * 1024 * 1024;

    /** @var array<string, DeferredCancellation> running exec calls by call id */
    private array $running = [];

    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        private readonly Workspace $workspace,
        private readonly Runner $runner,
        private readonly FileStore $files,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** long-lived processes; null — the streaming API is off */
        private readonly ?Streams $streams = null,
    ) {}

    public function handleRequest(HttpRequest $request): Response {
        $path = $request->getUri()->getPath();
        if ($path === '/health') {
            return self::json(HttpStatus::OK, ['ok' => true]);
        }
        // Participants' commands share this network namespace and connect over loopback; the core never does.
        $peer = $request->getClient()->getRemoteAddress()->toString();
        if (str_starts_with($peer, '127.') || str_starts_with($peer, '[::1]')) {
            return self::json(HttpStatus::FORBIDDEN, ['ok' => false, 'error' => t('sandbox.executor_server.net_dostupa')]);
        }
        if ($request->getMethod() === 'POST' && ($path === '/archive' || $path === '/unarchive')) {
            if (!hash_equals('Bearer ' . $this->token, $request->getHeader('authorization') ?? '')) {
                return self::json(HttpStatus::UNAUTHORIZED, ['ok' => false, 'error' => t('sandbox.executor_server.net_dostupa')]);
            }
            return $path === '/archive' ? $this->archive() : $this->unarchive($request);
        }
        if ($request->getMethod() !== 'POST' || !str_starts_with($path, '/v1/')) {
            return self::json(HttpStatus::NOT_FOUND, ['ok' => false, 'error' => t('sandbox.executor_server.net_takoy_operatsii')]);
        }
        if (!hash_equals('Bearer ' . $this->token, $request->getHeader('authorization') ?? '')) {
            return self::json(HttpStatus::UNAUTHORIZED, ['ok' => false, 'error' => t('sandbox.executor_server.net_dostupa')]);
        }

        try {
            $request->getBody()->increaseSizeLimit(self::MAX_BODY);
            $parsed = Request::parse(substr($path, 4), $request->getBody()->buffer());
            return self::json(HttpStatus::OK, ['ok' => true] + $this->dispatch($parsed));
        } catch (SandboxError $e) {
            return self::json($e->status, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->error('Executor failed', ['error' => $e::class]);
            return self::json(HttpStatus::INTERNAL_SERVER_ERROR, ['ok' => false, 'error' => t('sandbox.executor_server.vnutrennyaya_oshibka_pesochnitsy')]);
        }
    }

    /**
     * The working folders of all participants as tar.gz, a stream: for the archive of the agent. Read as root, the
     * ownership of every file kept; dependencies and caches stay out, they are installed again.
     *
     * ref: references/hermes-agent/hermes_cli/backup.py@e96221fa:L79-L97 (_EXCLUDED_DIRS) — the same dependency and cache
     *      folders; .git is kept here, a participant's repository may hold work not pushed anywhere
     *
     * spec: brief/operations.md#экспорт-и-импорт-агента — the working folders go into the archive through the executor
     */
    private function archive(): Response {
        $excluded = array_map(static fn(string $name): string => '--exclude=' . $name, self::ARCHIVE_SKIPPED);
        $process = \Amp\Process\Process::start(['tar', '--numeric-owner', ...$excluded, '-czf', '-', '-C', $this->workspace->root, '.']);
        \Amp\async(static function () use ($process): void {
            \Amp\ByteStream\buffer($process->getStderr());
            $process->join();
        })->ignore();
        return new Response(HttpStatus::OK, ['content-type' => 'application/gzip'], $process->getStdout());
    }

    /**
     * The working folders back from a tar.gz of archive(): unpacked over /workspace with their owners.
     *
     * spec: brief/operations.md#экспорт-и-импорт-агента — a full import brings the working folders back
     */
    private function unarchive(HttpRequest $request): Response {
        $request->getBody()->increaseSizeLimit(8 * 1024 * 1024 * 1024);
        $process = \Amp\Process\Process::start(['tar', '--numeric-owner', '-xzf', '-', '-C', $this->workspace->root]);
        try {
            \Amp\ByteStream\pipe($request->getBody(), $process->getStdin());
            $process->getStdin()->close();
            $error = \Amp\ByteStream\buffer($process->getStderr());
            $code = $process->join();
        } catch (Throwable $e) {
            $process->kill();
            $this->logger->error('Workspace not unpacked', ['error' => $e::class]);
            return self::json(HttpStatus::INTERNAL_SERVER_ERROR, ['ok' => false, 'error' => t('sandbox.executor_server.vnutrennyaya_oshibka_pesochnitsy')]);
        }
        return $code === 0
            ? self::json(HttpStatus::OK, ['ok' => true])
            : self::json(HttpStatus::INTERNAL_SERVER_ERROR, ['ok' => false, 'error' => mb_substr(trim($error), 0, 500)]);
    }

    /**
     * @return array<string, mixed>
     * @throws SandboxError
     */
    private function dispatch(Request $request): array {
        $id = $request->principalId;
        $limits = $request->limits;
        return match ($request->operation) {
            'exec' => $this->exec($request),
            'read' => $this->files->read($id, $limits, $request->string('path'), $request->int('start_line', 1, 1, PHP_INT_MAX), $request->int('max_lines', 500, 1, 2000)),
            'write' => $this->write($request),
            'replace' => $this->replace($request),
            'list' => ['entries' => $this->files->list($id, $limits, $request->string('path', '.'), $request->int('depth', 1, 1, 3))],
            'usage' => ['bytes' => $this->files->usage($id)],
            'fetch' => ['content' => base64_encode($this->files->fetch($id, $limits, $request->string('path'), $request->int('max_bytes', 5_000_000, 1, 25_000_000)))],
            'cancel' => ['cancelled' => $this->cancel($request->callId)],
            // spec: brief/agent-loop.md#песочница — the streaming API: a process lives between the calls
            'spawn' => ['session' => $this->streams()->spawn($id, $limits, $request->string('command'), $this->workspace->resolve($id, $request->string('cwd', '.')), $request->env())],
            'send' => $this->send($request),
            'receive' => $this->streams()->receive($id, $request->string('session'), max(1, $request->int('max_bytes', Streams::RECEIVE_MAX, 1, Streams::RECEIVE_MAX)), (float) $request->int('wait', 20, 0, Streams::WAIT_MAX)),
            'close' => ['closed' => $this->streams()->close($id, $request->string('session'))],
            'close_all' => ['closed' => $this->streams()->closeAll($id)],
            default => throw new SandboxError(t('sandbox.executor_server.net_takoy_operatsii'), 404),
        };
    }

    /** @return array<string, int|string|bool> */
    private function exec(Request $request): array {
        $cwd = $this->workspace->resolve($request->principalId, $request->string('cwd', '.'));
        $env = $request->env();
        CommandRunner::checkEnv($env);
        $deferred = new DeferredCancellation();
        if ($request->callId !== '') {
            $this->running[$request->callId] = $deferred;
        }
        try {
            return $this->runner->run(
                $request->principalId,
                $request->limits,
                $request->string('command'),
                $cwd,
                max(1, $request->int('timeout', 120, 1, 600)),
                null,
                $deferred->getCancellation(),
                $env,
            )->toArray();
        } finally {
            unset($this->running[$request->callId]);
        }
    }

    /** @throws SandboxError */
    private function streams(): Streams {
        return $this->streams ?? throw new SandboxError(t('sandbox.executor_server.net_takoy_operatsii'), 404);
    }

    /** @return array<string, mixed> */
    private function send(Request $request): array {
        $this->streams()->send($request->principalId, $request->string('session'), $request->string('data'));
        return [];
    }

    private function cancel(string $callId): bool {
        $deferred = $this->running[$callId] ?? null;
        $deferred?->cancel();
        return $deferred !== null;
    }

    /** @return array<string, mixed> */
    private function write(Request $request): array {
        // a binary file (a screenshot, a file from another agent) comes in base64: JSON carries text only
        $encoded = $request->string('content_base64', '');
        $content = $encoded === '' ? $request->string('content') : base64_decode($encoded, true);
        if ($content === false) {
            throw new SandboxError(sprintf(t('sandbox.request.ne_ukazano_pole'), 'content_base64'));
        }
        $this->files->write($request->principalId, $request->limits, $request->string('path'), $content);
        return [];
    }

    /** @return array<string, mixed> */
    private function replace(Request $request): array {
        $this->files->replace($request->principalId, $request->limits, $request->string('path'), $request->string('old'), $request->string('new'));
        return [];
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): Response {
        return new Response($status, ['content-type' => 'application/json; charset=utf-8'], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
