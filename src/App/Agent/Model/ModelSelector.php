<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The cheapest model that meets a task profile. Each score becomes a requirement on a catalog index — score s asks for
 * at least the (s × 10)-th percentile of that index among catalog models; a high cost of error raises every score.
 * Hard needs (tools, images, audio, window) filter first. Nothing fits — the strong role.
 *
 * spec: BRIEF.md#часть-2--профиль-задачи-выбор-модели-каскад
 * spec: BRIEF.md#выбор — hard needs, then the cheapest capable model with a margin growing with the cost of error
 */
final class ModelSelector {
    /** Axis => catalog index that measures it. */
    private const INDEX = [
        'text' => 'intelligence',
        'reasoning' => 'intelligence',
        'math' => 'intelligence',
        'academic' => 'intelligence',
        'code' => 'coding',
        'agency' => 'agentic',
    ];
    private const NAMES = ['intelligence' => 'интеллект', 'coding' => 'код', 'agentic' => 'агентность'];
    /** Scores up to this a model without indices may serve. */
    private const UNSCORED_OK = 3;

    public function __construct(
        private readonly ModelCatalogRepository $catalog,
        private readonly ModelRoles $roles,
        private readonly RoutingDecisionRepository $decisions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param int $raise points added to every score: the cascade asks for a stronger model
     * @return array{ModelRef, string} the model and why
     */
    public function choose(TaskProfile $profile, bool $needsTools, ?int $conversationId, int $raise = 0): array {
        $models = $this->catalog->models(ModelRef::OPENROUTER);
        $bump = (float) max(0, $profile->score('error_cost') - 5) / 2.0 + (float) $raise;
        $required = [];
        $highest = 0.0;
        foreach (self::INDEX as $axis => $index) {
            if ($profile->score($axis) <= 1 && $raise === 0) {
                continue;
            }
            $score = min(10.0, (float) $profile->score($axis) + $bump);
            $highest = max($highest, $score);
            $threshold = self::percentile(self::values($models, $index), $score / 10.0);
            if ($threshold !== null) {
                $required[$index] = max($required[$index] ?? 0.0, $threshold);
            }
        }
        $window = $profile->score('context') > 1 ? 2 ** ($profile->score('context') + 12) : 0;
        $candidates = [];
        foreach ($models as $model) {
            if (!$this->fits($model, $needsTools, $profile, $window, $required, $highest)) {
                continue;
            }
            $candidates[] = $model;
        }
        usort($candidates, static fn(ModelInfo $a, ModelInfo $b): int => self::price($a) <=> self::price($b));
        $needs = self::describe($required, $needsTools, $profile, $window);
        $chosen = $candidates[0] ?? null;
        if ($chosen === null) {
            $model = $this->roles->model(ModelRole::STRONG);
            $reason = sprintf('по профилю (%s) подходящих моделей нет — роль strong', $needs);
            $this->record($conversationId, 'fallback', (string) $model, $reason);
            return [$model, $reason];
        }
        $model = new ModelRef(ModelRef::OPENROUTER, $chosen->id);
        $reason = sprintf('самая дешёвая из %d подходящих: %s', count($candidates), $needs);
        $this->record($conversationId, 'profile', (string) $model, $reason);
        return [$model, $reason];
    }

    /** @param array<string, float> $required */
    private function fits(ModelInfo $model, bool $needsTools, TaskProfile $profile, int $window, array $required, float $highest): bool {
        if ($model->inputPricePerToken === null || $model->outputPricePerToken === null || str_ends_with($model->id, ':free')) {
            return false;
        }
        if ($needsTools && !$model->supportsTools()) {
            return false;
        }
        if ($profile->score('images') >= 5 && !in_array('image', $model->inputModalities, true)) {
            return false;
        }
        if ($profile->score('audio') >= 5 && !in_array('audio', $model->inputModalities, true)) {
            return false;
        }
        if ($window > 0 && ($model->contextLength ?? 0) < $window) {
            return false;
        }
        foreach ($required as $index => $threshold) {
            $value = self::index($model, $index);
            if ($value === null) {
                // Without indices a model is trusted only with simple work.
                if ($highest > self::UNSCORED_OK) {
                    return false;
                }
                continue;
            }
            if ($value < $threshold) {
                return false;
            }
        }
        return true;
    }

    private static function index(ModelInfo $model, string $index): ?float {
        return match ($index) {
            'intelligence' => $model->intelligenceIndex,
            'coding' => $model->codingIndex,
            default => $model->agenticIndex,
        };
    }

    /**
     * @param list<ModelInfo> $models
     * @return list<float> the index of every model that has it, ascending
     */
    private static function values(array $models, string $index): array {
        $values = [];
        foreach ($models as $model) {
            $value = self::index($model, $index);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        sort($values);
        return $values;
    }

    /** @param list<float> $sorted */
    private static function percentile(array $sorted, float $q): ?float {
        if ($sorted === []) {
            return null;
        }
        return $sorted[(int) floor((float) (count($sorted) - 1) * max(0.0, min(1.0, $q)))];
    }

    /** Blended price per token: three input tokens per output token, as in an agent's turn. */
    private static function price(ModelInfo $model): float {
        return (3.0 * ($model->inputPricePerToken ?? 0.0) + ($model->outputPricePerToken ?? 0.0)) / 4.0;
    }

    /** @param array<string, float> $required */
    private static function describe(array $required, bool $needsTools, TaskProfile $profile, int $window): string {
        $parts = [];
        foreach ($required as $index => $threshold) {
            $parts[] = sprintf('%s ≥ %.1f', self::NAMES[$index] ?? $index, $threshold);
        }
        if ($needsTools) {
            $parts[] = 'инструменты';
        }
        if ($profile->score('images') >= 5) {
            $parts[] = 'изображения';
        }
        if ($profile->score('audio') >= 5) {
            $parts[] = 'звук';
        }
        if ($window > 0) {
            $parts[] = sprintf('окно ≥ %d', $window);
        }
        return $parts === [] ? 'без требований' : implode(', ', $parts);
    }

    private function record(?int $conversationId, string $method, string $model, string $reason): void {
        try {
            $this->decisions->record(new RoutingDecision($conversationId, 'profile', $method, 'profile', $model, $reason));
        } catch (Throwable $e) {
            $this->logger->error('Could not record a routing decision', ['error' => $e::class]);
        }
    }
}
