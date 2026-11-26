<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Sandbox;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\HttpException;
use Amp\Http\Client\Request;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use JsonException;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\Domain\Agent\SandboxLimits;
use SensitiveParameter;

use function Amp\ByteStream\buffer;

use function Mercurius\I18n\t;

/**
 * Talks to the sandbox executor over the sandbox network with a bearer token. Cancelling an exec also asks the
 * executor to kill the command.
 *
 * spec: brief/agent-loop.md#песочница — executor behind a token
 */
final class HttpSandboxClient implements Sandbox, \OpenCCK\App\Agent\Sandbox\SandboxStreams, \OpenCCK\App\Agent\Sandbox\SandboxEnv, \OpenCCK\App\Agent\Sandbox\SandboxUsage {
    private const FILE_TIMEOUT = 60;
    private const EXTRA_SECONDS = 10;
    /** the archive of all working folders: an hour, up to 8 GB */
    private const ARCHIVE_TIMEOUT = 3600;
    private const ARCHIVE_BYTES = 8 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly DelegateHttpClient $http,
        private readonly string $url,
        #[SensitiveParameter] private readonly string $token,
    ) {}

    public function exec(int $principalId, SandboxLimits $limits, string $callId, string $command, string $cwd, int $timeoutSeconds, Cancellation $cancellation): CommandOutcome {
        return $this->execEnv($principalId, $limits, $callId, $command, $cwd, $timeoutSeconds, [], $cancellation);
    }

    public function execEnv(int $principalId, SandboxLimits $limits, string $callId, string $command, string $cwd, int $timeoutSeconds, #[SensitiveParameter] array $env, Cancellation $cancellation): CommandOutcome {
        $fields = ['command' => $command, 'cwd' => $cwd, 'timeout' => $timeoutSeconds] + ($env === [] ? [] : ['env' => (object) $env]);
        try {
            $body = $this->call('exec', $principalId, $limits, $fields, $timeoutSeconds + self::EXTRA_SECONDS, $cancellation, $callId);
        } catch (CancelledException $e) {
            if ($cancellation->isRequested()) {
                try {
                    $this->call('cancel', $principalId, $limits, [], self::EXTRA_SECONDS, new NullCancellation(), $callId);
                } catch (SandboxFailure) {
                    // The command may already be gone; the run is being cancelled either way.
                }
            }
            throw $e;
        }
        return new CommandOutcome(
            self::int($body, 'exit_code'),
            self::string($body, 'stdout'),
            self::string($body, 'stderr'),
            ($body['timed_out'] ?? false) === true,
            ($body['over_quota'] ?? false) === true,
            self::int($body, 'duration_ms'),
        );
    }

    public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines, Cancellation $cancellation): array {
        $body = $this->call('read', $principalId, $limits, ['path' => $path, 'start_line' => $startLine, 'max_lines' => $maxLines], self::FILE_TIMEOUT, $cancellation);
        return ['text' => self::string($body, 'text'), 'totalLines' => self::int($body, 'totalLines')];
    }

    public function write(int $principalId, SandboxLimits $limits, string $path, string $content, Cancellation $cancellation): void {
        $this->call('write', $principalId, $limits, ['path' => $path, 'content' => $content], self::FILE_TIMEOUT, $cancellation);
    }

    public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new, Cancellation $cancellation): void {
        $this->call('replace', $principalId, $limits, ['path' => $path, 'old' => $old, 'new' => $new], self::FILE_TIMEOUT, $cancellation);
    }

    public function usage(int $principalId, SandboxLimits $limits, Cancellation $cancellation): int {
        $body = $this->call('usage', $principalId, $limits, [], self::FILE_TIMEOUT, $cancellation);
        /** @var mixed $bytes */
        $bytes = $body['bytes'] ?? 0;
        return is_int($bytes) && $bytes > 0 ? $bytes : 0;
    }

    public function list(int $principalId, SandboxLimits $limits, string $path, int $depth, Cancellation $cancellation): array {
        $body = $this->call('list', $principalId, $limits, ['path' => $path, 'depth' => $depth], self::FILE_TIMEOUT, $cancellation);
        $list = isset($body['entries']) && is_array($body['entries']) ? $body['entries'] : [];
        $entries = [];
        foreach (array_keys($list) as $index) {
            $entry = self::entry($list[$index]);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    public function put(int $principalId, SandboxLimits $limits, string $path, string $bytes, Cancellation $cancellation): void {
        $this->call('write', $principalId, $limits, ['path' => $path, 'content_base64' => base64_encode($bytes)], self::FILE_TIMEOUT, $cancellation);
    }

    public function fetch(int $principalId, SandboxLimits $limits, string $path, int $maxBytes, Cancellation $cancellation): string {
        $body = $this->call('fetch', $principalId, $limits, ['path' => $path, 'max_bytes' => $maxBytes], self::FILE_TIMEOUT, $cancellation);
        $bytes = base64_decode(self::string($body, 'content'), true);
        if ($bytes === false) {
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_vernula_neponyatnyy_otvet'));
        }
        return $bytes;
    }

    public function spawn(int $principalId, SandboxLimits $limits, string $command, #[SensitiveParameter] array $env, Cancellation $cancellation): string {
        $body = $this->call('spawn', $principalId, $limits, ['command' => $command, 'env' => (object) $env], self::FILE_TIMEOUT, $cancellation);
        $session = self::string($body, 'session');
        if ($session === '') {
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_vernula_neponyatnyy_otvet'));
        }
        return $session;
    }

    public function send(int $principalId, SandboxLimits $limits, string $session, string $data, Cancellation $cancellation): void {
        $this->call('send', $principalId, $limits, ['session' => $session, 'data' => $data], self::FILE_TIMEOUT, $cancellation);
    }

    public function receive(int $principalId, SandboxLimits $limits, string $session, int $wait, Cancellation $cancellation): array {
        $wait = max(0, min(25, $wait));
        $body = $this->call('receive', $principalId, $limits, ['session' => $session, 'wait' => $wait], $wait + self::EXTRA_SECONDS, $cancellation);
        /** @var mixed $exit */
        $exit = $body['exit_code'] ?? null;
        return [
            'stdout' => self::string($body, 'stdout'),
            'exited' => ($body['exited'] ?? false) === true,
            'exitCode' => is_int($exit) ? $exit : null,
            'stderr' => self::string($body, 'stderr'),
        ];
    }

    public function close(int $principalId, SandboxLimits $limits, string $session): void {
        try {
            $this->call('close', $principalId, $limits, ['session' => $session], self::EXTRA_SECONDS, new NullCancellation());
        } catch (SandboxFailure) {
            // already gone
        }
    }

    public function closeAll(int $principalId, SandboxLimits $limits): void {
        try {
            $this->call('close_all', $principalId, $limits, [], self::EXTRA_SECONDS, new NullCancellation());
        } catch (SandboxFailure) {
            // nothing to close, or an executor before this operation: the idle ones die by themselves
        }
    }
    /**
     * The working folders of all participants as tar.gz into $file, streamed: for the archive of the agent.
     *
     * spec: brief/operations.md#экспорт-и-импорт-агента — the working folders go into the archive through the executor
     * @throws SandboxFailure
     */
    public function archive(string $file, Cancellation $cancellation): void {
        $request = new Request($this->url . '/archive', 'POST');
        $request->setHeader('authorization', 'Bearer ' . $this->token);
        $request->setTransferTimeout(self::ARCHIVE_TIMEOUT);
        $request->setInactivityTimeout(self::FILE_TIMEOUT);
        $request->setBodySizeLimit(self::ARCHIVE_BYTES);
        $out = \Amp\File\openFile($file, 'w');
        try {
            $response = $this->http->request($request, $cancellation);
            if ($response->getStatus() !== 200) {
                throw new SandboxFailure(self::error(buffer($response->getBody())));
            }
            \Amp\ByteStream\pipe($response->getBody(), $out, $cancellation);
        } catch (HttpException) {
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_nedostupna'));
        } finally {
            $out->close();
        }
    }

    /**
     * The working folders back from a tar.gz of archive(), unpacked over the workspace by the executor.
     *
     * spec: brief/operations.md#экспорт-и-импорт-агента — a full import brings the working folders back
     * @throws SandboxFailure
     */
    public function unarchive(string $file, Cancellation $cancellation): void {
        $request = new Request($this->url . '/unarchive', 'POST', \Amp\Http\Client\StreamedContent::fromFile($file));
        $request->setHeader('authorization', 'Bearer ' . $this->token);
        $request->setHeader('content-type', 'application/gzip');
        $request->setTransferTimeout(self::ARCHIVE_TIMEOUT);
        $request->setInactivityTimeout(self::ARCHIVE_TIMEOUT);
        try {
            $response = $this->http->request($request, $cancellation);
            $text = buffer($response->getBody());
        } catch (HttpException) {
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_nedostupna'));
        }
        if ($response->getStatus() !== 200) {
            throw new SandboxFailure(self::error($text));
        }
    }

    /** The executor's error text of a JSON answer, or a general one. */
    private static function error(string $text): string {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        /** @var mixed $error */
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        return is_string($error) && $error !== '' ? $error : t('sandbox.http_sandbox_client.pesochnitsa_nedostupna');
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws SandboxFailure
     * @throws CancelledException when $cancellation is requested
     */
    private function call(string $operation, int $principalId, SandboxLimits $limits, array $fields, int $timeoutSeconds, Cancellation $cancellation, string $callId = ''): array {
        $payload = ['principal_id' => $principalId, 'call_id' => $callId, 'limits' => [
            'disk_bytes' => $limits->diskBytes,
            'processes' => $limits->processes,
            'cpu_seconds' => $limits->cpuSeconds,
            'memory_bytes' => $limits->memoryBytes,
            'concurrent_commands' => $limits->concurrentCommands,
        ]] + $fields;
        $request = new Request($this->url . '/v1/' . $operation, 'POST', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $request->setHeader('authorization', 'Bearer ' . $this->token);
        $request->setHeader('content-type', 'application/json');
        $request->setTransferTimeout($timeoutSeconds);
        $request->setInactivityTimeout($timeoutSeconds);
        // a fetched file of 25 MB in base64 with its JSON
        $request->setBodySizeLimit(40 * 1024 * 1024);

        try {
            $response = $this->http->request($request, new CompositeCancellation($cancellation, new TimeoutCancellation($timeoutSeconds + 1)));
            $text = buffer($response->getBody());
        } catch (CancelledException $e) {
            if ($cancellation->isRequested()) {
                throw $e;
            }
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_ne_otvetila_vovremya'));
        } catch (HttpException) {
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_nedostupna'));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_vernula_neponyatnyy_otvet'));
        }
        if (!is_array($decoded)) {
            throw new SandboxFailure(t('sandbox.http_sandbox_client.pesochnitsa_vernula_neponyatnyy_otvet'));
        }
        $body = array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
        if (($body['ok'] ?? false) !== true) {
            $error = self::string($body, 'error');
            throw new SandboxFailure($error !== '' ? $error : t('sandbox.http_sandbox_client.pesochnitsa_otkazala'));
        }
        return $body;
    }

    /** @param array<string, mixed> $body */
    private static function int(array $body, string $key): int {
        return isset($body[$key]) && is_int($body[$key]) ? $body[$key] : 0;
    }

    /** @param array<string, mixed> $body */
    private static function string(array $body, string $key): string {
        return isset($body[$key]) && is_string($body[$key]) ? $body[$key] : '';
    }

    /** @return array{path: string, type: string, size: int}|null */
    private static function entry(mixed $entry): ?array {
        if (!is_array($entry) || !isset($entry['path'], $entry['type'], $entry['size'])) {
            return null;
        }
        if (!is_string($entry['path']) || !is_string($entry['type']) || !is_int($entry['size'])) {
            return null;
        }
        return ['path' => $entry['path'], 'type' => $entry['type'], 'size' => $entry['size']];
    }
}
