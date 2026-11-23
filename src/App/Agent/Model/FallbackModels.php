<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use InvalidArgumentException;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Where a run goes when its model fails: the same model at the other provider, else the main role, else the strong
 * one. One switch per run; the prompt cache is lost once, which is cheaper than a failed run.
 *
 * A model whose provider is off the choice — a used-up window of a subscription, a lost sign-in — is replaced only by
 * another model of the same category list, in the owner's order, at a provider that serves now. No such model — no
 * switch: the owner chose the subscription for this category, and another category would be a model he did not put
 * there. A role model at a provider off the choice is skipped too.
 *
 * spec: brief/models.md#лимиты-подписки-и-переход-на-модель-категории
 * spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — a failed model is switched once
 * ref: references/hermes-agent/agent/turn_recovery.py@e96221fa:L1922-L1959 — switch models on provider failures
 * ref: references/hermes-agent/agent/chat_completion_helpers.py@e96221fa:L2034-L2090 (try_activate_fallback)
 */
final class FallbackModels {
    /** OpenRouter's prefix for models of OpenAI. */
    private const OPENAI_PREFIX = 'openai/';

    public function __construct(
        private readonly ModelRoles $roles,
        private readonly ModelCatalogRepository $catalog,
        private readonly RoutingDecisionRepository $decisions,
        private readonly LoggerInterface $logger,
        private readonly ?ProviderPauses $pauses = null,
    ) {}

    /** The model to try next, or null when there is nothing else to try. */
    public function next(string $model, ?int $conversationId, ?int $runId = null): ?string {
        try {
            $ref = ModelRef::parse($model);
        } catch (InvalidArgumentException) {
            return null;
        }
        $providers = $this->roles->providers();
        $next = null;
        $why = '';
        if ($this->pauses?->paused($ref->provider) === true) {
            [$next, $why] = $this->sameCategory($ref);
            if ($next === null) {
                $this->logger->notice('A model off the choice has no other model in its category', ['model' => (string) $ref]);
                return null;
            }
        } elseif ($ref->provider === ModelRef::OPENROUTER && str_starts_with($ref->model, self::OPENAI_PREFIX) && in_array(ModelRef::OPENAI, $providers, true)) {
            $twin = new ModelRef(ModelRef::OPENAI, substr($ref->model, strlen(self::OPENAI_PREFIX)));
            if ($this->roles->allowedText($twin) && $this->catalog->find($twin) !== null) {
                [$next, $why] = [$twin, t('model.fallback_models.ta_zhe_model_napryamuyu_u')];
            }
        } elseif ($ref->provider === ModelRef::OPENAI && in_array(ModelRef::OPENROUTER, $providers, true)) {
            $twin = new ModelRef(ModelRef::OPENROUTER, self::OPENAI_PREFIX . $ref->model);
            if ($this->roles->allowedText($twin) && $this->catalog->find($twin) !== null) {
                [$next, $why] = [$twin, t('model.fallback_models.ta_zhe_model_cherez_openrouter')];
            }
        }
        if ($next === null) {
            $main = $this->roles->model(ModelRole::MAIN);
            $strong = $this->roles->model(ModelRole::STRONG);
            $serves = fn(ModelRef $model): bool => (string) $model !== (string) $ref && $this->pauses?->paused($model->provider) !== true;
            [$next, $why] = $serves($main) ? [$main, t('model.fallback_models.rol_main')] : ($serves($strong) ? [$strong, t('model.fallback_models.rol_strong')] : [null, '']);
        }
        if ($next === null) {
            return null;
        }
        $reason = sprintf(t('model.fallback_models.model_ne_otvetila'), $ref, $why);
        $this->logger->warning('Model failed; switching once', ['from' => (string) $ref, 'to' => (string) $next]);
        try {
            $this->decisions->record(new RoutingDecision($conversationId, 'fallback', 'failure', 'fallback', (string) $next, $reason, $runId));
        } catch (Throwable $e) {
            $this->logger->error('Could not record a routing decision', ['error' => $e::class]);
        }
        return (string) $next;
    }

    /** @return array{?ModelRef, string} the next model of the list that holds the failed one, and why */
    private function sameCategory(ModelRef $ref): array {
        foreach ([ModelRole::CHEAP, ModelRole::MAIN, ModelRole::STRONG] as $role) {
            $list = $this->roles->listOf($role);
            if (!in_array((string) $ref, array_map('strval', $list), true)) {
                continue;
            }
            foreach ($list as $model) {
                if ((string) $model !== (string) $ref && $this->pauses?->paused($model->provider) !== true && $this->catalog->find($model) !== null) {
                    return [$model, sprintf(t('model.fallback_models.same_category'), $role)];
                }
            }
        }
        return [null, ''];
    }
}
