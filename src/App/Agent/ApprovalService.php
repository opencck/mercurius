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
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\App\Agent\Trace\NullEvents;
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
 * spec: BRIEF.md#подтверждения-и-запреты
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221f:L3844-L3861 (_send_exec_approval_prompt),
 *      L4347-L4389 (_handle_exec_approval_callback) — short id in the button, decide first then edit the card,
 *      "already decided" for a second press; the approval lives in the database here, not in process memory
 */
final class ApprovalService implements ApprovalGate, CallbackHandler {
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
        private readonly string $ownerChatId,
        private readonly int $timeoutSeconds,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly AgentEvents $events = new NullEvents(),
    ) {}

    /** @param Closure(int): void $resume */
    public function onDecision(Closure $resume): void {
        $this->resume = $resume;
    }

    public function request(Run $run, ValidatedCall $call, Principal $principal): void {
        $args = $call->args ?? [];
        $key = $call->tool instanceof GrantKeyed ? $call->tool->grantKey($args) : null;
        $label = SecretMasker::mask($call->tool instanceof GrantKeyed ? $call->tool->describe($args) : $call->call->name);
        $shown = SecretMasker::mask(json_encode(SecretMasker::maskArgs($args), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $approval = $this->approvals->create(
            $run->id,
            $call->call->id,
            $principal->id,
            $call->call->name,
            $label . "\n" . $call->call->name . ' ' . $shown,
            $key,
            new DateTimeImmutable(sprintf('+%d seconds', $this->timeoutSeconds)),
        );

        $inFile = mb_strlen($shown) > self::CARD_LIMIT;
        // ref: references/hermes-agent/gateway/platforms/base.py@e96221fa:L2744-L2763 (send_exec_approval) — an action
        //      the owner could not read in full can only be refused
        $unreadable = $inFile && !$this->channel->sendFile($this->ownerChatId, sprintf('approval-%d.json', $approval->id), $shown, t('agent.approval_service.deystvie_tselikom'))->success;
        $text = sprintf(
            t('agent.approval_service.podtverdite_deystvie_instrument_argument'),
            $label,
            $call->call->name,
            $unreadable ? t('agent.approval_service.pokazat_tselikom_ne_udalos_podtverdit') : ($inFile ? t('agent.approval_service.tselikom_v_fayle_vyshe') : $shown),
            $principal->displayName ?? (t('agent.approval_service.uchastnik') . $principal->id),
            (new DateTimeImmutable(sprintf('+%d seconds', $this->timeoutSeconds)))->setTimezone(new \DateTimeZone('UTC'))->format('H:i'),
        );
        $row = [];
        if (!$unreadable) {
            $row[] = new Button(t('agent.approval_service.razreshit'), sprintf('ap:%d:a', $approval->id));
            if ($key !== null) {
                $row[] = new Button(t('agent.approval_service.razreshat_vsegda'), sprintf('ap:%d:g', $approval->id));
            }
        }
        $row[] = new Button(t('agent.approval_service.otkazat'), sprintf('ap:%d:d', $approval->id));
        $card = new OutgoingMessage($this->ownerChatId, $text, buttons: [$row]);
        $sent = $this->channel->send($card);
        // The run streams into the same chat: a short flood is waited out before the action is refused.
        if (!$sent->success && $sent->errorKind === \Mercurius\Messenger\SendErrorKind::RateLimited && $sent->retryAfter !== null && $sent->retryAfter <= self::FLOOD_WAIT) {
            \Amp\delay((float) $sent->retryAfter);
            $sent = $this->channel->send($card);
        }
        if ($sent->success && $sent->messageId !== null) {
            $this->approvals->setCard($approval->id, $this->ownerChatId, $sent->messageId);
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
    public function showGrants(string $chatId, ?string $replyTo): bool {
        $grants = $this->approvals->grants();
        if ($grants === []) {
            return false;
        }
        $lines = [];
        $rows = [];
        foreach ($grants as $grant) {
            $name = $this->principals->find($grant->principalId)->displayName ?? (t('agent.approval_service.uchastnik') . $grant->principalId);
            $lines[] = sprintf('%d. %s — %s', $grant->id, $grant->key, $name);
            $rows[] = [new Button(sprintf(t('agent.approval_service.otozvat'), $grant->id), sprintf('pg:%d:x', $grant->id))];
        }
        $this->channel->send(new OutgoingMessage($chatId, t('agent.approval_service.sohranennye_razresheniya') . implode("\n", $lines), $replyTo, buttons: $rows));
        return true;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^pg:(\d+):x$/', $callback->data, $grant) === 1) {
            $revoked = $this->approvals->revokeGrant((int) $grant[1]);
            $this->channel->answerCallback($callback->callbackId, $revoked === null ? t('agent.approval_service.uzhe_otozvano') : t('agent.approval_service.otozvano'));
            if ($revoked !== null) {
                $this->logger->info('Grant revoked', ['grant' => $revoked->id, 'key' => $revoked->key]);
            }
            return;
        }
        if (preg_match('/^ap:(\d+):([agd])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $id = (int) $match[1];
        $approval = $this->approvals->find($id);
        $decided = $approval === null ? null : $this->approvals->decide(
            $id,
            $match[2] === 'd' ? ApprovalStatus::Denied : ApprovalStatus::Approved,
            $match[2] === 'd' ? t('agent.approval_service.otkazano_vladeltsem') : null,
            $this->principals->findByIdentity($callback->channel, $callback->userId)?->id,
        );
        if ($decided === null) {
            $this->channel->answerCallback($callback->callbackId, t('agent.approval_service.uzhe_resheno'));
            return;
        }
        if ($match[2] === 'g' && $decided->grantKey !== null) {
            $this->approvals->addGrant($decided->principalId, $decided->grantKey);
        }
        $this->channel->answerCallback($callback->callbackId, match ($match[2]) {
            'a' => t('agent.approval_service.razresheno'),
            'g' => t('agent.approval_service.razresheno_i_zapomneno'),
            default => t('agent.approval_service.otkazano'),
        });
        $this->closeCard($decided, match ($match[2]) {
            'a' => t('agent.approval_service.razresheno_vladeltsem'),
            'g' => sprintf(t('agent.approval_service.razresheno_vladeltsem_i_zapomneno'), $decided->grantKey ?? t('agent.approval_service.bez_klyucha')),
            default => t('agent.approval_service.otkazano_vladeltsem'),
        });
        $this->continue($decided);
    }

    /**
     * Denies what the run waits for without resuming it: the owner wrote instead of pressing (their words are the
     * reason), stopped the run, or the run failed. The card gets the outcome.
     */
    public function denyPending(int $runId, string $reason, string $outcome): bool {
        $pending = $this->approvals->pendingForRun($runId);
        $decided = $pending === null ? null : $this->approvals->decide($pending->id, ApprovalStatus::Denied, $reason, null);
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
        $this->events->approvalDecided($approval->runId, $approval->tool, $approval->status->value, 0.0);
        if ($approval->cardChatId === null || $approval->cardMessageId === null) {
            return;
        }
        $this->channel->edit(
            $approval->cardChatId,
            $approval->cardMessageId,
            sprintf(t('agent.approval_service.deystvie'), $approval->summary, $outcome),
        );
    }

    private function continue(Approval $approval): void {
        if ($this->resume !== null) {
            ($this->resume)($approval->runId);
        }
    }
}
