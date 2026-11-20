<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\Domain\Repository\SpendRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Caps in money over everything the agent spends: a run, its workers, background work and compaction alike. A token
 * budget does not see price: the same tokens cost fifty times more on a strong model.
 *
 * spec: brief/agent-loop.md#лимиты — daily and monthly caps in USD
 * ref: references/claude-cookbooks/managed_agents/CMA_cap_session_spend.ipynb@ce8b262 — one cap in money across all
 *      threads, subagents included; here a run stops at the cap instead of pausing
 */
final class SpendGuard {
    public function __construct(
        private readonly SpendRepository $spend,
        private readonly float $dailyCap,
        private readonly float $monthlyCap,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Why no more model calls may start now; null while within the caps.
     *
     * @param float $participantCap USD a UTC day of this participant may cost (their policy); 0 — no cap of their own
     *
     * spec: brief/participants.md#несколько-пользователей — each participant has their own spend and cap
     */
    public function exceeded(?int $principalId = null, float $participantCap = 0.0): ?string {
        if ($this->dailyCap <= 0.0 && $this->monthlyCap <= 0.0 && ($participantCap <= 0.0 || $principalId === null)) {
            return null;
        }
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($principalId !== null && $participantCap > 0.0) {
                $own = $this->spend->spentBy($principalId, $now->setTime(0, 0));
                if ($own >= $participantCap) {
                    return sprintf(t('agent.spend_guard.participant_cap'), $participantCap, $own);
                }
            }
            if ($this->dailyCap > 0.0) {
                $day = $this->spend->spentSince($now->setTime(0, 0));
                if ($day >= $this->dailyCap) {
                    return sprintf(t('agent.spend_guard.dnevnoy_limit_rashoda_dostignut_potrache'), $this->dailyCap, $day);
                }
            }
            if ($this->monthlyCap > 0.0) {
                $month = $this->spend->spentSince($now->modify('first day of this month')->setTime(0, 0));
                if ($month >= $this->monthlyCap) {
                    return sprintf(t('agent.spend_guard.mesyachnyy_limit_rashoda_dostignut_potra'), $this->monthlyCap, $month);
                }
            }
        } catch (Throwable $e) {
            // A cap that cannot be checked does not stop the agent: the owner would not know why.
            $this->logger->error('Could not check the spend caps', ['error' => $e::class]);
        }
        return null;
    }
}
