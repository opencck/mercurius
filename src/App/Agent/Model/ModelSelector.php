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
 * Hard needs (tools, images, audio, window, a price not above the strong role's) filter first; thresholds are taken
 * among the models left. Nothing fits, or the catalog has no index for a demanding score — the strong role.
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
        $window = $profile->score('context') > 1 ? 2 ** ($profile->score('context') + 12) : 0;
        $ceiling = $this->ceiling();
        // Thresholds come from the models that could be chosen at all, so a top score is reachable.
        $eligible = array_values(array_filter(
            $this->catalog->models(ModelRef::OPENROUTER),
            fn(ModelInfo $m): bool => $this->eligible($m, $needsTools, $profile, $window, $ceiling),
        ));
        $bump = (float) max(0, $profile->score('error_cost') - 5) / 2.0 + (float) $raise;
        $required = [];
        $missing = [];
        $highest = 0.0;
        foreach (self::INDEX as $axis => $index) {
            // Every axis counts, a missing one as 1: the cost of error raises them all.
            $score = min(10.0, (float) $profile->score($axis) + $bump);
            $highest = max($highest, $score);
            $threshold = self::percentile(self::values($eligible, $index), $score / 10.0);
            if ($threshold !== null) {
                $required[$index] = max($required[$index] ?? 0.0, $threshold);
            } elseif ($score > self::UNSCORED_OK) {
                $missing[$index] = self::NAMES[$index];
            }
        }
        if ($missing !== []) {
            // Without the index nothing shows a model is good enough: not the cheapest one at random.
            return $this->fallback($conversationId, sprintf('в каталоге нет оценок (%s) — роль strong', implode(', ', $missing)));
        }
        $needs = self::describe($required, $needsTools, $profile, $window);
        $candidates = array_values(array_filter($eligible, static fn(ModelInfo $m): bool => self::meets($m, $required, $highest)));
        usort($candidates, static fn(ModelInfo $a, ModelInfo $b): int => self::price($a) <=> self::price($b));
        $chosen = $candidates[0] ?? null;
        if ($chosen === null) {
            return $this->fallback($conversationId, sprintf('по профилю (%s) подходящих моделей нет — роль strong', $needs));
        }
        $model = new ModelRef(ModelRef::OPENROUTER, $chosen->id);
        $reason = sprintf('самая дешёвая из %d подходящих: %s', count($candidates), $needs);
        $this->record($conversationId, 'profile', 'profile', (string) $model, $reason);
        return [$model, $reason];
    }

    /** @return array{ModelRef, string} */
    private function fallback(?int $conversationId, string $reason): array {
        $model = $this->roles->model(ModelRole::STRONG);
        $this->record($conversationId, 'fallback', ModelRole::STRONG, (string) $model, $reason);
        return [$model, $reason];
    }

    /**
     * The blended price of the strong role: a profile never buys a pricier model than the owner's strongest one (the
     * same guard as for moving roles). Null when the strong model is not in the catalog or has no price.
     */
    private function ceiling(): ?float {
        $strong = $this->catalog->find($this->roles->model(ModelRole::STRONG));
        return $strong === null || $strong->inputPricePerToken === null || $strong->outputPricePerToken === null ? null : self::price($strong);
    }

    /** Hard needs: a price above zero (no free or promo entries), not above the strong role's; tools, images, audio, window. */
    private function eligible(ModelInfo $model, bool $needsTools, TaskProfile $profile, int $window, ?float $ceiling): bool {
        if ($model->inputPricePerToken === null || $model->outputPricePerToken === null || self::price($model) <= 0.0 || str_ends_with($model->id, ':free')) {
            return false;
        }
        if ($ceiling !== null && self::price($model) > $ceiling) {
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
        return $window === 0 || ($model->contextLength ?? 0) >= $window;
    }

    /** @param array<string, float> $required */
    private static function meets(ModelInfo $model, array $required, float $highest): bool {
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

    private function record(?int $conversationId, string $method, string $role, string $model, string $reason): void {
        try {
            $this->decisions->record(new RoutingDecision($conversationId, 'profile', $method, $role, $model, $reason));
        } catch (Throwable $e) {
            $this->logger->error('Could not record a routing decision', ['error' => $e::class]);
        }
    }
}
