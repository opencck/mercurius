<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Closure;
use Mercurius\Llm\Role as MessageRole;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * What is said in the chats and topics of a project reaches the agents that work with the project, as it reaches the
 * people there: a person reads the chat in Telegram, an agent connected over WebSocket gets a notice "ProjectEvent"
 * when a run in such a place ends — who asked, what, and what was answered.
 *
 * spec: brief/network.md#события-проекта-для-агентов
 */
final class ProjectEvents {
    public const METHOD = 'ProjectEvent';
    /** the longest text and answer an event carries */
    public const TEXT_MAX = 4000;

    /** @param Closure(int, string, array<string, mixed>): bool $push sends a notice to an agent over its live connections */
    public function __construct(
        private readonly RunRepository $runs,
        private readonly ConversationRepository $conversations,
        private readonly TranscriptRepository $transcript,
        private readonly ProjectRepository $projects,
        private readonly PrincipalRepository $principals,
        private readonly Closure $push,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** A run ended: when its place belongs to a project, the agents of the project learn what was said. */
    public function finished(int $runId): void {
        try {
            $run = $this->runs->find($runId);
            $meta = $run === null || $run->parentRunId !== null ? null : $this->conversations->meta($run->conversationId);
            $project = $meta === null ? null : $this->projects->active($meta->channel, $meta->chatId, $meta->threadId);
            if ($run === null || $meta === null || $project === null) {
                return;
            }
            $agents = $this->agentsOf($project->id, $project->ownerId, $run->principalId);
            if ($agents === []) {
                return;
            }
            $asked = '';
            $answered = '';
            foreach ($this->transcript->runMessages($run->id) as $message) {
                if ($message->role === MessageRole::User && $asked === '') {
                    $asked = $message->content;
                }
                if ($message->role === MessageRole::Assistant && $message->toolCalls === [] && trim($message->content) !== '') {
                    $answered = $message->content;
                }
            }
            $name = $this->principals->find($run->principalId)?->displayName;
            $params = [
                'project' => ['id' => $project->id, 'name' => $project->name],
                'place' => ['channel' => $meta->channel, 'chat' => $meta->chatId, 'thread' => $meta->threadId],
                'run' => $run->id,
                'from' => sprintf('%s (#%d)', $name ?? '—', $run->principalId),
                'text' => self::cut($asked),
                'answer' => self::cut($answered),
                'status' => $run->status->value,
                'at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            foreach ($agents as $agent) {
                ($this->push)($agent->id, self::METHOD, $params);
            }
        } catch (Throwable $e) {
            $this->logger->warning('An event of a project was not sent', ['run' => $runId, 'error' => $e::class]);
        }
    }

    /**
     * The agents that work with a project, but for whoever wrote: its members and, when an agent owns it, the owner.
     *
     * @return list<Principal>
     */
    private function agentsOf(int $projectId, ?int $ownerId, int $author): array {
        $ids = array_map(static fn(array $m): int => $m['principalId'], $this->projects->members($projectId));
        if ($ownerId !== null) {
            $ids[] = $ownerId;
        }
        $found = [];
        foreach (array_unique($ids) as $id) {
            $who = $id === $author ? null : $this->principals->find($id);
            if ($who !== null && $who->kind === PrincipalKind::Agent && $who->status === PrincipalStatus::Active) {
                $found[] = $who;
            }
        }
        return $found;
    }

    /** A member agent sees what a member person sees in the chat, without the secrets the mask knows. */
    private static function cut(string $text): string {
        $text = SecretMasker::mask(trim($text));
        return mb_strlen($text) > self::TEXT_MAX ? mb_substr($text, 0, self::TEXT_MAX - 1) . '…' : $text;
    }
}
