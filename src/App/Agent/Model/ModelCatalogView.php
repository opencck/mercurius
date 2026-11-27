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
    private const KNOWN = [
        ModelRef::OPENROUTER, ModelRef::OPENAI, ModelRef::ANTHROPIC, ModelRef::BEDROCK, ModelRef::GEMINI, ModelRef::VERTEX,
        ModelRef::CODEX, ModelRef::CHATGPT, ModelRef::CLAUDE, ModelRef::ANTIGRAVITY,
    ];
    /** the owner's command of a subscription: its sign-in and its quota */
    private const COMMANDS = [ModelRef::CHATGPT => '/codex', ModelRef::CLAUDE => '/claude', ModelRef::ANTIGRAVITY => '/antigravity'];

    /** @param list<string> $providers the providers that have a key or a module now */
    public function __construct(
        private readonly ModelCatalogRepository $catalog,
        private readonly array $providers,
        private readonly ?ProviderPauses $pauses = null,
    ) {}

    /** The providers the agent has, what each is and how many models its catalog holds; then the ones it has not. */
    public function providers(): string {
        $lines = [t('model.catalog.providers_head')];
        foreach ($this->providers as $provider) {
            $count = count($this->catalog->models($provider));
            $command = self::COMMANDS[$provider] ?? null;
            $line = $command === null ? sprintf(t('model.catalog.by_key'), $count) : sprintf(t('model.catalog.by_subscription'), $count, $command);
            $pause = $this->pauses?->anyOf($provider);
            if ($pause !== null) {
                $line .= '; ' . match ($pause['why']) {
                    ProviderPauses::SIGN_IN => sprintf(t('model.catalog.paused_sign_in'), ($command ?? '') . ' login'),
                    ProviderPauses::LIMIT => sprintf(t('model.catalog.paused_limit'), gmdate('Y-m-d H:i', $pause['until']) . ' UTC'),
                    default => t('model.catalog.paused_account'),
                };
            }
            $lines[] = '- ' . $provider . ': ' . $line;
        }
        $missing = array_values(array_diff(self::KNOWN, $this->providers));
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
        if (!in_array($provider, $this->providers, true)) {
            throw new DomainException(sprintf(t('model.catalog.no_provider'), $provider === '' ? '—' : $provider, implode(', ', $this->providers)));
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
