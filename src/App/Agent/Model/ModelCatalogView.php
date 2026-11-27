<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use DomainException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Repository\ModelCatalogRepository;

use function Mercurius\I18n\t;

/**
 * What the agent can read about the providers of models: which of them it has now, and the catalog of each — narrowed
 * by words of the name and by what the model reads. The owner chooses the models of a category from this; the lists
 * that stand now say only what was chosen before.
 *
 * spec: brief/models.md#каталог-провайдеров-для-агента
 */
final class ModelCatalogView {
    public const DEFAULT = 40;
    /** lines of one answer at most: a longer catalog is narrowed by words, not read whole */
    public const MAX = 100;
    /** what a model may read besides text */
    public const INPUTS = ['image', 'audio', 'video', 'file'];
    /** every provider the application knows, in the order they are told */
    public const KNOWN = [
        ModelRef::OPENROUTER, ModelRef::OPENAI, ModelRef::ANTHROPIC, ModelRef::BEDROCK, ModelRef::GEMINI, ModelRef::VERTEX,
        ModelRef::CODEX, ModelRef::CHATGPT, ModelRef::CLAUDE, ModelRef::ANTIGRAVITY,
    ];
    /** the owner's command of a subscription: its sign-in and its quota */
    private const COMMANDS = [ModelRef::CHATGPT => '/codex', ModelRef::CLAUDE => '/claude', ModelRef::ANTIGRAVITY => '/antigravity'];

    /**
     * @param list<string>|(\Closure(): list<string>) $providers the providers of the installation that have a key or a module now
     * @param ?ProviderRegistry $registry the providers that come and go (MCP)
     * @param (\Closure(): array<string, list<string>>)|null $placed the models in each category now: where a model of an
     *        unavailable provider stands is told
     */
    public function __construct(
        private readonly ModelCatalogRepository $catalog,
        private readonly array|\Closure $providers,
        private readonly ?ProviderPauses $pauses = null,
        private readonly ?ProviderRegistry $registry = null,
        private readonly ?\Closure $placed = null,
    ) {}

    /** @return list<string> */
    private function builtin(): array {
        return $this->providers instanceof \Closure ? ($this->providers)() : $this->providers;
    }

    /**
     * The providers in three parts: the ones that can be called now, the known ones that cannot (with why and what to
     * do), and the ones not connected at all. A model of an unavailable provider that stands in a category is named.
     *
     * spec: brief/models.md#каталог-провайдеров-для-агента
     */
    public function providers(): string {
        $available = [];
        $unavailable = [];
        $off = [];
        foreach ($this->builtin() as $provider) {
            $count = count($this->catalog->models($provider));
            $command = self::COMMANDS[$provider] ?? null;
            $line = '- ' . $provider . ': ' . ($command === null ? sprintf(t('model.catalog.by_key'), $count) : sprintf(t('model.catalog.by_subscription'), $count, $command));
            $pause = $this->pauses?->anyOf($provider);
            if ($pause === null) {
                $available[] = $line;
                continue;
            }
            $unavailable[] = $line . '; ' . match ($pause['why']) {
                ProviderPauses::SIGN_IN => sprintf(t('model.catalog.paused_sign_in'), ($command ?? '') . ' login'),
                ProviderPauses::LIMIT => sprintf(t('model.catalog.paused_limit'), gmdate('Y-m-d H:i', $pause['until']) . ' UTC'),
                default => t('model.catalog.paused_account'),
            };
            $off[] = $provider;
        }
        foreach ($this->registry?->all() ?? [] as $name => $provider) {
            $kinds = array_values(array_unique(array_map(static fn(\Mercurius\Mcp\Models\McpModel $m): string => $m->kind, $provider['models'])));
            $line = '- ' . $name . ': ' . sprintf(t('model.catalog.by_mcp'), $provider['about'], count($provider['models']), implode(', ', $kinds) ?: '—');
            if ($provider['available']) {
                $available[] = $line . (in_array('chat', $kinds, true) ? ' ' . t('model.catalog.chat_risk') : '');
            } else {
                $unavailable[] = $line . '; ' . sprintf(t('model.catalog.mcp_off'), $provider['reason'] === '' ? '—' : $provider['reason']);
                $off[] = $name;
            }
        }
        $lines = [t('model.catalog.available_head'), ...($available === [] ? ['—'] : $available)];
        if ($unavailable !== []) {
            $lines[] = t('model.catalog.unavailable_head');
            array_push($lines, ...$unavailable);
            foreach ($this->placed === null ? [] : ($this->placed)() as $category => $models) {
                foreach ($models as $model) {
                    $provider = str_contains($model, ':') ? (string) strstr($model, ':', true) : ModelRef::OPENROUTER;
                    if (in_array($provider, $off, true)) {
                        $lines[] = sprintf(t('model.catalog.placed'), $category, $model);
                    }
                }
            }
        }
        $missing = array_values(array_diff(self::KNOWN, $this->builtin()));
        if ($missing !== []) {
            $lines[] = sprintf(t('model.catalog.missing'), implode(', ', $missing));
        }
        $lines[] = t('model.catalog.hint');
        return implode("\n", $lines);
    }

