<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use Closure;
use DateTimeImmutable;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Repository\JobRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use Psr\Log\LoggerInterface;

/**
 * Takes a webhook for a job by event. The request is signed with a secret from the vault: HMAC-SHA256 of
 * "<timestamp>.<body>" with a timestamp within five minutes, or GitHub's HMAC of the body. A delivery is taken once,
 * also after a restart; a repeat gets 200 without a run, a busy job — 409, so the provider retries.
 *
 * spec: BRIEF.md#планировщик-и-проекты — events by webhook with HMAC and protection from a repeat
 * ref: references/hermes-agent/gateway/platforms/webhook.py@e96221fa:L874-L935 (_validate_signature) — generic V2 over
 *      "<timestamp>.<body>", GitHub sha256, body-only V1 rejected; L382-L391 (_record_delivery_id) — a delivery taken
 *      once. Here deliveries are kept in the database, and a GitHub delivery is known by the hash of its body: the
 *      delivery header is not signed, so it cannot tell a replay from a new delivery.
 * ref: references/hermes-agent/gateway/platforms/_shared.py@e96221fa:L46-L51 (timestamp_fresh) — the 300 s window
 */
final class HookReceiver {
    public const WINDOW = 300;
    public const BODY_MAX = 65_536;
    public const PER_HOUR = 60;
    private const EVENT_MAX = 8_000;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /** @param (Closure(): DateTimeImmutable)|null $now */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobScheduler $scheduler,
        private readonly Vault $vault,
        private readonly PrincipalRepository $principals,
        private readonly LoggerInterface $logger,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @param array<string, string> $headers names in lower case
     * @return array{int, string} HTTP status and a short text
     */
    public function receive(int $jobId, array $headers, string $body): array {
        $job = $this->jobs->find($jobId);
        $principal = $job === null ? null : $this->principals->find($job->principalId);
        $secret = $job === null || $principal === null || !$job->byEvent() || $job->status !== Job::ACTIVE || $job->hookSecret === null
            ? null
            : $this->vault->reveal($job->hookSecret, $principal);
        if ($job === null || $secret === null) {
            // The same answer for a missing job and a missing secret: the address tells nothing.
            return [404, 'not found'];
        }
        if (strlen($body) > self::BODY_MAX) {
            return [413, 'too large'];
        }
        $delivery = $this->verify($headers, $body, $secret);
        if ($delivery === null) {
            $this->logger->warning('Webhook signature refused', ['job' => $job->id]);
            return [401, 'bad signature'];
        }
        if ($this->jobs->deliveriesSince($job->id, ($this->now)()->modify('-1 hour')) >= self::PER_HOUR) {
            return [429, 'too many'];
        }
        if (!$this->jobs->claimEvent($job->id)) {
            return [409, 'busy'];
        }
        if (!$this->jobs->recordDelivery($job->id, $delivery)) {
            $this->jobs->release($job->id);
            return [200, 'duplicate'];
        }
        $this->logger->info('Webhook accepted', ['job' => $job->id]);
        $this->scheduler->runEvent($job, $this->event($headers, $body));
        return [202, 'accepted'];
    }

    /**
     * The delivery id when the signature holds; null otherwise.
     *
     * @param array<string, string> $headers
     */
    private function verify(array $headers, string $body, string $secret): ?string {
        $v2 = strtolower(trim($headers['x-webhook-signature-v2'] ?? ''));
        if ($v2 !== '') {
            $timestamp = trim($headers['x-webhook-timestamp'] ?? '');
            if (!ctype_digit($timestamp) || abs(($this->now)()->getTimestamp() - (int) $timestamp) > self::WINDOW) {
                return null;
            }
            $v2 = str_starts_with($v2, 'sha256=') ? substr($v2, 7) : $v2;
            if (!hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $v2)) {
                return null;
            }
            // Within the window a signature is unique to its timestamp and body.
            return 'v2:' . hash('sha256', $timestamp . '.' . $v2);
        }
        $github = strtolower(trim($headers['x-hub-signature-256'] ?? ''));
        if ($github !== '') {
            return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $github) ? 'gh:' . hash('sha256', $body) : null;
        }
        // A body-only MAC without a timestamp (X-Webhook-Signature) can be replayed forever: refused.
        return null;
    }

    /** @param array<string, string> $headers */
    private function event(array $headers, string $body): string {
        $type = trim($headers['x-github-event'] ?? $headers['x-webhook-event'] ?? '');
        $text = mb_scrub($body, 'UTF-8');
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            $text = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
        }
        if (mb_strlen($text) > self::EVENT_MAX) {
            $text = mb_substr($text, 0, self::EVENT_MAX) . "\n… (обрезано)";
        }
        return ($type === '' ? '' : 'Тип события: ' . mb_substr($type, 0, 100) . "\n") . $text;
    }
}
