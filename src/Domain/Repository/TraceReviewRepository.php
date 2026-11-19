<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;

/**
 * What the traces of a period say, for the agent's review of itself: where runs stopped, which tools failed or were
 * refused, calls repeated after a refusal, the costliest runs, the spend by purpose.
 *
 * spec: BRIEF.md#самоулучшение-решено-2026-11-18
 */
interface TraceReviewRepository {
    /**
     * @return array{
     *     stops: list<array{reason: string, runs: int, tokens: int}>,
     *     tools: list<array{name: string, calls: int, failed: int, denied: int, sample: string}>,
     *     retried: int,
     *     costly: list<array{run: int, tokens: int, turns: int, reason: string}>,
     *     spend: list<array{purpose: string, usd: float, calls: int}>,
     *     refused: list<array{name: string, calls: int, runs: int}>,
     *     afterRefusal: list<array{run: int, denied: string, then: string}>
     * }
     */
    public function review(DateTimeImmutable $since): array;
}
