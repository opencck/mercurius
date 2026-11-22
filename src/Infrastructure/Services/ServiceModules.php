<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Services;

use Amp\Cancellation;
use Closure;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Throwable;

use function Amp\async;
use function Amp\Future\await;

/**
 * The module services the app knows of (an address and a token from .env) and whether each answers /health now. A tool
 * of a module is offered only while its service is up; the check runs at start and once a minute, so a module started
 * or stopped later comes and goes without a restart. A service without /health (the local Bot API) brings its own check.
 * A module that was up, fell and came back is told with its downtime: in that time its work did not get through.
 *
 * spec: brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы — a tool only while the service runs
 * ref: references/hermes-agent/tools/registry.py@e96221fa:L260-L270 — hermes asks check_fn of each tool and keeps the
 *      verdict ~30 s; here one check per service for all its tools, in the background, so a run never waits on it
 */
final class ServiceModules {
    /** @var array<string, array{url: string, token: string}> */
    private array $modules = [];
    /** @var array<string, bool> */
    private array $up = [];
    /** @var array<string, Closure(?Cancellation): bool> */
    private array $probes = [];
    /** @var array<string, float> since when a module that was up does not answer */
    private array $downSince = [];

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** @var (Closure(string, float): void)|null a module that fell is back, after so many seconds */
        private readonly ?Closure $back = null,
        /** @var (Closure(): float)|null */
        private readonly ?Closure $now = null,
    ) {}

    /** Adds a module; without a token it stays off and is not added. */
    public function add(string $name, string $url, #[SensitiveParameter] ?string $token): bool {
        if ($token === null || $token === '') {
            return false;
        }
        $this->modules[$name] = ['url' => rtrim($url, '/'), 'token' => $token];
        $this->up[$name] = false;
        return true;
    }

    /**
     * Adds a module with its own check in place of /health.
     *
     * @param Closure(?Cancellation): bool $check
     */
    public function probe(string $name, Closure $check): void {
        $this->probes[$name] = $check;
        $this->up[$name] = false;
    }

    public function up(string $name): bool {
        return $this->up[$name] ?? false;
    }

    public function url(string $name): ?string {
        return $this->modules[$name]['url'] ?? null;
    }

    public function token(string $name): ?string {
        return $this->modules[$name]['token'] ?? null;
    }

    /** @return array<string, bool> every module set up and whether it is up */
    public function states(): array {
        return $this->up;
    }

    /** Asks every module at once: /health, 3 s each, or its own check. */
    public function check(?Cancellation $cancellation = null): void {
        $checks = [];
        foreach ($this->modules as $name => $module) {
            $checks[$name] = async(fn(): bool => $this->healthy($module['url'], $cancellation));
        }
        foreach ($this->probes as $name => $probe) {
            $checks[$name] = async(static function () use ($probe, $cancellation): bool {
                try {
                    return $probe($cancellation);
                } catch (Throwable) {
                    return false;
                }
            });
        }
        foreach ($checks as $name => $future) {
            $this->note($name, $future->await() === true);
        }
    }

    private function note(string $name, bool $healthy): void {
        $was = $this->up[$name] ?? false;
        $this->up[$name] = $healthy;
        if ($was === $healthy) {
            return;
        }
        $now = $this->now === null ? microtime(true) : ($this->now)();
        if (!$healthy) {
            $this->downSince[$name] = $now;
            $this->logger->warning('Module down', ['module' => $name]);
            return;
        }
        $this->logger->info('Module up', ['module' => $name]);
        if (isset($this->downSince[$name])) {
            $seconds = $now - $this->downSince[$name];
            unset($this->downSince[$name]);
            if ($this->back !== null) {
                ($this->back)($name, $seconds);
            }
        }
    }

    private function healthy(string $url, ?Cancellation $cancellation): bool {
        $request = new Request($url . '/health');
        $request->setTransferTimeout(3);
        $request->setInactivityTimeout(3);
        $request->setBodySizeLimit(10_000);
        try {
            $response = $this->http->request($request, $cancellation ?? new TimeoutCancellation(5));
            $response->getBody()->buffer();
            return $response->getStatus() === 200;
        } catch (Throwable) {
            return false;
        }
    }
}
