<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DateTimeImmutable;
use OpenCCK\Domain\Agent\TraceSpan;

interface TraceRepository {
    /** The step of the run itself; a run resumed after a restart gets the same one back. */
    public function openRun(int $runId, string $name): int;

    public function closeRun(int $runId, string $status, int $durationMs, ?string $error): void;

    /** Saves a finished step; returns its id. */
    public function record(TraceSpan $span): int;

    /**
     * Full request and response of a step, already masked.
     *
     * @param array<array-key, mixed> $request
     * @param array<array-key, mixed> $response
     */
    public function payload(int $spanId, array $request, array $response): void;

    /** @return array{request: array<array-key, mixed>, response: array<array-key, mixed>}|null */
    public function payloadOf(int $spanId): ?array;

    /** Deletes payloads saved before $before; returns how many. Steps stay. */
    /**
     * Replaces a text in every kept prompt and answer (a forgotten fact); returns the payloads changed. The text is
     * matched as it stands inside a JSON string.
     */
    public function redactPayloads(string $text, string $replacement): int;

    public function purgePayloads(DateTimeImmutable $before): int;

    /** @return list<TraceSpan> steps of the run in order */
    public function spans(int $runId): array;
}
