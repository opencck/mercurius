<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Closure;
use DateTimeImmutable;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\App\Agent\Trace\NullEvents;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Agent\Approval;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ApprovalRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

use function Mercurius\I18n\t;

/**
 * Approvals in the owner's private chat (presses "ap:<id>:a|g|d") and the list of saved grants (/permissions,
 * presses "pg:<id>:x" revoke one).
 *
 * Approvals in the owner's private chat: a card with the action shown in full (secrets masked), three buttons,
 * an atomic decision, then the card is edited and the run resumes. Silence past the timeout is a denial; so is a
 * text from the owner while the run waits, with that text as the reason.
 *
 * spec: brief/agent-loop.md#подтверждения-и-запреты
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221fa:L3844-L3861 (_send_exec_approval_prompt),
 *      L4347-L4389 (_handle_exec_approval_callback) — short id in the button, decide first then edit the card,
 *      "already decided" for a second press; the approval lives in the database here, not in process memory
 */
final class ApprovalService implements ApprovalGate, CallbackHandler, TakesOwnPresses {
    /** Refusals in a row after which exactly the same action is refused without asking the owner again. */
    public const REFUSALS = 3;
    /** Longer actions go to the owner as a file next to the card: you cannot approve what you cannot see. */
    private const CARD_LIMIT = 3000;
    /** Seconds of flood the card waits out before the action is refused. */
    private const FLOOD_WAIT = 30;

    /** @var (Closure(int): void)|null resumes a run after its approval is decided */
    private ?Closure $resume = null;

