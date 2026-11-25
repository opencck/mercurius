<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectLevel;
use OpenCCK\Domain\Repository\ProjectLinkRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * A project as a channel for agents. A person is in a project by being in its chat or topic; an agent connected over
 * the network has no chat there, so it enters the project itself: it sees the projects it may work with, subscribes
 * to one to hear what is said in its chats and topics, and may make it its working project — then its tasks run in
 * that project, with the browser, mail, secrets and skills of the project, until it leaves.
 *
 * spec: brief/network.md#проект-как-канал-для-агентов
 */
final class ProjectChannels {
    /** the chats of agents: the place of the working project of an agent is `a2a-<agent>` there */
    private const CHANNEL = A2aServer::CHANNEL;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectLinkRepository $links,
    ) {}

    private static function home(Member $agent): string {
        return 'a2a-' . $agent->id;
    }

    public function levelOf(Project $project, Member $who): ?ProjectLevel {
        return $project->levelOf($who, $this->projects->memberLevel($project->id, $who->id));
    }

    /**
     * The projects the participant may work with: what a list of channels is for a person.
     *
     * @return list<array{id: int, name: string, access: string, subscribed: bool, working: bool}>
     */
    public function available(Member $who): array {
        $working = $this->working($who)?->id;
        $found = [];
        foreach ($this->projects->all(100, Project::scopeOf($who)) as $project) {
            $level = $this->levelOf($project, $who);
            if ($level !== null) {
                $found[] = ['id' => $project->id, 'name' => $project->name, 'access' => $level->label(), 'subscribed' => $this->links->subscribed($project->id, $who->id), 'working' => $project->id === $working];
            }
        }
        return $found;
    }

    /** A project the participant may work with, by its name or number. */
    public function find(Member $who, string $project): ?Project {
        $project = trim($project);
        $found = ctype_digit($project) ? $this->projects->find((int) $project) : $this->projects->byName($project, Project::scopeOf($who));
        return $found !== null && $this->levelOf($found, $who) !== null ? $found : null;
    }

    public function subscribe(Member $who, Project $project): void {
        $this->links->subscribe($project->id, $who->id);
    }

    /** Stops listening; a working project that is left is no longer the working one. */
    public function unsubscribe(Member $who, Project $project): bool {
        if ($this->working($who)?->id === $project->id) {
            $this->leave($who);
        }
        return $this->links->unsubscribe($project->id, $who->id);
    }

    public function subscribed(int $projectId, int $principalId): bool {
        return $this->links->subscribed($projectId, $principalId);
    }

    /** Makes the project the working one of the agent: its next tasks run there. Entering is also listening. */
    public function enter(Member $agent, Project $project): void {
        $this->projects->activate(self::CHANNEL, self::home($agent), $project->id);
        $this->links->subscribe($project->id, $agent->id);
    }

    public function leave(Member $agent): bool {
        if ($this->projects->active(self::CHANNEL, self::home($agent)) === null) {
            return false;
        }
        $this->projects->activate(self::CHANNEL, self::home($agent), null);
        return true;
    }

    /** The working project of the agent, while it may still work with it. */
    public function working(Member $agent): ?Project {
        $project = $this->projects->active(self::CHANNEL, self::home($agent));
        if ($project !== null && $this->levelOf($project, $agent) === null) {
            // the access was taken back: the agent is out of the project
            $this->projects->activate(self::CHANNEL, self::home($agent), null);
            return null;
        }
        return $project;
    }

    /**
     * Puts a new chat of the agent (a task of the network) into its working project, or into the project the task
     * itself names; a task that names a project the agent may not work with gets the reason.
     *
     * @return ?string why the task cannot run in the named project; null — the chat is in its place
     */
    public function place(Member $agent, string $chatId, ?string $named = null): ?string {
        if ($named !== null && trim($named) !== '') {
            $project = $this->find($agent, $named);
            if ($project === null) {
                return sprintf(t('peer.project_channels.no_project'), trim($named));
            }
        } else {
            $project = $this->working($agent);
        }
        if ($project !== null) {
            $this->projects->activate(self::CHANNEL, $chatId, $project->id);
        }
        return null;
    }
}
