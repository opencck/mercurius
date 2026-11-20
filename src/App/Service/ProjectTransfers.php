<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use DomainException;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTransfer;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Hands a project over to another participant, with the consent of the one who takes it: an offer waits, the taker
 * accepts it by a button or by words, and only then the project, its folder and its jobs become theirs. The former
 * owner keeps the full access until the new one takes it back.
 *
 * spec: brief/participants.md#доступ-к-проектам-и-передача
 */
final class ProjectTransfers implements CallbackHandler {
    public const PREFIX = 'pt';

    /**
     * @param (Closure(int, int, int): void)|null $move moves the folder of a project from the sandbox of one participant
     *        to that of another: from, to, project; throws when it could not; null — no sandbox, nothing to move
     * @param (Closure(Principal, string, list<list<Button>>): void)|null $tell a message to a participant in their private
     *        place, with buttons where the place has them; null — nobody is told
     */
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly PrincipalRepository $principals,
        private readonly ?Closure $move = null,
        private readonly ?Closure $tell = null,
        private readonly ?Channel $channel = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** The participant an owner id stands for: null is the owner of the agent. */
    private function holder(?int $id): ?Principal {
        return $id === null ? $this->principals->owner() : $this->principals->find($id);
    }

    private static function name(?Principal $who): string {
        return $who === null ? '—' : sprintf('%s (#%d)', $who->displayName ?? ($who->isOwner() ? t('tool.project_edit.agent_owner') : '—'), $who->id);
    }

    /**
     * Offers the project to a participant and tells them.
     *
     * @throws DomainException with the reason said to whoever asked
     */
    public function offer(Project $project, Principal $to): ProjectTransfer {
        $from = $this->holder($project->ownerId);
        if ($from !== null && $from->id === $to->id) {
            throw new DomainException(sprintf(t('tool.project_edit.share_owner'), self::name($to)));
        }
        $transfer = $this->projects->offer($project->id, $project->ownerId, $to->isOwner() ? null : $to->id);
        if ($this->tell !== null) {
            ($this->tell)($to, sprintf(t('service.project_transfers.offer'), self::name($from), $project->name, $project->goal === '' ? '' : ' — ' . mb_substr($project->goal, 0, 200), self::name($from)), [[
                new Button(t('service.project_transfers.accept'), sprintf('%s:%d:a', self::PREFIX, $transfer->id)),
                new Button(t('service.project_transfers.decline'), sprintf('%s:%d:d', self::PREFIX, $transfer->id)),
            ]]);
        }
        return $transfer;
    }

    /**
     * The offers of projects that wait for this participant, with their projects.
     *
     * @return list<array{ProjectTransfer, Project}>
     */
    public function waitingFor(Principal $who): array {
        $found = [];
        foreach ($this->projects->offersTo($who->isOwner() ? null : $who->id) as $transfer) {
            $project = $this->projects->find($transfer->projectId);
            if ($project !== null) {
                $found[] = [$transfer, $project];
            }
        }
        return $found;
    }

    /**
     * The taker says yes or no. On yes the project takes the place of an empty one of that name, its folder moves to
     * the taker's sandbox, and it becomes theirs.
     *
     * @return string what happened, for whoever decided
     * @throws DomainException with the reason; the offer goes on waiting
     */
    public function decide(int $transferId, Principal $by, bool $accept): string {
        $transfer = $this->projects->transfer($transferId);
        $project = $transfer === null ? null : $this->projects->find($transfer->projectId);
        if ($transfer === null || $project === null || $transfer->status !== ProjectTransfer::PENDING) {
            throw new DomainException(t('service.project_transfers.settled'));
        }
        if ($transfer->toId === null ? !$by->isOwner() : $transfer->toId !== $by->id) {
            throw new DomainException(t('service.project_transfers.not_yours'));
        }
        $from = $this->holder($transfer->fromId);
        if (!$accept) {
            $this->projects->settle($transfer->id, ProjectTransfer::DECLINED);
            $this->notify($from, sprintf(t('service.project_transfers.declined_notice'), self::name($by), $project->name));
            return sprintf(t('service.project_transfers.declined'), $project->name);
        }
        if ($project->ownerId !== $transfer->fromId) {
            $this->projects->settle($transfer->id, ProjectTransfer::CANCELLED);
            throw new DomainException(t('service.project_transfers.settled'));
        }
        $same = $this->projects->byName($project->name, $transfer->toId);
        $same = $same !== null && $same->id !== $project->id && $same->ownerId === $transfer->toId ? $same : null;
        if ($same !== null && !$this->projects->isEmpty($same->id)) {
            throw new DomainException(sprintf(t('service.project_transfers.clash'), $project->name));
        }
        if ($this->move !== null && $from !== null && $from->id !== $by->id) {
            try {
                ($this->move)($from->id, $by->id, $project->id);
            } catch (Throwable $e) {
                $this->logger->warning('The folder of a project was not moved', ['project' => $project->id, 'error' => $e::class]);
                throw new DomainException(sprintf(t('service.project_transfers.move_failed'), mb_substr($e->getMessage(), 0, 200)));
            }
        }
        if ($same !== null) {
            $this->projects->absorb($project->id, $same->id);
        }
        $this->projects->setOwner($project->id, $transfer->toId);
        if ($transfer->fromId !== null) {
            // the former owner goes on working with the project until the new one decides otherwise
            $this->projects->share($project->id, $transfer->fromId, 'full', $transfer->toId);
        }
        $this->projects->settle($transfer->id, ProjectTransfer::ACCEPTED);
        $this->logger->notice('A project passed to a new owner', ['project' => $project->id, 'from' => $transfer->fromId, 'to' => $transfer->toId]);
        $this->notify($from, sprintf(t('service.project_transfers.accepted_notice'), self::name($by), $project->name));
        return sprintf(t('service.project_transfers.accepted'), $project->name, self::name($from));
    }

    private function notify(?Principal $who, string $text): void {
        if ($who === null || $this->tell === null) {
            return;
        }
        try {
            ($this->tell)($who, $text, []);
        } catch (Throwable $e) {
            $this->logger->warning('A notice about a project was not delivered', ['principal' => $who->id, 'error' => $e::class]);
        }
    }

    public function handleCallback(IncomingCallback $callback): void {
        if ($this->channel === null) {
            return;
        }
        if (preg_match('/^pt:(\d+):([ad])$/', $callback->data, $m) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        // whoever pressed is found by their account in the channel: the offer is theirs alone
        $by = $this->principals->findByIdentity($this->channel->name(), $callback->userId);
        try {
            $text = $by === null ? t('service.project_transfers.not_yours') : $this->decide((int) $m[1], $by, $m[2] === 'a');
        } catch (DomainException $e) {
            $this->channel->answerCallback($callback->callbackId, mb_substr($e->getMessage(), 0, 190), true);
            return;
        }
        $this->channel->answerCallback($callback->callbackId);
        if ($by !== null && $callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $text);
        }
    }
}
