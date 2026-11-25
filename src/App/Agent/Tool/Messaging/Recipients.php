<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Messaging;

use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\MemberRepository;

use function Mercurius\I18n\t;

/**
 * Where a message, a file or other content goes: this chat (and its topic), the private chat of a participant named
 * by "owner", an #id or a name, or a place — a group the owner approved, or the chat or topic of a project. To this
 * chat or to the owner it is an answer; to anyone else and to a place it waits for the owner's card and can be granted
 * per person or per place.
 *
 * spec: brief/channels.md#личные-сообщения-участникам
 * spec: brief/channels.md#сообщение-в-группу-или-тему-по-просьбе
 */
final class Recipients {
    /** @var (\Closure(\OpenCCK\Domain\Agent\ConversationMeta): (array{string, ?string}|null))|null */
    private ?\Closure $home = null;

    public function __construct(
        private readonly MemberRepository $principals,
        /** the channel the people are written to in: their identities there are their chats */
        private readonly string $channel,
    ) {}

    /**
     * "Here" of a conversation that is not a chat of the messenger (a run of a job): the chat and topic its report
     * goes to; null from the closure — the conversation's own chat.
     *
     * spec: brief/scheduler.md#задания-проекта
     *
     * @param \Closure(\OpenCCK\Domain\Agent\ConversationMeta): (array{string, ?string}|null) $home
     */
    public function useHome(\Closure $home): void {
        $this->home = $home;
    }

    /** @var (\Closure(): list<array{chat: string, thread: ?string, label: string, names: list<string>}>)|null */
    private ?\Closure $places = null;

    /**
     * The places a message may go to besides people: asked at each call, so a group approved a minute ago is there.
     *
     * spec: brief/channels.md#сообщение-в-группу-или-тему-по-просьбе
     *
     * @param \Closure(): list<array{chat: string, thread: ?string, label: string, names: list<string>}> $places
     */
    public function usePlaces(\Closure $places): void {
        $this->places = $places;
    }

    /** @var (\Closure(string, ?string, string, Member): void)|null */
    private ?\Closure $recorder = null;

    /**
     * What is sent to a place is written into the conversation of that place, so the agent there knows what it said.
     *
     * spec: brief/channels.md#сообщение-в-группу-или-тему-по-просьбе
     *
     * @param \Closure(string, ?string, string, Member): void $recorder chat, topic, what was sent, who asked
     */
    public function useRecorder(\Closure $recorder): void {
        $this->recorder = $recorder;
    }

    /** Records what went to the place the name points to; a person or this chat is not a place — nothing is written. */
    public function sent(?string $to, string $what, Member $by): void {
        if ($this->recorder === null || $to === null || trim($to) === '' || $this->find($to) instanceof Member) {
            return;
        }
        $place = $this->place($to);
        if (is_array($place)) {
            ($this->recorder)($place['chat'], $place['thread'], $what, $by);
        }
    }

    /**
     * The labels of the places a message may go to.
     *
     * @return list<string>
     */
    public function places(): array {
        return $this->places === null ? [] : array_values(array_unique(array_map(static fn(array $p): string => $p['label'], ($this->places)())));
    }

    /**
     * The place the name points to: by its label or one of its names, exact first, then a part.
     *
     * @return array{chat: string, thread: ?string, label: string}|string|null the place; why it is not clear which; null — no such place
     */
    public function place(?string $to): array|string|null {
        $key = mb_strtolower(trim((string) preg_replace('~^\s*(?:group|группа|группу|project|проект|тема|тему|topic)\s*[:\s]\s*~iu', '', $to ?? ''), " \t\n\r\0\x0B@#«»\"'"));
        if ($this->places === null || $key === '') {
            return null;
        }
        $all = ($this->places)();
        $names = static fn(string $label, array $more): array => array_map(mb_strtolower(...), array_values([$label, ...array_filter($more, is_string(...))]));
        $found = array_values(array_filter($all, static fn(array $p): bool => in_array($key, $names($p['label'], $p['names']), true)));
        if ($found === []) {
            $found = array_values(array_filter($all, static fn(array $p): bool => array_filter($names($p['label'], $p['names']), static fn(string $n): bool => str_contains($n, $key)) !== []));
        }
        // one place under several names is one place
        $unique = [];
        foreach ($found as $p) {
            $unique[$p['chat'] . ':' . ($p['thread'] ?? '')] ??= $p;
        }
        if ($unique === []) {
            return null;
        }
        if (count($unique) > 1) {
            return sprintf(t('tool.send_message.place_ambiguous'), $to ?? '', implode('; ', array_map(static fn(array $p): string => $p['label'], array_values($unique))));
        }
        $p = array_values($unique)[0];
        return ['chat' => $p['chat'], 'thread' => $p['thread'], 'label' => $p['label']];
    }

