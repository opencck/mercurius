<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use Closure;
use OpenCCK\Domain\Agent\ModelRef;
use Throwable;

/**
 * Providers taken off the choice for a while: a subscription whose window of the plan is used up until it reopens, a
 * subscription without a sign-in until the owner signs in again. A call to such a provider fails at once, without a
 * request, and the run moves to another model of the same category; the owner hears once per window.
 *
 * The state is in memory: after a restart the first call learns it again from the provider.
 *
 * spec: brief/models.md#лимиты-подписки-и-переход-на-модель-категории
 */
final class ProviderPauses {
    public const LIMIT = 'limit';
    public const SIGN_IN = 'sign_in';
    /** the provider of the plan asks the owner of the account to act: nothing is sent until the owner says so */
    public const ACCOUNT = 'account';

    /** @var array<string, array{until: int, why: string, word: string}> by provider or by model */
    private array $paused = [];
    /** @var array<string, true> the windows the owner has heard of: provider|why|until */
    private array $told = [];
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param ?Closure(string, string, int, ?string): void $notify tells the owner: provider or model, why (LIMIT, SIGN_IN or ACCOUNT), until, the words of the provider
     * @param ?Closure(): int $clock
     * @param ?Closure(string): string $word the owner's word on paid use for a provider: a pause by a limit stands only
     *        while that word is what it was — a word that allows more lifts it at once
     */
    public function __construct(
        private ?Closure $notify = null,
        ?Closure $clock = null,
        private readonly ?Closure $word = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Who tells the owner: the channel to the owner is made after the models.
     *
     * @param Closure(string, string, int, ?string): void $notify
     */
    public function tellWith(Closure $notify): void {
        $this->notify = $notify;
    }

    /**
     * @param string $provider a provider (a lost sign-in) or a model "provider:model" (a used-up window: plans have
     *        windows per model, so the other models of the subscription go on)
     */
    public function pause(string $provider, int $until, string $why, ?string $said = null): void {
        if ($until <= ($this->clock)()) {
            return;
        }
        $this->paused[$provider] = ['until' => $until, 'why' => $why, 'word' => $this->wordOf($provider)];
        $key = $provider . '|' . $why . '|' . ($why === self::LIMIT ? (string) $until : '');
        if (isset($this->told[$key]) || $this->notify === null) {
            return;
        }
        $this->told[$key] = true;
        if (count($this->told) > 256) {
            $this->told = [$key => true];
        }
        try {
            ($this->notify)($provider, $why, $until, $said);
        } catch (Throwable) {
            // the notice is a courtesy: the pause works without it
        }
    }

    /** A pause for so many seconds from now, by the clock of the pauses. */
    public function pauseFor(string $provider, int $seconds, string $why, ?string $said = null): void {
        $this->pause($provider, ($this->clock)() + $seconds, $why, $said);
    }

    /** The provider serves again: the owner signed in. */
    public function resume(string $provider): void {
        unset($this->paused[$provider]);
        foreach (array_keys($this->told) as $key) {
            if (str_starts_with($key, $provider . '|' . self::SIGN_IN . '|') || str_starts_with($key, $provider . '|' . self::ACCOUNT . '|')) {
                unset($this->told[$key]);
            }
        }
    }

    /** @return ?array{until: int, why: string} the pause that stands now for a provider or a model */
    public function of(string $provider): ?array {
        $pause = $this->paused[$provider] ?? null;
        if ($pause === null) {
            return null;
        }
        if ($pause['until'] <= ($this->clock)() || ($pause['why'] === self::LIMIT && $pause['word'] !== $this->wordOf($provider))) {
            unset($this->paused[$provider]);
            return null;
        }
        return ['until' => $pause['until'], 'why' => $pause['why']];
    }

    public function paused(string $provider): bool {
        return $this->of($provider) !== null;
    }

    /** @return ?array{until: int, why: string} the pause of the model or of its provider */
    public function blocking(ModelRef $model): ?array {
        return $this->of((string) $model) ?? $this->of($model->provider);
    }

    /** @return ?array{until: int, why: string} the pause of a model of the provider, if any: what /claude shows */
    public function anyOf(string $provider): ?array {
        $found = $this->of($provider);
        foreach (array_keys($this->paused) as $key) {
            if ($found === null && str_starts_with($key, $provider . ':')) {
                $found = $this->of($key);
            }
        }
        return $found;
    }

    private function wordOf(string $key): string {
        $provider = explode(':', $key, 2)[0];
        return $this->word === null ? '' : ($this->word)($provider);
    }
}