    /**
     * The models of one provider, by their full names.
     *
     * @param ?string $query words looked for in the id and the name; every word must be there
     * @param ?string $input what the model must read: one of INPUTS
     * @throws DomainException the agent has no such provider
     */
    public function catalog(string $provider, ?string $query, ?string $input, int $limit): string {
        $provider = strtolower(trim($provider));
        if ($this->registry !== null && array_key_exists($provider, $this->registry->all()) && !$this->registry->isAvailable($provider)) {
            throw new DomainException(sprintf(t('model.catalog.mcp_unavailable'), $provider, $this->registry->reason($provider) ?: '—'));
        }
        $known = [...$this->builtin(), ...($this->registry?->available() ?? [])];
        if (!in_array($provider, $known, true)) {
            throw new DomainException(sprintf(t('model.catalog.no_provider'), $provider === '' ? '—' : $provider, implode(', ', $known)));
        }
        $all = $this->catalog->models($provider);
        if ($all === []) {
            return sprintf(t('model.catalog.empty'), $provider);
        }
        $split = preg_split('~[\s,]+~u', mb_strtolower(trim($query ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $words = $split === false ? [] : $split;
        $input = $input === null || trim($input) === '' ? null : strtolower(trim($input));
        $fit = array_values(array_filter($all, static function (ModelInfo $model) use ($words, $input): bool {
            if ($input !== null && !in_array($input, $model->inputModalities, true)) {
                return false;
            }
            $text = mb_strtolower($model->id . ' ' . $model->name);
            foreach ($words as $word) {
                if (!str_contains($text, $word)) {
                    return false;
                }
            }
            return true;
        }));
        if ($fit === []) {
            return sprintf(t('model.catalog.none'), $provider, $words === [] ? '—' : implode(' ', $words), $input ?? '—', count($all));
        }
        $limit = max(1, min(self::MAX, $limit));
        $shown = array_slice($fit, 0, $limit);
        $lines = [sprintf(t('model.catalog.head'), $provider, count($fit), count($shown))];
        foreach ($shown as $model) {
            $lines[] = '- ' . self::line($provider, $model);
        }
        if (count($shown) < count($fit)) {
            $lines[] = sprintf(t('model.catalog.more'), self::MAX);
        }
        return implode("\n", $lines);
    }

    private static function line(string $provider, ModelInfo $model): string {
        $parts = [$provider . ':' . $model->id];
        if ($model->contextLength !== null) {
            $parts[] = 'ctx ' . $model->contextLength;
        }
        $parts[] = 'in: ' . ($model->inputModalities === [] ? 'text' : implode(',', $model->inputModalities));
        if ($model->inputPricePerToken !== null && $model->outputPricePerToken !== null) {
            $parts[] = '$' . self::perMillion($model->inputPricePerToken) . '/$' . self::perMillion($model->outputPricePerToken);
        }
        if (in_array('tools', $model->supportedParameters, true)) {
            $parts[] = 'tools';
        }
        return implode('; ', $parts);
    }

    private static function perMillion(float $perToken): string {
        $text = rtrim(rtrim(number_format($perToken * 1_000_000.0, 4, '.', ''), '0'), '.');
        return $text === '' ? '0' : $text;
    }
}
