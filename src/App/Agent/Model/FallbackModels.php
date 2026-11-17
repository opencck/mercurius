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
 * spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — a failed model is switched once
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
    ) {}

    /** The model to try next, or null when there is nothing else to try. */
    public function next(string $model, ?int $conversationId): ?string {
        try {
            $ref = ModelRef::parse($model);
        } catch (InvalidArgumentException) {
            return null;
        }
        $providers = $this->roles->providers();
        $next = null;
        $why = '';
        if ($ref->provider === ModelRef::OPENROUTER && str_starts_with($ref->model, self::OPENAI_PREFIX) && in_array(ModelRef::OPENAI, $providers, true)) {
            $twin = new ModelRef(ModelRef::OPENAI, substr($ref->model, strlen(self::OPENAI_PREFIX)));
            if ($this->catalog->find($twin) !== null) {
                [$next, $why] = [$twin, t('model.fallback_models.ta_zhe_model_napryamuyu_u')];
            }
        } elseif ($ref->provider === ModelRef::OPENAI && in_array(ModelRef::OPENROUTER, $providers, true)) {
            $twin = new ModelRef(ModelRef::OPENROUTER, self::OPENAI_PREFIX . $ref->model);
            if ($this->catalog->find($twin) !== null) {
                [$next, $why] = [$twin, t('model.fallback_models.ta_zhe_model_cherez_openrouter')];
            }
        }
        if ($next === null) {
            $main = $this->roles->model(ModelRole::MAIN);
            $strong = $this->roles->model(ModelRole::STRONG);
            [$next, $why] = (string) $main !== (string) $ref ? [$main, t('model.fallback_models.rol_main')] : ((string) $strong !== (string) $ref ? [$strong, t('model.fallback_models.rol_strong')] : [null, '']);
        }
        if ($next === null) {
            return null;
        }
        $reason = sprintf(t('model.fallback_models.model_ne_otvetila'), $ref, $why);
        $this->logger->warning('Model failed; switching once', ['from' => (string) $ref, 'to' => (string) $next]);
        try {
            $this->decisions->record(new RoutingDecision($conversationId, 'fallback', 'failure', 'fallback', (string) $next, $reason));
        } catch (Throwable $e) {
            $this->logger->error('Could not record a routing decision', ['error' => $e::class]);
        }
        return (string) $next;
    }
}
