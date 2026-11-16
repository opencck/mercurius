<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Chooses the model of a conversation when it opens: the class of the first request picks a role, the role table
 * picks the model. Every choice is logged with its reason — data for the full model selection later.
 *
 * spec: BRIEF.md#модели — class picks the role; the model is fixed for the conversation; choices are logged
 * spec: BRIEF.md#начальная-таблица-ролей-моделей — short and typical requests go to the default model
 */
final class ModelRouter {
    /** @param positive-int $defaultWindow LLM_CONTEXT_WINDOW, for models the catalog does not know */
    public function __construct(
        private readonly RequestClassifier $classifier,
        private readonly ModelRoles $roles,
        private readonly ModelCatalogRepository $catalog,
        private readonly RoutingDecisionRepository $decisions,
        private readonly int $defaultWindow,
        private readonly LoggerInterface $logger,
    ) {}

    public function choose(?int $conversationId, string $text, ?Cancellation $cancellation = null): ModelRef {
        [$class, $method, $reason] = $this->classifier->classify($text, $cancellation);
        $role = $class === RequestClass::Complex ? ModelRole::STRONG : ModelRole::MAIN;
        $model = $this->roles->model($role);
        $info = $this->catalog->find($model);
        if ($role !== ModelRole::MAIN && $info !== null && !$info->supportsTools()) {
            $reason .= sprintf('; %s не вызывает инструменты — взята основная модель', $model);
            $role = ModelRole::MAIN;
            $model = $this->roles->model(ModelRole::MAIN);
        }
        try {
            $this->decisions->record(new RoutingDecision($conversationId, $class->value, $method, $role, (string) $model, $reason));
        } catch (Throwable $e) {
            $this->logger->error('Could not record a routing decision', ['error' => $e::class]);
        }
        $this->logger->info('Model chosen', ['conversation' => $conversationId, 'class' => $class->value, 'method' => $method, 'model' => (string) $model]);
        return $model;
    }

    /**
     * Tokens of the model's window for compaction: from the catalog, but never above LLM_CONTEXT_WINDOW — a 1M window
     * would let a conversation send hundreds of thousands of tokens on every step before compacting.
     *
     * @return positive-int
     */
    public function window(string $model): int {
        $length = $this->catalog->find(ModelRef::parse($model))?->contextLength;
        return $length !== null && $length > 0 ? min($length, $this->defaultWindow) : $this->defaultWindow;
    }

    public function cheapModel(): string {
        return (string) $this->roles->model(ModelRole::CHEAP);
    }
}
