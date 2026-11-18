<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Closure;
use InvalidArgumentException;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Amp\TimeoutCancellation;
use OpenCCK\App\Agent\Tool\MemoryProposeTool;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use OpenCCK\Memory\App\Proposals;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Every N turns of a person a cheap model reads the last turns and proposes facts worth keeping; each goes to the
 * owner's card, nothing is remembered without it. A turn where the agent proposed a fact itself starts the count again.
 *
 * spec: BRIEF.md#память-слои — the background proposal of facts
 * ref: references/hermes-agent/agent/turn_context.py@e96221fa:L820-L829 (_tick_memory_nudge) — every
 *      memory.nudge_interval (10) user turns, reset when the model used the memory tool itself
 * ref: references/hermes-agent/agent/background_review.py@e96221fa:L446-L472 (_MEMORY_ROUTING_BLOCK,
 *      _MEMORY_REVIEW_PROMPT) — only facts grounded in the conversation, no guessed traits, a one-off request is not a
 *      standing preference. Hermes forks the agent on the main model with tools and writes at once; here one call of
 *      the cheap model without tools, and the owner decides
 */
final class FactNudge {
    public const EVERY = 10;
    public const MAX_FACTS = 3;
    private const TURNS_TEXT = 12_000;
    private const SKIPPED_CHANNELS = ['a2a', 'eval'];

    /** @var array<int, int> turns since the last review, by conversation */
    private array $turns = [];

    /**
     * @param Closure(): string $cheapModel
     * @param int $every turns between reviews; 0 — off
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Closure $cheapModel,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly Proposals $proposals,
        private readonly MemoryCards $cards,
        private readonly ConversationAudience $audience,
        private readonly LoggerInterface $logger,
        private readonly int $every = self::EVERY,
        private readonly float $timeout = 60.0,
    ) {}

    public function afterRun(Run $run, Principal $principal): void {
        if ($this->every <= 0 || $run->stopReason !== StopReason::Answered || $principal->kind === PrincipalKind::Agent) {
            return;
        }
        $meta = $this->conversations->meta($run->conversationId);
        if ($meta === null || in_array($meta->channel, self::SKIPPED_CHANNELS, true)) {
            return;
        }
        foreach ($this->transcript->runMessages($run->id) as $message) {
            foreach ($message->toolCalls as $call) {
                if ($call->name === MemoryProposeTool::NAME) {
                    unset($this->turns[$run->conversationId]);
                    return;
                }
            }
        }
        $this->turns[$run->conversationId] = ($this->turns[$run->conversationId] ?? 0) + 1;
        if ($this->turns[$run->conversationId] < $this->every) {
            return;
        }
        unset($this->turns[$run->conversationId]);
        try {
            $this->review($run, $principal);
        } catch (Throwable $e) {
            $this->logger->warning('Background fact review failed', ['run' => $run->id, 'error' => $e::class]);
        }
    }

    private function review(Run $run, Principal $principal): void {
        $scopes = $this->audience->scopes($principal, $run->conversationId);
        if ($scopes === []) {
            return;
        }
        $lines = [];
        foreach ($this->transcript->history($run->conversationId, $run->id + 1, max(2, $this->every * 2)) as $message) {
            if (($message->role === Role::User || $message->role === Role::Assistant) && trim($message->content) !== '') {
                $lines[] = ($message->role === Role::User ? t('agent.fact_nudge.person') : t('agent.fact_nudge.agent')) . ': ' . trim($message->content);
            }
        }
        $text = SecretMasker::mask(mb_substr(implode("\n\n", $lines), -self::TURNS_TEXT));
        if ($text === '') {
            return;
        }
        $response = $this->llm->complete(new ChatRequest(
            ($this->cheapModel)(),
            [Message::system(t('agent.fact_nudge.prompt')), Message::user($text)],
            [],
            800,
        ), new TimeoutCancellation($this->timeout));
        $sent = 0;
        foreach (self::facts($response->text) as $fact) {
            try {
                $proposal = $this->proposals->propose('add', null, $fact['text'], t('agent.fact_nudge.reason') . $fact['reason'], $scopes, $principal->id, $run->id);
            } catch (InvalidArgumentException $e) {
                $this->logger->info('Background fact not proposed', ['run' => $run->id, 'why' => $e->getMessage()]);
                continue;
            }
            $this->cards->show($proposal);
            $sent++;
        }
        $this->logger->info('Background fact review', ['run' => $run->id, 'proposed' => $sent]);
    }

    /** @return list<array{text: string, reason: string}> at most MAX_FACTS; nothing when the answer is not the JSON asked */
    public static function facts(string $answer): array {
        $answer = trim($answer);
        if (preg_match('~\{.*\}~s', $answer, $m) !== 1) {
            return [];
        }
        try {
            /** @var mixed $data */
            $data = json_decode($m[0], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        /** @var array<array-key, mixed> $list */
        $list = is_array($data) && isset($data['facts']) && is_array($data['facts']) ? $data['facts'] : [];
        $facts = [];
        /** @var mixed $item */
        foreach ($list as $item) {
            if (is_array($item) && isset($item['text']) && is_string($item['text']) && trim($item['text']) !== '') {
                $reason = isset($item['reason']) && is_string($item['reason']) ? trim($item['reason']) : '';
                $facts[] = ['text' => trim($item['text']), 'reason' => mb_substr($reason, 0, 300)];
            }
            if (count($facts) >= self::MAX_FACTS) {
                break;
            }
        }
        return $facts;
    }
}
