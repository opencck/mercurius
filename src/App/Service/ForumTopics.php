<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\ForumChannel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\GroupRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * In a forum every project has a topic of its own. A project started in the forum gets a new topic (or the topic it was
 * started in, when that one has no project yet); a finished project closes its topic and a return reopens it; a topic
 * someone opened becomes a draft project. Topic changes are writes: they go without a card and are logged.
 *
 * spec: brief/channels.md#форум-темы--проекты
 * ref: references/hermes-agent/plugins/platforms/telegram/adapter.py@e96221fa:L2297-L2330 (_create_dm_topic) — a topic
 *      that cannot be created (not a forum, no rights) is logged and the work goes on without it; hermes maps topics to
 *      sessions by name in its config, we map them to projects in the base
 */
final class ForumTopics {
    public function __construct(
        private readonly Channel $channel,
        private readonly GroupRepository $groups,
        private readonly ProjectRepository $projects,
        private readonly LoggerInterface $logger,
    ) {}

    /** After a project was created in a conversation; a line for the agent, '' when the chat is not a forum. */
    public function created(Project $project, ConversationMeta $meta): string {
        // spec: brief/channels.md#telegram-группы-каналы-и-темы — topics are an optional part of a channel
        if (!$this->channel instanceof ForumChannel || !$this->forum($meta->channel, $meta->chatId)) {
            return '';
        }
        if ($meta->threadId !== '' && $this->projects->active($meta->channel, $meta->chatId, $meta->threadId) === null) {
            $this->channel->editTopic($meta->chatId, $meta->threadId, $project->name);
            $this->projects->activate($meta->channel, $meta->chatId, $project->id, $meta->threadId);
            $this->logger->notice('Forum topic linked to a project', ['chat' => $meta->chatId, 'thread' => $meta->threadId, 'project' => $project->id]);
            return sprintf(t('service.forum_topics.eta_tema_teper_proekt'), $project->name);
        }
        $thread = $this->channel->createTopic($meta->chatId, $project->name);
        if ($thread === null) {
            return t('service.forum_topics.temu_dlya_proekta_sozdat_ne');
        }
        $this->projects->activate($meta->channel, $meta->chatId, $project->id, $thread);
        $this->channel->send(new OutgoingMessage($meta->chatId, sprintf(t('service.forum_topics.tema_proekta_zdes_ego_beseda'), $project->name), threadId: $thread));
        $this->logger->notice('Forum topic created for a project', ['chat' => $meta->chatId, 'thread' => $thread, 'project' => $project->id]);
        return sprintf(t('service.forum_topics.sozdal_temu_dlya_proekta'), $project->name);
    }

    /**
     * A project got a new name: its topics are renamed too. Best effort, as every topic call.
     *
     * spec: brief/channels.md#форум-темы--проекты
     */
    public function renamed(int $projectId, string $name): void {
        if (!$this->channel instanceof ForumChannel) {
            return;
        }
        foreach ($this->projects->topicsOf($projectId) as $topic) {
            $done = $this->channel->editTopic($topic['chatId'], $topic['threadId'], $name);
            $this->logger->notice('Forum topic renamed with its project', ['chat' => $topic['chatId'], 'thread' => $topic['threadId'], 'project' => $projectId, 'done' => $done]);
        }
    }

    /** A project's status changes from $before->status to $status: done closes its topics, a return reopens them. */
    public function statusChanged(Project $before, string $status): void {
        $closing = $status === Project::DONE && $before->status !== Project::DONE;
        $reopening = $before->status === Project::DONE && $status !== Project::DONE;
        if ((!$closing && !$reopening) || !$this->channel instanceof ForumChannel) {
            return;
        }
        foreach ($this->projects->topicsOf($before->id) as $topic) {
            $done = $closing ? $this->channel->closeTopic($topic['chatId'], $topic['threadId']) : $this->channel->reopenTopic($topic['chatId'], $topic['threadId']);
            $this->logger->notice($closing ? 'Forum topic closed' : 'Forum topic reopened', ['chat' => $topic['chatId'], 'thread' => $topic['threadId'], 'project' => $before->id, 'done' => $done]);
        }
    }

    /**
     * Someone opened a topic in an approved forum: it becomes a draft project with the topic's name, and the topic is
     * told so. Null when the topic already has a project or the chat is not such a forum.
     *
     * @param int|null $ownerId null — the agent's owner; otherwise the participant who opened it
     */
    public function opened(string $channel, string $chatId, string $threadId, string $name, ?int $ownerId): ?Project {
        if (!$this->forum($channel, $chatId) || $this->projects->active($channel, $chatId, $threadId) !== null) {
            return null;
        }
        $name = mb_substr(trim($name), 0, Project::NAME_MAX);
        try {
            $project = $this->projects->create($name === '' ? t('service.forum_topics.tema') . $threadId : $name, '', $ownerId);
        } catch (DomainException) {
            // the name is taken: the topic joins the existing project of this owner
            $project = $this->projects->byName($name, $ownerId);
            if ($project === null) {
                return null;
            }
        }
        $this->projects->activate($channel, $chatId, $project->id, $threadId);
        $this->channel->send(new OutgoingMessage($chatId, sprintf(t('service.forum_topics.svyazal_temu_s_proektom_tsel'), $project->name), threadId: $threadId));
        $this->logger->notice('Forum topic became a project', ['chat' => $chatId, 'thread' => $threadId, 'project' => $project->id]);
        return $project;
    }

    private function forum(string $channel, string $chatId): bool {
        $group = $this->groups->find($channel, $chatId);
        return $group !== null && $group->forum && $group->status === Group::APPROVED;
    }
}
