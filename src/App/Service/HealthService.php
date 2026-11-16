<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\async;

/**
 * Runs all health checks concurrently, each bounded by a timeout.
 * Failure details go to the log, never to the response.
 */
final class HealthService {
    /** @param list<HealthCheck> $checks */
    public function __construct(
        private readonly array $checks,
        private readonly float $timeout = 2.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function run(): HealthReport {
        $futures = [];
        foreach ($this->checks as $check) {
            $futures[$check->name()] = async($check->check(...));
        }

        $results = [];
        foreach ($futures as $name => $future) {
            try {
                $future->await(new TimeoutCancellation($this->timeout));
                $results[$name] = 'ok';
            } catch (CancelledException) {
                $results[$name] = 'timeout';
                $this->logger->warning('Health check timed out', ['check' => $name, 'timeout' => $this->timeout]);
            } catch (Throwable $e) {
                $results[$name] = 'fail';
                $this->logger->warning('Health check failed', ['check' => $name, 'error' => $e::class, 'message' => $e->getMessage()]);
            }
        }

        $healthy = !in_array('fail', $results, true) && !in_array('timeout', $results, true);
        return new HealthReport($healthy, $results);
    }
}