    /** The active person the name points to; the text — why there is none. */
    public function find(string $to): Member|string {
        return self::among($this->principals, $to, false);
    }

    /**
     * The active participant the name points to, a person or an agent: "owner", an #id or a name.
     *
     * spec: brief/participants.md#доступ-к-проектам-и-передача — access to a project is given to people and to agents alike
     */
    public static function participant(MemberRepository $principals, string $to): Member|string {
        return self::among($principals, $to, true);
    }

    private static function among(MemberRepository $principals, string $to, bool $agents): Member|string {
        $to = trim($to);
        $people = array_values(array_filter(
            $principals->all(),
            static fn(Member $p): bool => ($agents || $p->kind === MemberKind::Human) && $p->status === MemberStatus::Active,
        ));
        // a participant as the agent itself shows them — «#2 · Имя» in lists, «Имя (#2)» in refusals — is that number
        if (preg_match('~^#(\d{1,10})(?:\D|$)~u', $to, $shown) === 1 || preg_match('~\(#(\d{1,10})\)$~u', $to, $shown) === 1) {
            $to = $shown[1];
        }
        $key = mb_strtolower(ltrim($to, '@#'));
        if (in_array($key, ['owner', 'владелец', 'владельцу'], true)) {
            $owner = array_values(array_filter($people, static fn(Member $p): bool => $p->isOwner()));
            return $owner[0] ?? t('tool.send_message.no_owner');
        }
        if (ctype_digit($key)) {
            foreach ($people as $p) {
                if ($p->id === (int) $key) {
                    return $p;
                }
            }
        }
        $exact = array_values(array_filter($people, static fn(Member $p): bool => mb_strtolower($p->displayName ?? '') === $key));
        $found = $exact !== [] ? $exact : array_values(array_filter(
            $people,
            static fn(Member $p): bool => $key !== '' && str_contains(mb_strtolower($p->displayName ?? ''), $key),
        ));
        if (count($found) === 1) {
            return $found[0];
        }
        $names = implode(', ', array_map(static fn(Member $p): string => sprintf('%s (#%d)', $p->displayName ?? '—', $p->id), $found === [] ? $people : $found));
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
            if ($meta === null) {
                return t('tool.send_file.no_chat');
            }
            $home = $this->home === null ? null : ($this->home)($meta);
            if ($home === null && $meta->channel === \OpenCCK\App\Job\JobScheduler::CHANNEL) {
                return t('tool.send_file.no_chat');
            }
            return $home ?? [$meta->chatId, $meta->threadId === '' ? null : $meta->threadId];
        }
        $person = $this->find($to);
        if (is_string($person)) {
            $place = $this->place($to);
            if ($place === null) {
                return $this->places() === [] ? $person : $person . ' ' . sprintf(t('tool.send_message.places'), implode('; ', $this->places()));
            }
            return is_string($place) ? $place : [$place['chat'], $place['thread']];
        }
        $chat = $this->principals->externalId($person->id, $this->channel);
        return $chat === null ? sprintf(t('tool.send_message.no_chat'), $person->displayName ?? '#' . $person->id) : [$chat, null];
    }

    public function danger(?string $to): DangerClass {
        if ($to === null || trim($to) === '') {
            return DangerClass::Write;
        }
        $person = $this->find($to);
        return $person instanceof Member && $person->isOwner() ? DangerClass::Write : DangerClass::Irreversible;
    }

    public function grantKey(?string $to): ?string {
        if ($to === null || trim($to) === '') {
            return null;
        }
        $person = $this->find($to);
        if (is_string($person)) {
            $place = $this->place($to);
            return is_array($place) ? 'message:place:' . $place['chat'] . ($place['thread'] === null ? '' : ':' . $place['thread']) : null;
        }
        return !$person->isOwner() ? 'message:' . $person->id : null;
    }

    public function name(?string $to): string {
        if ($to === null || trim($to) === '') {
            return t('tool.send_content.this_chat');
        }
        $person = $this->find($to);
        if (is_string($person)) {
            $place = $this->place($to);
            return is_array($place) ? $place['label'] : $to;
        }
        return $person->displayName ?? '#' . $person->id;
    }
}
