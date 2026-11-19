<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Module;

use Amp\Future;
use Closure;
use DateTimeImmutable;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Infrastructure\API\AppModuleInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

use function Amp\async;

/**
 * Keeps the model catalog of every provider in the database: at start when it is older than a day, then once a day.
 * Loading runs in the background, so the agent starts without waiting for the network; a failed load keeps the old
 * catalog.
 *
 * spec: BRIEF.md#модели — the catalog is loaded daily and at start when older than a day, per provider
 */
final class ModelCatalogModule implements AppModuleInterface {
    /** Seconds before a failed first load is tried again. */
    private const RETRY = 600.0;
    private const DAY = 86_400;

    private ?string $timer = null;
    /** @var list<Future<void>> */
    private array $loads = [];

    /**
     * @param array<string, Closure(): list<ModelInfo>> $sources provider => loader
     * @param array<string, Closure(): list<string>> $toolReliable provider => the ids of the models that call tools well
     */
    public function __construct(
        private readonly array $sources,
        private readonly ModelCatalogRepository $catalog,
        private readonly LoggerInterface $logger,
        private readonly float $interval = self::DAY,
        private readonly array $toolReliable = [],
    ) {}

    public function start(): void {
        foreach ($this->sources as $provider => $fetch) {
            $fetchedAt = $this->catalog->fetchedAt($provider);
            if ($fetchedAt === null || $fetchedAt < new DateTimeImmutable(sprintf('-%d seconds', self::DAY))) {
                $this->loads[] = async($this->load(...), $provider, $fetch);
            }
        }
        $this->timer = EventLoop::repeat($this->interval, function (): void {
            foreach ($this->sources as $provider => $fetch) {
                $this->loads[] = async($this->load(...), $provider, $fetch);
            }
        });
    }

    public function stop(): void {
        if ($this->timer !== null) {
            EventLoop::cancel($this->timer);
            $this->timer = null;
        }
    }

    /** Waits for loads in progress; for tests and orderly shutdown. */
    public function idle(): void {
        while ($this->loads !== []) {
            $loads = $this->loads;
            $this->loads = [];
            Future\awaitAll($loads);
        }
    }

    /** @param Closure(): list<ModelInfo> $fetch */
    private function load(string $provider, Closure $fetch): void {
        try {
            $models = $fetch();
            if ($models === []) {
                $this->logger->warning('Model catalog came empty; the old one stays', ['provider' => $provider]);
                $this->retry($provider, $fetch);
                return;
            }
            $this->catalog->replace($provider, $models);
            $this->logger->info('Model catalog loaded', ['provider' => $provider, 'models' => count($models)]);
            $this->markToolReliable($provider);
        } catch (Throwable $e) {
            $this->logger->warning('Model catalog was not loaded; the old one stays', ['provider' => $provider, 'error' => $e::class]);
            $this->retry($provider, $fetch);
        }
    }

    /**
     * Which models call tools well; a failed or empty answer leaves it unknown, so no model is held back by it.
     *
     * spec: BRIEF.md#выбор-моделей-под-задачу
     */
    private function markToolReliable(string $provider): void {
        $fetch = $this->toolReliable[$provider] ?? null;
        if ($fetch === null) {
            return;
        }
        try {
            $ids = $fetch();
        } catch (Throwable $e) {
            $this->logger->warning('Tool reliability of models was not loaded', ['provider' => $provider, 'error' => $e::class]);
            return;
        }
        if ($ids !== []) {
            $this->catalog->markToolReliable($provider, $ids);
        }
    }

    /**
     * A provider with no catalog yet cannot be chosen or priced: it is tried again in minutes, not on the next day.
     *
     * @param Closure(): list<ModelInfo> $fetch
     */
    private function retry(string $provider, Closure $fetch): void {
        if ($this->timer === null || $this->catalog->fetchedAt($provider) !== null) {
            return;
        }
        EventLoop::delay(self::RETRY, function () use ($provider, $fetch): void {
            if ($this->timer !== null) {
                $this->loads[] = async($this->load(...), $provider, $fetch);
            }
        });
    }
}
