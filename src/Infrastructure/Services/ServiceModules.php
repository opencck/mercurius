<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Services;

use Amp\Cancellation;
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
 * or stopped later comes and goes without a restart.
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

    public function __construct(
        private readonly HttpClient $http,
        private readonly LoggerInterface $logger = new NullLogger(),
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

    /** Asks every module's /health at once, 3 s each. */
    public function check(?Cancellation $cancellation = null): void {
        $checks = [];
        foreach ($this->modules as $name => $module) {
            $checks[$name] = async(fn(): bool => $this->healthy($module['url'], $cancellation));
        }
        foreach ($checks as $name => $future) {
            $healthy = $future->await() === true;
            if (($this->up[$name] ?? false) !== $healthy) {
                $this->logger->info('Module ' . ($healthy ? 'up' : 'down'), ['module' => $name]);
            }
            $this->up[$name] = $healthy;
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
