<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API;

use Closure;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;

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

    /** Starts all modules and blocks until SIGINT/SIGTERM (on Windows — until the event loop ends). */
    public function run(): void {
        foreach ($this->modules as $module) {
            $module->start();
        }
        $this->logger->info('Application started', ['modules' => array_map(static fn(AppModuleInterface $m): string => $m::class, $this->modules)]);

        try {
            $signal = trapSignal([self::SIGINT, self::SIGTERM]);
            $this->logger->info('Stop signal received', ['signal' => $signal]);
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
