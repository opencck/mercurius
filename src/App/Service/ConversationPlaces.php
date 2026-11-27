<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * The context of a private conversation: a member switches it by /context or in words, /new opens a conversation
 * without it. The right is checked when switching and again at each run: a context the member lost is taken off and
 * the run goes on in their own.
 *
 * spec: brief/participants.md#контекст-беседы-в-личном-чате
 */
final class ConversationPlaces {
    /** how many projects a choice lists */
    private const CHOICES = 50;

    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly ProjectRepository $projects,
    ) {}

    /** Only a private chat has a context of its conversation: a group and a topic take the project of the chat. */
    public static function isPrivate(string $chatId, string $threadId = ''): bool {
        return $threadId === '' && !str_starts_with($chatId, '-');
    }

    /**
     * Switches the open conversation of a private chat: personal, global or a project by name or number.
     *
     * @return array{bool, string} whether it switched, and the answer
     */
    public function switchTo(Member $who, string $channel, string $chatId, string $target): array {
        if (!self::isPrivate($chatId)) {
            return [false, t('place.private_only')];
        }
        $target = trim($target);
        $place = match (mb_strtolower($target)) {
            '' => null,
            'personal', 'личный', 'own', 'mine' => ConversationPlace::personal(),
            'global', 'owners', 'общий', 'глобальный' => ConversationPlace::owners(),
            default => $this->projectNamed($who, $target),
        };
        if ($place === null) {
            $names = array_map(static fn(Project $p): string => $p->name, $this->choices($who));
            return [false, $target === '' ? t('place.usage') : sprintf(t('place.no_project'), $target, $names === [] ? '—' : implode(', ', $names))];
        }
        if ($place->kind === ConversationPlace::OWNERS && !$who->isOwner()) {
            return [false, t('place.owners_only')];
        }
        $id = $this->conversations->openConversation($who->id, $channel, $chatId);
        $this->conversations->setContext($id, $place->context());
        // the binding of a private chat by /project would outlive /new: the conversation's context takes its place
        $this->projects->activate($channel, $chatId, null);
        return [true, sprintf(t('place.switched'), $place->title())];
    }

    /**
     * The place of a conversation for a run of this member, and a notice when the context they chose is lost: it is
     * taken off, the run goes on in their own.
     *
     * @return array{ConversationPlace, ?string}
     */
    public function current(ConversationMeta $meta, Member $who): array {
        if (!self::isPrivate($meta->chatId, $meta->threadId) || $meta->context === null) {
            return [ConversationPlace::personal(), null];
        }
        $place = $this->placeOf($meta->context, $who);
        if ($place !== null) {
            return [$place, null];
        }
        $this->conversations->setContext($meta->id, null);
        return [ConversationPlace::personal(), $meta->context === ConversationPlace::OWNERS ? t('place.lost_owners') : t('place.lost_project')];
    }

    /** The place of the open conversation of a chat, for /context, /ls and the tool. */
    public function of(Member $who, string $channel, string $chatId, string $threadId = ''): ConversationPlace {
        if (!self::isPrivate($chatId, $threadId)) {
            $project = $this->projects->active($channel, $chatId, $threadId);
            return $project === null ? ConversationPlace::personal() : ConversationPlace::project($project);
        }
        $meta = $this->conversations->meta($this->conversations->openConversation($who->id, $channel, $chatId));
        return $meta === null ? ConversationPlace::personal() : $this->current($meta, $who)[0];
    }

    /** @return list<Project> the projects this member may switch to */
    public function choices(Member $who): array {
        return array_values(array_filter(
            $this->projects->all(self::CHOICES, Project::scopeOf($who)),
            fn(Project $p): bool => $p->levelOf($who, $this->projects->memberLevel($p->id, $who->id)) !== null,
        ));
    }

    /** The place a kept context stands for, if the member still has it. */
    private function placeOf(string $context, Member $who): ?ConversationPlace {
        if ($context === ConversationPlace::OWNERS) {
            return $who->isOwner() ? ConversationPlace::owners() : null;
        }
        if (preg_match('/^project:(\d+)$/', $context, $m) !== 1) {
            return null;
        }
        $project = $this->projects->find((int) $m[1]);
        return $project !== null && $project->levelOf($who, $this->projects->memberLevel($project->id, $who->id)) !== null
            ? ConversationPlace::project($project)
            : null;
    }

    private function projectNamed(Member $who, string $target): ?ConversationPlace {
        foreach ($this->choices($who) as $project) {
            if ((ctype_digit($target) && $project->id === (int) $target) || mb_strtolower($project->name) === mb_strtolower($target)) {
                return ConversationPlace::project($project);
            }
        }
        return null;
    }
}
