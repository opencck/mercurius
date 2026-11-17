<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Agent\RoutingDecision;
use DateTimeImmutable;
use OpenCCK\Domain\Repository\ModelCatalogRepository;
use OpenCCK\Domain\Repository\ModelReliabilityRepository;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

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
    private const NAMES = ['intelligence' => 'model.model_selector.intelligence', 'coding' => 'model.model_selector.coding', 'agentic' => 'model.model_selector.agentic'];
    /** Scores up to this a model without indices may serve. */
    private const UNSCORED_OK = 3;
    /** Our own record: a model that failed this share of at least MIN_RUNS worker runs in DAYS is not chosen. */
    private const FAILURE_SHARE = 0.5;
    private const MIN_RUNS = 3;
    private const DAYS = 14;
    /** An excluded model gets a trial run again after this many seconds without runs: it cannot improve otherwise. */
    private const PROBE_AFTER = 3 * 86400;
    /** Seconds the failure rates are kept between choices. */
    private const STATS_TTL = 60;

    /** @var array{at: int, models: array<string, true>}|null */
    private ?array $unreliableCache = null;
    /** An urgent part (urgency at least this) skips models slow here. */
    private const URGENT = 7;
    /** Slow: p95 of a call above this, over at least SLOW_MIN_CALLS calls. */
    private const SLOW_P95_MS = 60_000;
    private const SLOW_MIN_CALLS = 5;

    public function __construct(
        private readonly ModelCatalogRepository $catalog,
        private readonly ModelRoles $roles,
        private readonly RoutingDecisionRepository $decisions,
        private readonly LoggerInterface $logger,
        private readonly ?ModelReliabilityRepository $reliability = null,
    ) {}

    /**
     * @param int $raise points added to every score: the cascade asks for a stronger model
     * @return array{ModelRef, string} the model and why
     */
    public function choose(TaskProfile $profile, bool $needsTools, ?int $conversationId, int $raise = 0): array {
        $window = $profile->score('context') > 1 ? 2 ** ($profile->score('context') + 12) : 0;
        $ceiling = $this->ceiling();
        $unreliable = $this->unreliable();
        // spec: BRIEF.md#выбор — urgency: the catalog has no latency, our own calls do
        $slow = $profile->score('urgency') >= self::URGENT ? $this->slow() : [];
        // Thresholds come from the models that could be chosen at all, so a top score is reachable. Models that often
        // failed here stay in that set: dropping them would lower the bar for the rest.
        // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — every provider with a key; refs keep the provider
        $eligible = [];
        $providerOf = [];
        foreach ($this->roles->providers() as $provider) {
            foreach ($this->catalog->models($provider) as $m) {
                if ($this->eligible($m, $needsTools, $profile, $window, $ceiling)) {
                    $providerOf[count($eligible)] = $provider;
                    $eligible[] = $m;
                }
            }
        }
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
                $missing[$index] = t(self::NAMES[$index]);
            }
        }
        if ($missing !== []) {
            // Without the index nothing shows a model is good enough: not the cheapest one at random.
            return $this->fallback($conversationId, sprintf(t('model.model_selector.v_kataloge_net_otsenok_rol'), implode(', ', $missing)));
        }
        $candidates = [];
        $skipped = 0;
        $slowSkipped = 0;
        foreach ($eligible as $i => $m) {
            if (!self::meets($m, $required, $highest)) {
                continue;
            }
            $ref = new ModelRef($providerOf[$i], $m->id);
            if (isset($unreliable[(string) $ref])) {
                $skipped++;
                continue;
            }
            if (isset($slow[(string) $ref])) {
                $slowSkipped++;
                continue;
            }
            $candidates[] = ['ref' => $ref, 'info' => $m];
        }
        $needs = self::describe($required, $needsTools, $profile, $window)
            . ($skipped > 0 ? sprintf(t('model.model_selector.bez_chasto_otkazyvavshih_u_nas'), $skipped) : '')
            . ($slowSkipped > 0 ? sprintf(t('model.model_selector.bez_medlennyh_u_nas_p95'), $slowSkipped, intdiv(self::SLOW_P95_MS, 1000)) : '');
        usort(
            $candidates,
            /**
             * @param array{ref: ModelRef, info: ModelInfo} $a
             * @param array{ref: ModelRef, info: ModelInfo} $b
             */
            // The same model directly and through OpenRouter costs the same in the catalog; OpenRouter adds its fee.
            static fn(array $a, array $b): int => [self::price($a['info']), $a['ref']->provider === ModelRef::OPENROUTER]
                <=> [self::price($b['info']), $b['ref']->provider === ModelRef::OPENROUTER],
        );
        $chosen = $candidates[0] ?? null;
        if ($chosen === null) {
            return $this->fallback($conversationId, sprintf(t('model.model_selector.po_profilyu_podhodyaschih_modeley_net'), $needs));
        }
        $model = $chosen['ref'];
        $reason = sprintf(t('model.model_selector.samaya_deshevaya_iz_podhodyaschih'), count($candidates), $needs);
        $this->record($conversationId, 'profile', 'profile', (string) $model, $reason);
        return [$model, $reason];
    }

    /**
     * Models that failed workers here too often: the catalog's benchmarks do not show a provider that keeps
     * dropping requests.
     *
     * spec: BRIEF.md#часть-4--проверяющий-и-данные-трасс — failure rates in the choice
     *
     * @return array<string, true> model refs
     */
    private function unreliable(): array {
        if ($this->reliability === null) {
            return [];
        }
        $now = time();
        if ($this->unreliableCache !== null && $now - $this->unreliableCache['at'] < self::STATS_TTL) {
            return $this->unreliableCache['models'];
        }
        try {
            $stats = $this->reliability->failures(new DateTimeImmutable(sprintf('-%d days', self::DAYS)));
        } catch (Throwable $e) {
            $this->logger->error('Could not read model failure rates', ['error' => $e::class]);
            return [];
        }
        $unreliable = [];
        foreach ($stats as $model => $row) {
            if (
                $row['runs'] >= self::MIN_RUNS
                && (float) $row['failures'] >= self::FAILURE_SHARE * (float) $row['runs']
                && $now - $row['last'] < self::PROBE_AFTER
            ) {
                $unreliable[$model] = true;
            }
        }
        $this->unreliableCache = ['at' => $now, 'models' => $unreliable];
        return $unreliable;
    }

    /** @return array<string, true> model refs slow here, for urgent parts */
    private function slow(): array {
        if ($this->reliability === null) {
            return [];
        }
        try {
            $stats = $this->reliability->latency(new DateTimeImmutable(sprintf('-%d days', self::DAYS)));
        } catch (Throwable $e) {
            $this->logger->error('Could not read model latency', ['error' => $e::class]);
            return [];
        }
        $slow = [];
        foreach ($stats as $model => $row) {
            if ($row['calls'] >= self::SLOW_MIN_CALLS && $row['p95'] > self::SLOW_P95_MS) {
                $slow[$model] = true;
            }
        }
        return $slow;
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

    /**
     * Hard needs: a plain entry (a :batch variant is served only by the Batch API, :free has its own limits), a price above
     * zero, not above the strong role's; tools, images, audio, window.
     */
    private function eligible(ModelInfo $model, bool $needsTools, TaskProfile $profile, int $window, ?float $ceiling): bool {
        if ($model->inputPricePerToken === null || $model->outputPricePerToken === null || self::price($model) <= 0.0 || str_contains($model->id, ':')) {
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
            $parts[] = sprintf('%s ≥ %.1f', isset(self::NAMES[$index]) ? t(self::NAMES[$index]) : $index, $threshold);
        }
        if ($needsTools) {
            $parts[] = t('model.model_selector.instrumenty');
        }
        if ($profile->score('images') >= 5) {
            $parts[] = t('model.model_selector.izobrazheniya');
        }
        if ($profile->score('audio') >= 5) {
            $parts[] = t('model.model_selector.zvuk');
        }
        if ($window > 0) {
            $parts[] = sprintf(t('model.model_selector.okno'), $window);
        }
        return $parts === [] ? t('model.model_selector.bez_trebovaniy') : implode(', ', $parts);
    }

    private function record(?int $conversationId, string $method, string $role, string $model, string $reason): void {
        try {
            $this->decisions->record(new RoutingDecision($conversationId, 'profile', $method, $role, $model, $reason));
        } catch (Throwable $e) {
            $this->logger->error('Could not record a routing decision', ['error' => $e::class]);
        }
    }
}
