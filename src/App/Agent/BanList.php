<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Agent\Ban;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ApprovalRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * Bans by command: "/deny" shows them, "/deny <area> <what>" sets one, "/deny remove <number>" lifts one. The owner of
 * the agent sets and lifts any; the owner of a project — those of their project, but not one the owner of the agent set.
 *
 * spec: brief/agent-loop.md#запреты
 * В референсах запретов нет: у hermes «никогда» — только встроенный список опасных команд (tools/approval.py), а
 * владелец решает каждый раз; здесь владелец сам пополняет список тем же ключом, что у разрешений.
 */
final class BanList implements CallbackHandler {
    public const PREFIX = 'bn';
    private const SHOWN = 30;

    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly PrincipalRepository $principals,
        private readonly Channel $channel,
        private readonly LoggerInterface $logger,
        private readonly ?ProjectRepository $projects = null,
    ) {}

    public function command(Principal $who, string $argument, string $chatId, ?string $replyTo): void {
        $argument = trim($argument);
        if ($argument === '') {
            [$text, $rows] = $this->view($who);
            $this->channel->send(new OutgoingMessage($chatId, $text, $replyTo, disableLinkPreview: true, buttons: $rows));
            return;
        }
        /** @var list<string> $parts */
        $parts = preg_split('~\s+~u', $argument) ?: [];
        if (strtolower($parts[0]) === 'remove') {
            $this->channel->send(new OutgoingMessage($chatId, $this->remove($who, (int) ltrim($parts[1] ?? '', '#')), $replyTo));
            return;
        }
        $this->channel->send(new OutgoingMessage($chatId, $this->set($who, $parts), $replyTo, disableLinkPreview: true));
    }

    /** @param list<string> $parts the words of the command: an area, then what */
    private function set(Principal $who, array $parts): string {
        $kind = strtolower($parts[0] ?? '');
        $target = $parts[1] ?? '';
        $principalId = $kind === 'participant' ? $this->participant($target) : null;
        $area = match ($kind) {
            'all', 'participant' => '',
            'project' => $this->project($who, $target),
            'job' => ctype_digit($target) ? 'job:' . (int) $target : null,
            default => null,
        };
        $rest = array_slice($parts, $kind === 'all' ? 1 : 2);
        if (($kind === 'participant' && $principalId === null) || $area === null || $rest === []) {
            return t('agent.bans.usage');
        }
        // spec: brief/agent-loop.md#запреты — the owner of a project bans in their project only
        if (!$who->isOwner() && ($kind !== 'project' || $area === '')) {
            return t('agent.bans.project_only');
        }
        $key = self::key($rest);
        if ($key === null) {
            return t('agent.bans.usage');
        }
        $ban = $this->approvals->addBan($principalId, $area, $key, null, $who->id);
        $this->logger->info('Ban saved by command', ['ban' => $ban->id, 'area' => $area, 'participant' => $principalId]);
        return sprintf(t('agent.bans.set'), $ban->id, GrantKeys::label($key), $this->scope($ban));
    }

    /**
     * What a ban covers: "server <name>", "tool <name>", or a key of a grant, "*" — any text.
     *
     * @param list<string> $rest
     */
    private static function key(array $rest): ?string {
        $what = strtolower($rest[0] ?? '');
        $name = trim(implode(' ', array_slice($rest, 1)));
        return match (true) {
            $what === 'server' && $name !== '' => 'ssh-any:' . mb_strtolower($name),
            $what === 'tool' && preg_match('~^[a-z0-9_]+$~', $name) === 1 => GrantKeys::any($name),
            count($rest) >= 1 && $what !== 'server' && $what !== 'tool' => trim(implode(' ', $rest)),
            default => null,
        };
    }

    private function remove(Principal $who, int $id): string {
        $ban = null;
        foreach ($this->approvals->bans() as $one) {
            if ($one->id === $id) {
                $ban = $one;
            }
        }
        if ($ban === null) {
            return sprintf(t('agent.bans.none'), $id);
        }
        if (!$this->mayLift($who, $ban)) {
            return t('agent.bans.not_yours');
        }
        $this->approvals->removeBan($id);
        $this->logger->info('Ban lifted', ['ban' => $id]);
        return sprintf(t('agent.bans.lifted'), $id, GrantKeys::label($ban->key));
    }

    /** The owner of the agent lifts any; the owner of a project — a ban of their project set by someone but the owner of the agent. */
    private function mayLift(Principal $who, Ban $ban): bool {
        if ($who->isOwner()) {
            return true;
        }
        if (!str_starts_with($ban->area, 'project:')) {
            return false;
        }
        $project = $this->projects?->find((int) substr($ban->area, 8));
        if ($project === null || $project->ownerId !== $who->id) {
            return false;
        }
        $setter = $ban->createdBy === null ? null : $this->principals->find($ban->createdBy);
        return $setter === null || !$setter->isOwner();
    }

    /** Whether a participant sees a ban in their list: the owner of the agent all, the owner of a project those of it. */
    private function sees(Principal $who, Ban $ban): bool {
        if ($who->isOwner()) {
            return true;
        }
        $project = str_starts_with($ban->area, 'project:') ? $this->projects?->find((int) substr($ban->area, 8)) : null;
        return $project !== null && $project->ownerId === $who->id;
    }

    /** @return array{string, list<list<Button>>} */
    private function view(Principal $who): array {
        $bans = array_values(array_filter($this->approvals->bans(), fn(Ban $b): bool => $this->sees($who, $b)));
        if ($bans === []) {
            return [t('agent.bans.empty') . "\n\n" . t('agent.bans.usage'), []];
        }
        $lines = [sprintf(t('agent.bans.head'), count($bans)), ''];
        $rows = [];
        foreach (array_slice($bans, 0, self::SHOWN) as $ban) {
            $lines[] = sprintf('#%d · %s · %s', $ban->id, $this->scope($ban), $ban->label ?? GrantKeys::label($ban->key));
            if ($this->mayLift($who, $ban)) {
                $rows[] = [new Button(sprintf(t('agent.bans.btn_lift'), $ban->id), sprintf('%s:x:%d', self::PREFIX, $ban->id))];
            }
        }
        return [implode("\n", $lines) . "\n\n" . t('agent.bans.usage'), $rows];
    }

    /** spec: brief/agent-loop.md#запреты — a ban is lifted from the list by its button */
    public function handleCallback(IncomingCallback $callback): void {
        $who = $this->principals->findByIdentity($callback->channel, $callback->userId);
        if (preg_match('~^bn:x:(\d+)$~', $callback->data, $m) !== 1 || $who === null) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $answer = $this->remove($who, (int) $m[1]);
        $this->channel->answerCallback($callback->callbackId, mb_substr($answer, 0, 190));
        if ($callback->messageId !== null) {
            [$text, $rows] = $this->view($who);
            $this->channel->edit($callback->chatId, $callback->messageId, $text, buttons: $rows);
        }
    }

    private function scope(Ban $ban): string {
        return match (true) {
            $ban->principalId !== null => sprintf(t('agent.bans.scope_participant'), $this->principals->find($ban->principalId)->displayName ?? ('#' . $ban->principalId)),
            str_starts_with($ban->area, 'project:') => sprintf(t('agent.bans.scope_project'), $this->projects?->find((int) substr($ban->area, 8))->name ?? $ban->area),
            str_starts_with($ban->area, 'job:') => sprintf(t('agent.bans.scope_job'), substr($ban->area, 4)),
            default => t('agent.bans.scope_all'),
        };
    }

    private function participant(string $who): ?int {
        $id = ltrim($who, '#');
        if (ctype_digit($id)) {
            return $this->principals->find((int) $id)?->id;
        }
        foreach ($this->principals->all() as $principal) {
            if ($who !== '' && mb_strtolower((string) $principal->displayName) === mb_strtolower($who)) {
                return $principal->id;
            }
        }
        return null;
    }

    /** "project:N" of a project the participant sees, by number or a one-word name; null — none. */
    private function project(Principal $who, string $name): ?string {
        $project = ctype_digit($name) ? $this->projects?->find((int) $name) : $this->projects?->byName($name, Project::scopeOf($who));
        return $project === null || !$project->visibleTo($who) ? null : 'project:' . $project->id;
    }
}
