<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;

use function Mercurius\I18n\t;

/**
 * Where a message, a file or other content goes: this chat (and its topic), or the private chat of a participant named
 * by "owner", an #id or a name. To this chat or to the owner it is an answer; to anyone else it waits for the owner's
 * card and can be granted per person.
 *
 * spec: brief/channels.md#личные-сообщения-участникам
 */
final class Recipients {
    public function __construct(
        private readonly PrincipalRepository $principals,
        /** the channel the people are written to in: their identities there are their chats */
        private readonly string $channel,
    ) {}

    /** The active person the name points to; the text — why there is none. */
    public function find(string $to): Principal|string {
        return self::among($this->principals, $to, false);
    }

    /**
     * The active participant the name points to, a person or an agent: "owner", an #id or a name.
     *
     * spec: brief/participants.md#доступ-к-проектам-и-передача — access to a project is given to people and to agents alike
     */
    public static function participant(PrincipalRepository $principals, string $to): Principal|string {
        return self::among($principals, $to, true);
    }

    private static function among(PrincipalRepository $principals, string $to, bool $agents): Principal|string {
        $to = trim($to);
        $people = array_values(array_filter(
            $principals->all(),
            static fn(Principal $p): bool => ($agents || $p->kind === PrincipalKind::Human) && $p->status === PrincipalStatus::Active,
        ));
        $key = mb_strtolower(ltrim($to, '@#'));
        if (in_array($key, ['owner', 'владелец', 'владельцу'], true)) {
            $owner = array_values(array_filter($people, static fn(Principal $p): bool => $p->role === Role::owner()));
            return $owner[0] ?? t('tool.send_message.no_owner');
        }
        if (ctype_digit($key)) {
            foreach ($people as $p) {
                if ($p->id === (int) $key) {
                    return $p;
                }
            }
        }
        $exact = array_values(array_filter($people, static fn(Principal $p): bool => mb_strtolower($p->displayName ?? '') === $key));
        $found = $exact !== [] ? $exact : array_values(array_filter(
            $people,
            static fn(Principal $p): bool => $key !== '' && str_contains(mb_strtolower($p->displayName ?? ''), $key),
        ));
        if (count($found) === 1) {
            return $found[0];
        }
        $names = implode(', ', array_map(static fn(Principal $p): string => sprintf('%s (#%d)', $p->displayName ?? '—', $p->id), $found === [] ? $people : $found));
        return sprintf(t($found === [] ? 'tool.send_message.unknown' : 'tool.send_message.ambiguous'), $to, $names);
    }

    /**
     * The chat and topic: this one when nobody is named, else the person's private chat.
     *
     * @return array{string, ?string}|string chat and topic, or why there is none
     */
    public function where(?string $to, ToolContext $context, ConversationRepository $conversations): array|string {
        if ($to === null || trim($to) === '') {
            $meta = $conversations->meta($context->conversationId);
            return $meta === null ? t('tool.send_file.no_chat') : [$meta->chatId, $meta->threadId === '' ? null : $meta->threadId];
        }
        $person = $this->find($to);
        if (is_string($person)) {
            return $person;
        }
        $chat = $this->principals->externalId($person->id, $this->channel);
        return $chat === null ? sprintf(t('tool.send_message.no_chat'), $person->displayName ?? '#' . $person->id) : [$chat, null];
    }

    public function danger(?string $to): DangerClass {
        if ($to === null || trim($to) === '') {
            return DangerClass::Write;
        }
        $person = $this->find($to);
        return $person instanceof Principal && $person->role === Role::owner() ? DangerClass::Write : DangerClass::Irreversible;
    }

    public function grantKey(?string $to): ?string {
        if ($to === null || trim($to) === '') {
            return null;
        }
        $person = $this->find($to);
        return $person instanceof Principal && $person->role !== Role::owner() ? 'message:' . $person->id : null;
    }

    public function name(?string $to): string {
        if ($to === null || trim($to) === '') {
            return t('tool.send_content.this_chat');
        }
        $person = $this->find($to);
        return $person instanceof Principal ? ($person->displayName ?? '#' . $person->id) : $to;
    }
}