    /** @param positive-int $timeoutSeconds AGENT_APPROVAL_TIMEOUT */
    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
        private readonly int $timeoutSeconds,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly AgentEvents $events = new NullEvents(),
    ) {}

    private ?string $participantChannel = null;
    private ?\OpenCCK\Domain\Repository\RunRepository $runs = null;

    /** The runs, to know the conversation a grant "for this conversation" lasts for and whether the run read outside data. */
    public function useRuns(\OpenCCK\Domain\Repository\RunRepository $runs): void {
        $this->runs = $runs;
    }

    /** @param Closure(int): void $resume */
    public function onDecision(Closure $resume): void {
        $this->resume = $resume;
    }

    public function request(Run $run, ValidatedCall $call, Principal $principal, ?Principal $decider = null): void {
        // spec: brief/skills-mcp.md#ящики-участников-и-проектов — the card of a participant's own thing goes to that participant
        $chat = $decider === null ? OwnerDirectory::chat($this->ownerChatId) : $this->privateChat($decider);
        $args = $call->args ?? [];
        // spec: brief/agent-loop.md#разрешения-навсегда — every action has a key: its own, else "any call of this tool"
        $keys = GrantKeys::of($call->tool, $args, $call->call->name);
        $key = $keys->exact;
        $label = SecretMasker::mask($call->tool instanceof GrantKeyed ? $call->tool->describe($args) : $call->call->name);
        $shown = SecretMasker::mask(json_encode(SecretMasker::maskArgs($args), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $summary = $label . "\n" . $call->call->name . ' ' . $shown;
        // ref: references/hermes-agent/tools/approval.py@e96221fa:L64-L111 (denial circuit breaker) — after three refusals in
        //      a row the answer is a hard stop; here the owner is not asked a fourth time about exactly the same action
        $refusedBefore = $this->approvals->deniedInARow($principal->id, $summary, new DateTimeImmutable('-1 day'));
        $approval = $this->approvals->create(
            $run->id,
            $call->call->id,
            $principal->id,
            $call->call->name,
            $summary,
            $key,
            new DateTimeImmutable(sprintf('+%d seconds', $this->timeoutSeconds)),
        );
        if ($keys->wide !== null) {
            $this->approvals->setWideKey($approval->id, $keys->wide[0]);
        }
        if ($chat === null) {
            $decided = $this->approvals->decide($approval->id, ApprovalStatus::Denied, t('agent.approval_service.decider_unreachable'), null);
            if ($decided !== null) {
                // The run is still pausing in this fiber: it continues once the pause is saved.
                EventLoop::defer(fn() => $this->continue($decided));
            }
            return;
        }
        if ($refusedBefore >= self::REFUSALS) {
            $this->logger->notice('Action refused without a card: refused in a row before', ['approval' => $approval->id, 'run' => $run->id, 'tool' => $call->call->name]);
            $decided = $this->approvals->decide($approval->id, ApprovalStatus::Denied, sprintf(t('agent.approval_service.refused_in_a_row'), $refusedBefore), null);
            if ($decided !== null) {
                // The run is still pausing in this fiber: it continues once the pause is saved.
                EventLoop::defer(fn() => $this->continue($decided));
            }
            return;
        }

        $inFile = mb_strlen($shown) > self::CARD_LIMIT;
        // ref: references/hermes-agent/gateway/platforms/base.py@e96221fa:L2744-L2763 (send_exec_approval) — an action
        //      the owner could not read in full can only be refused
        $unreadable = $inFile && !$this->channel->sendFile($chat, sprintf('approval-%d.json', $approval->id), $shown, t('agent.approval_service.deystvie_tselikom'))->success;
        $text = sprintf(
            t('agent.approval_service.podtverdite_deystvie_instrument_argument'),
            $label,
            $call->call->name,
            $unreadable ? t('agent.approval_service.pokazat_tselikom_ne_udalos_podtverdit') : ($inFile ? t('agent.approval_service.tselikom_v_fayle_vyshe') : $shown),
            $principal->displayName ?? (t('agent.approval_service.uchastnik') . $principal->id),
            (new DateTimeImmutable(sprintf('+%d seconds', $this->timeoutSeconds)))->setTimezone(new \DateTimeZone('UTC'))->format('H:i'),
        );
        if ($run->outside !== null) {
            // spec: brief/agent-loop.md#подтверждения-и-запреты — the owner sees why a grant was not used and what may have steered the agent
            $text .= sprintf(t('agent.approval_service.outside_note'), \OpenCCK\App\Service\MessageMeta::inline($run->outside));
        }
        // spec: brief/agent-loop.md#разрешения-навсегда — three terms: this once, this conversation, for good; the button
        //       "for good" names what it allows, and a wider grant of the tool stands next to it
        $rows = [];
        if (!$unreadable) {
            $text .= sprintf(t('agent.grants.card_key'), GrantKeys::label($key));
            if ($run->outside !== null) {
                $text .= t('agent.grants.card_outside_risk');
            }
            $rows[] = [
                new Button(t('agent.grants.once'), sprintf('ap:%d:a', $approval->id)),
                new Button(t('agent.grants.session'), sprintf('ap:%d:s', $approval->id)),
            ];
            $rows[] = [new Button(t('agent.grants.forever'), sprintf('ap:%d:g', $approval->id))];
            if ($keys->wide !== null) {
                $rows[] = [new Button(mb_substr(sprintf(t('agent.grants.forever_wide'), $keys->wide[1]), 0, 60), sprintf('ap:%d:w', $approval->id))];
            }
        }
        $rows[] = [new Button(t('agent.approval_service.otkazat'), sprintf('ap:%d:d', $approval->id))];
        // spec: brief/agent-loop.md#запреты — "never" refuses and bans the action, the exact one or the wide one
        $never = [new Button(t('agent.bans.never'), sprintf('ap:%d:n', $approval->id))];
        if ($keys->wide !== null) {
            $never[] = new Button(mb_substr(sprintf(t('agent.bans.never_wide'), $keys->wide[1]), 0, 60), sprintf('ap:%d:m', $approval->id));
        }
        $rows[] = $never;
        // arguments may carry an address from outside data: no preview of it in the card
        $card = new OutgoingMessage($chat, $text, disableLinkPreview: true, buttons: $rows);
        $sent = $this->channel->send($card);
        // The run streams into the same chat: a short flood is waited out before the action is refused.
        if (!$sent->success && $sent->errorKind === \Mercurius\Messenger\SendErrorKind::RateLimited && $sent->retryAfter !== null && $sent->retryAfter <= self::FLOOD_WAIT) {
            \Amp\delay((float) $sent->retryAfter);
            $sent = $this->channel->send($card);
        }
        if ($sent->success && $sent->messageId !== null) {
            $this->approvals->setCard($approval->id, $chat, $sent->messageId);
        } elseif (!$sent->success) {
            // ref: references/hermes-agent/tools/approval.py@e96221fa:L950 — a card nobody can see is a refusal now,
            //      not a wait for the timeout
            $this->logger->warning('Approval card was not delivered; refused', ['approval' => $approval->id, 'run' => $run->id]);
            $decided = $this->approvals->decide($approval->id, ApprovalStatus::Denied, t('agent.approval_service.kartochka_podtverzhdeniya_ne_doshla_do'), null);
            if ($decided !== null) {
                $this->closeCard($decided, t('agent.approval_service.kartochka_ne_doshla_otkaz'));
                // The run is still pausing in this fiber: it continues once the pause is saved.
                EventLoop::defer(fn() => $this->continue($decided));
            }
            return;
        }
        $this->logger->info('Approval requested', ['approval' => $approval->id, 'run' => $run->id, 'tool' => $call->call->name]);
    }

    /** Sends the active grants with a revoke button each; false when there are none. */
    /**
     * The channel where people have private chats: a card for a participant goes there.
     *
     * spec: brief/skills-mcp.md#ящики-участников-и-проектов
     */
    public function useParticipantCards(string $channelName): void {
        $this->participantChannel = $channelName;
    }

    /** The private chat of a person; an agent and a person with no chat have none. */
    private function privateChat(Principal $decider): ?string {
        if ($this->participantChannel === null || $decider->isAgent()) {
            return null;
        }
        return $this->principals->externalId($decider->id, $this->participantChannel);
    }

    /**
     * A press on a card that was sent to a participant, in the private chat it was sent to: theirs to make.
     *
     * spec: brief/skills-mcp.md#ящики-участников-и-проектов — the owner of a mailbox confirms what is sent from it
     */
    public function ownPress(IncomingCallback $callback): bool {
        if (preg_match('/^ap:(\d+):[asgwdnm]$/', $callback->data, $match) !== 1 || $callback->userId !== $callback->chatId) {
            return false;
        }
        $approval = $this->approvals->find((int) $match[1]);
        return $approval !== null && $approval->cardChatId !== null && $approval->cardChatId === $callback->chatId;
    }

    /**
     * The list of saved grants: the summary, or the part the argument names; false when there are no grants.
     *
     * spec: brief/agent-loop.md#список-разрешений
     */
    public function showGrants(string $chatId, ?string $replyTo, string $argument = ''): bool {
        $bans = count($this->approvals->bans());
        if ($this->approvals->grants() === []) {
            if ($bans === 0) {
                return false;
            }
            $this->channel->send(new OutgoingMessage($chatId, sprintf(t('agent.bans.in_list'), $bans), $replyTo));
            return true;
        }
        $selector = GrantList::selectorOf($argument);
        [$text, $rows] = $selector === null ? [t('agent.grants.list_usage'), []] : $this->list()->view($selector);
        // spec: brief/agent-loop.md#запреты — the list of grants names the bans; they are seen and lifted by /deny
        if ($selector === '' && $bans > 0) {
            $text .= "\n\n" . sprintf(t('agent.bans.in_list'), $bans);
        }
        $this->channel->send(new OutgoingMessage($chatId, $text, $replyTo, disableLinkPreview: true, buttons: $rows));
        return true;
    }

    private function list(): GrantList {
        return new GrantList($this->approvals, $this->principals);
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (str_starts_with($callback->data, 'pg:')) {
            // spec: brief/agent-loop.md#список-разрешений — every press redraws the same message
            $pressed = $this->list()->press($callback->data);
            if ($pressed === null) {
                $this->channel->answerCallback($callback->callbackId);
                return;
            }
            [$answer, [$text, $rows]] = $pressed;
            $this->channel->answerCallback($callback->callbackId, $answer === '' ? null : $answer);
            if ($answer !== '') {
                $this->logger->info('Grants changed from the list', ['press' => preg_replace('~^(pg:\w+):.*$~', '$1', $callback->data)]);
            }
            if ($callback->messageId !== null) {
                $this->channel->edit($callback->chatId, $callback->messageId, $text, buttons: $rows);
            }
            return;
        }
        if (preg_match('/^gn:(\d+):([jad])$/', $callback->data, $needs) === 1) {
            $this->decideNeeds($callback, (int) $needs[1], $needs[2]);
            return;
        }
        if (preg_match('/^ap:(\d+):([asgwdnm])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $id = (int) $match[1];
        $approval = $this->approvals->find($id);
        $refused = in_array($match[2], ['d', 'n', 'm'], true);
        $presser = $this->principals->findByIdentity($callback->channel, $callback->userId)?->id;
        $decided = $approval === null ? null : $this->approvals->decide(
            $id,
            $refused ? ApprovalStatus::Denied : ApprovalStatus::Approved,
            $match[2] === 'd' ? t('agent.approval_service.otkazano_vladeltsem') : ($refused ? t('agent.bans.refused_by_owner') : null),
            $presser,
        );
        if ($decided === null) {
            $this->channel->answerCallback($callback->callbackId, t('agent.approval_service.uzhe_resheno'));
            return;
        }
        if ($match[2] === 'n' || $match[2] === 'm') {
            // spec: brief/agent-loop.md#запреты — a ban of the participant who asked, as a grant from a card would be;
            //       it is lifted from the list, not from a card
            $key = $match[2] === 'm' ? ($decided->wideKey ?? $decided->grantKey) : $decided->grantKey;
            if ($key !== null) {
                $words = $key === $decided->grantKey ? mb_substr(explode("\n", $decided->summary, 2)[0], 0, 200) : null;
                $this->approvals->addBan($decided->principalId, '', $key, $words === '' ? null : $words, $presser);
                $this->logger->info('Ban saved from a card', ['key' => $key]);
            }
            $this->channel->answerCallback($callback->callbackId, t('agent.bans.saved_short'));
            $this->closeCard($decided, sprintf(t('agent.bans.saved'), GrantKeys::label($key ?? '')));
            $this->continue($decided);
            return;
        }
        // spec: brief/agent-loop.md#разрешения-навсегда — a grant given on a card of a run that read outside data applies
        //       after outside data too: the owner saw the warning on that card
        $run = $this->runs?->find($decided->runId);
        $outside = $run?->outside !== null;
        $saved = match ($match[2]) {
            'g' => $decided->grantKey,
            'w' => $decided->wideKey ?? $decided->grantKey,
            's' => $run === null ? null : $decided->grantKey,
            default => null,
        };
        if ($saved !== null) {
            // spec: brief/agent-loop.md#список-разрешений — the exact grant keeps what it allows in words, for the list
            $words = $saved === $decided->grantKey ? mb_substr(explode("\n", $decided->summary, 2)[0], 0, 200) : null;
            $this->approvals->addGrant($decided->principalId, $saved, $match[2] === 's' ? $run?->conversationId : null, $outside, $words === '' ? null : $words);
            $this->logger->info('Grant saved', ['key' => $saved, 'term' => $match[2], 'outside' => $outside]);
        }
        $this->channel->answerCallback($callback->callbackId, match ($match[2]) {
            'a' => t('agent.approval_service.razresheno'),
            's' => t('agent.grants.saved_session_short'),
            'g', 'w' => t('agent.approval_service.razresheno_i_zapomneno'),
            default => t('agent.approval_service.otkazano'),
        });
        $this->closeCard($decided, match (true) {
            $match[2] === 'd' => t('agent.approval_service.otkazano_vladeltsem'),
            $saved === null => t('agent.approval_service.razresheno_vladeltsem'),
            $match[2] === 's' => sprintf(t('agent.grants.saved_session'), GrantKeys::label($saved)),
            default => sprintf(t('agent.approval_service.razresheno_vladeltsem_i_zapomneno'), GrantKeys::label($saved)) . ($outside ? t('agent.grants.saved_outside') : ''),
        });
        $this->continue($decided);
    }

    /**
     * Denies what the run waits for without resuming it: the owner wrote instead of pressing (their words are the
     * reason), stopped the run, or the run failed. The card gets the outcome.
     */
    /** @param int|null $decidedBy the person who refused (the owner wrote instead of pressing); null — not a person's refusal */
    public function denyPending(int $runId, string $reason, string $outcome, ?int $decidedBy = null): bool {
        $pending = $this->approvals->pendingForRun($runId);
        $decided = $pending === null ? null : $this->approvals->decide($pending->id, ApprovalStatus::Denied, $reason, $decidedBy);
        if ($decided === null) {
            return false;
        }
        $this->closeCard($decided, $outcome);
        return true;
    }

    /** Whether the run still waits for a decision; false once it was decided, or when it was never asked. */
    public function isPending(int $runId): bool {
        return $this->approvals->pendingForRun($runId) !== null;
    }

    /** Closes approvals nobody answered in time. */
    public function expireDue(): void {
        foreach ($this->approvals->due(new DateTimeImmutable()) as $approval) {
            $decided = $this->approvals->decide($approval->id, ApprovalStatus::Expired, null, null);
            if ($decided !== null) {
                $this->closeCard($decided, t('agent.approval_service.vremya_vyshlo_molchanie_ne_soglasie'));
                $this->continue($decided);
            }
        }
    }

    private function closeCard(Approval $approval, string $outcome): void {
        // spec: brief/operations.md#шаги-и-хранение — the approval step lasts from the card to the decision
        $waited = $approval->createdAt === null ? 0.0 : max(0.0, microtime(true) - (float) $approval->createdAt->format('U.u'));
        $this->events->approvalDecided($approval->runId, $approval->tool, $approval->status->value, $waited);
        if ($approval->cardChatId === null || $approval->cardMessageId === null) {
            return;
        }
        $this->channel->edit(
            $approval->cardChatId,
            $approval->cardMessageId,
            sprintf(t('agent.approval_service.deystvie'), $approval->summary, $outcome),
        );
    }

    /**
     * The grants a run with nobody to ask lacked, as one card: allow these to the area, allow the area everything, or
     * refuse. Sent once per list.
     *
     * spec: brief/scheduler.md#разрешения-задания
     */
    public function askNeeds(string $area, string $title): void {
        $needs = $this->approvals->needs($area);
        $chat = OwnerDirectory::chat($this->ownerChatId);
        if ($needs === null || $needs->cardMessageId !== null) {
            return;
        }
        $lines = array_map(static fn(array $item): string => sprintf('• %s — %s', GrantKeys::label($item['key']), $item['label']), $needs->items);
        $sent = $this->channel->send(new OutgoingMessage(
            $chat,
            sprintf(t('agent.grants.needs_card'), $title, mb_substr(implode("\n", $lines), 0, self::CARD_LIMIT)),
            disableLinkPreview: true,
            buttons: [
                [new Button(t('agent.grants.needs_these'), sprintf('gn:%d:j', $needs->id))],
                [new Button(t('agent.grants.needs_all'), sprintf('gn:%d:a', $needs->id))],
                [new Button(t('agent.approval_service.otkazat'), sprintf('gn:%d:d', $needs->id))],
            ],
        ));
        if ($sent->success && $sent->messageId !== null) {
            $this->approvals->setNeedsCard($needs->id, $chat, $sent->messageId);
        }
    }

    private function decideNeeds(IncomingCallback $callback, int $id, string $choice): void {
        $decided = $this->approvals->closeNeeds($id, $choice === 'd' ? 'denied' : 'granted');
        if ($decided === null) {
            $this->channel->answerCallback($callback->callbackId, t('agent.approval_service.uzhe_resheno'));
            return;
        }
        // the grants of an area are given for that area and apply after outside data too: a job reads outside data by its nature
        if ($choice === 'a') {
            $this->approvals->addGrant($decided->principalId, 'area:' . $decided->area, null, true);
        } elseif ($choice === 'j') {
            foreach ($decided->items as $item) {
                $this->approvals->addGrant($decided->principalId, $decided->area . '|' . $item['key'], null, true, sprintf('%s: %s', $decided->area, $item['label']));
            }
        }
        $outcome = match ($choice) {
            'a' => sprintf(t('agent.grants.needs_all_done'), $decided->area),
            'j' => sprintf(t('agent.grants.needs_these_done'), count($decided->items), $decided->area),
            default => t('agent.approval_service.otkazano_vladeltsem'),
        };
        $this->channel->answerCallback($callback->callbackId, $choice === 'd' ? t('agent.approval_service.otkazano') : t('agent.approval_service.razresheno_i_zapomneno'));
        if ($decided->cardChatId !== null && $decided->cardMessageId !== null) {
            $this->channel->edit($decided->cardChatId, $decided->cardMessageId, $outcome, buttons: []);
        }
        $this->logger->info('Lacking grants decided', ['area' => $decided->area, 'choice' => $choice, 'items' => count($decided->items)]);
    }

    /**
     * "/permissions allow <job N | project N | server имя | tool имя> [outside]" — a grant for an area as a whole, by the
     * owner's own command; "outside" — it applies after outside data too.
     *
     * spec: brief/agent-loop.md#разрешения-навсегда
     */
    public function allow(Principal $owner, string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $outside = strtolower((string) end($parts)) === 'outside';
        if ($outside) {
            array_pop($parts);
        }
        $kind = strtolower($parts[0] ?? '');
        $name = trim(implode(' ', array_slice($parts, 1)));
        $key = match (true) {
            $name === '' => null,
            in_array($kind, ['job', 'project'], true) && ctype_digit($name) => sprintf('area:%s:%d', $kind, (int) $name),
            $kind === 'server' => 'ssh-any:' . mb_strtolower($name),
            $kind === 'tool' && preg_match('~^[a-z0-9_]+$~', $name) === 1 => GrantKeys::any($name),
            default => null,
        };
        if ($key === null) {
            return t('agent.grants.allow_usage');
        }
        $this->approvals->addGrant($owner->id, $key, null, $outside);
        $this->logger->info('Grant saved by command', ['key' => $key, 'outside' => $outside]);
        return sprintf(t('agent.grants.allowed'), GrantKeys::label($key)) . ($outside ? t('agent.grants.saved_outside') : '');
    }

    /**
     * After a run of an area with nobody to ask: an area that goes on gets the card of what it lacked; an area that
     * ended (a one-off job that ran) loses its grants and its list — there is nothing left to allow it.
     *
     * spec: brief/scheduler.md#разрешения-задания
     */
    public function afterRun(bool $ended, string $area, string $title): void {
        if (!$ended) {
            $this->askNeeds($area, $title);
            return;
        }
        $needs = $this->approvals->needs($area);
        if ($needs !== null) {
            $this->approvals->closeNeeds($needs->id, 'denied');
        }
        $this->forgetArea($area);
    }

    /** The grants of a job end with the job. */
    public function forgetArea(string $area): int {
        return $this->approvals->revokeGrants($area . '|') + $this->approvals->revokeGrants('area:' . $area);
    }

    private function continue(Approval $approval): void {
        if ($this->resume !== null) {
            ($this->resume)($approval->runId);
        }
    }
}
