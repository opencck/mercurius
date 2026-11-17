<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API;

use Closure;
use OpenCCK\App\Settings\Restarter;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;

use function Amp\async;
use function Amp\Future\awaitFirst;
use function Amp\trapSignal;

/**
 * Application: registers modules, starts them in order, stops them in reverse order on SIGINT/SIGTERM.
 */
final class App {
    /** @var list<AppModuleInterface> */
    private array $modules = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function getLogger(): LoggerInterface {
        return $this->logger;
    }

    /** @param Closure(self): AppModuleInterface $factory */
    public function addHandler(Closure $factory): self {
        $this->modules[] = $factory($this);
        return $this;
    }

    private const SIGINT = 2;
    private const SIGTERM = 15;

    /**
     * Starts all modules and blocks until SIGINT/SIGTERM or a restart asked by the owner (on Windows — until the event
     * loop ends). After a restart request the process ends and Docker starts it again with the new settings.
     */
    public function run(?Restarter $restarter = null): void {
        foreach ($this->modules as $module) {
            $module->start();
        }
        $this->logger->info('Application started', ['modules' => array_map(static fn(AppModuleInterface $m): string => $m::class, $this->modules)]);

        try {
            $signal = async(static fn(): int => trapSignal([self::SIGINT, self::SIGTERM]));
            $first = awaitFirst($restarter === null ? [$signal] : [$signal, $restarter->requested()]);
            $this->logger->info($first === null ? 'Restart requested' : 'Stop signal received', ['signal' => $first]);
        } catch (UnsupportedFeatureException) {
            EventLoop::run();
        }
        $this->stop();
    }

    public function stop(): void {
        foreach (array_reverse($this->modules) as $module) {
            $module->stop();
        }
        $this->logger->info('Application stopped');
    }
}
