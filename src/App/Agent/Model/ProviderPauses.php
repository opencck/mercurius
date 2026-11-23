<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use Closure;
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

    /** @var array<string, array{until: int, why: string}> */
    private array $paused = [];
    /** @var array<string, true> the windows the owner has heard of: provider|why|until */
    private array $told = [];
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param ?Closure(string, string, int): void $notify tells the owner: provider, why (LIMIT or SIGN_IN), until
     * @param ?Closure(): int $clock
     */
    public function __construct(
        private ?Closure $notify = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Who tells the owner: the channel to the owner is made after the models.
     *
     * @param Closure(string, string, int): void $notify
     */
    public function tellWith(Closure $notify): void {
        $this->notify = $notify;
    }

    public function pause(string $provider, int $until, string $why): void {
        if ($until <= ($this->clock)()) {
            return;
        }
        $this->paused[$provider] = ['until' => $until, 'why' => $why];
        $key = $provider . '|' . $why . '|' . ($why === self::LIMIT ? (string) $until : '');
        if (isset($this->told[$key]) || $this->notify === null) {
            return;
        }
        $this->told[$key] = true;
        if (count($this->told) > 256) {
            $this->told = [$key => true];
        }
        try {
            ($this->notify)($provider, $why, $until);
        } catch (Throwable) {
            // the notice is a courtesy: the pause works without it
        }
    }

    /** A pause for so many seconds from now, by the clock of the pauses. */
    public function pauseFor(string $provider, int $seconds, string $why): void {
        $this->pause($provider, ($this->clock)() + $seconds, $why);
    }

    /** The provider serves again: the owner signed in. */
    public function resume(string $provider): void {
        unset($this->paused[$provider]);
        foreach (array_keys($this->told) as $key) {
            if (str_starts_with($key, $provider . '|' . self::SIGN_IN . '|')) {
                unset($this->told[$key]);
            }
        }
    }

    /** @return ?array{until: int, why: string} the pause that stands now */
    public function of(string $provider): ?array {
        $pause = $this->paused[$provider] ?? null;
        if ($pause !== null && $pause['until'] <= ($this->clock)()) {
            unset($this->paused[$provider]);
            return null;
        }
        return $pause;
    }

    public function paused(string $provider): bool {
        return $this->of($provider) !== null;
    }
}
