<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;

use function Mercurius\I18n\t;

/**
 * What the agent knows about a message besides its text: its number, the forum topic, and the message it answers
 * (author and a piece of its text). The owner asked "which message am I replying to?" and the agent could not tell.
 *
 * spec: brief/channels.md#группа — the agent sees where and to what a message answers
 * ref: references/hermes-agent/gateway/inbound_reply_context.py@e96221fa:L60-L81 (reply_pointer) — the whole replied-to
 * text, not a preview, and "your previous message" for a reply to the agent; it goes into the turn's message, never the
 * system prompt. We add the message and topic numbers: the agent is asked about them in groups.
 */
final class MessageMeta {
    /** A Telegram message is at most 4096 characters; the cap only guards against a malformed update. */
    private const QUOTE = 4096;

    /** Text from outside as one inert line of at most 240 characters: no line breaks, no control characters. */
    public static function inline(string $text, int $max = 240): string {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * The text of the message, or what it was when it had none: a sticker, a photo, a voice message. Without this a group
     * member's sticker or voice reply was an empty line the agent could not tell from nothing.
     */
    public static function content(IncomingMessage $message): string {
        $text = trim($message->text);
        if ($text !== '') {
            return $text;
        }
        $raw = $message->raw;
        $field = static function (string $kind, string $key) use ($raw): string {
            /** @var mixed $part */
            $part = $raw[$kind] ?? null;
            /** @var mixed $value */
            $value = is_array($part) ? ($part[$key] ?? null) : null;
            return is_string($value) || is_int($value) ? self::inline((string) $value, 80) : '';
        };
        $kinds = [
            'sticker' => fn(): string => trim(t('service.message_meta.sticker') . ' ' . $field('sticker', 'emoji')),
            'voice' => fn(): string => sprintf(t('service.message_meta.voice'), (int) $field('voice', 'duration')),
            'video_note' => fn(): string => t('service.message_meta.video_note'),
            'audio' => fn(): string => trim(t('service.message_meta.audio') . ' ' . $field('audio', 'title')),
            'photo' => fn(): string => t('service.message_meta.photo'),
            'video' => fn(): string => t('service.message_meta.video'),
            'animation' => fn(): string => t('service.message_meta.animation'),
            'document' => fn(): string => trim(t('service.message_meta.document') . ' ' . $field('document', 'file_name')),
            'location' => fn(): string => t('service.message_meta.location'),
            'contact' => fn(): string => t('service.message_meta.contact'),
            'poll' => fn(): string => trim(t('service.message_meta.poll') . ' ' . $field('poll', 'question')),
        ];
        foreach ($kinds as $kind => $describe) {
            if (isset($raw[$kind])) {
                return '[' . $describe() . ']';
            }
        }
        return '[' . t('service.message_meta.empty') . ']';
    }

    /**
     * Whether the message was forwarded: its text is what someone else wrote.
     *
     * spec: brief/memory.md#как-сделано-области-и-факты — facts are looked up by the participant's own words
     */
    public static function forwarded(IncomingMessage $message): bool {
        return isset($message->raw['forward_origin']) || isset($message->raw['forward_from']) || isset($message->raw['forward_from_chat']) || isset($message->raw['forward_date']);
    }

    /** A header line, or '' when there is nothing beyond the text (a plain private message). */
    public static function header(IncomingMessage $message, string $botId, bool $always = false, ?int $run = null): string {
        $parts = [];
        $topic = self::topic($message);
        if ($topic !== null) {
            $parts[] = $topic;
        }
        $reply = self::reply($message, $botId, $run);
        if ($reply !== null) {
            $parts[] = $reply;
        }
        if ($parts === [] && !$always) {
            return '';
        }
        return sprintf(t('service.message_meta.eto_soobschenie'), $message->messageId, $parts === [] ? '' : '; ' . implode('; ', $parts));
    }

    /**
     * The forum topic a message belongs to, '' when none. Outside a forum Telegram gives replies a thread id too (the
     * root of the reply chain); only `is_topic_message` marks a real topic, so an ordinary group stays one conversation.
     *
     * ref: references/hermes-agent/gateway/session.py@e96221fa:L722-L768 (build_session_key) — the session key is the
     *      chat and its thread; a thread is shared by everyone in it
     */
    public static function topicKey(IncomingMessage $message): string {
        if ($message->isPrivateChat || $message->threadId === null) {
            return '';
        }
        return ($message->raw['is_topic_message'] ?? false) === true ? $message->threadId : '';
    }

    /** "тема «Name» (#id)" for a message in a forum topic. */
    public static function topic(IncomingMessage $message): ?string {
        if ($message->threadId === null) {
            return null;
        }
        /** @var mixed $created */
        $created = self::reply_($message)['forum_topic_created'] ?? null;
        $name = null;
        if (is_array($created)) {
            /** @var mixed $value */
            $value = $created['name'] ?? null;
            $name = is_string($value) ? self::inline($value, 128) : null;
        }
        return $name === null ? sprintf(t('service.message_meta.tema'), $message->threadId) : sprintf(t('service.message_meta.tema_2'), $name, $message->threadId);
    }

    /**
     * "оно отвечает на сообщение #id, которое написал ты (агент): «text»"; the root message of a topic is the topic,
     * not a reply. A weak model mixed up "#292; ответ на #291" and named the number of the message itself.
     */
    public static function reply(IncomingMessage $message, string $botId, ?int $run = null): ?string {
        $reply = self::reply_($message);
        if ($reply === [] || isset($reply['forum_topic_created'])) {
            return null;
        }
        /** @var mixed $id */
        $id = $reply['message_id'] ?? null;
        /** @var mixed $from */
        $from = $reply['from'] ?? null;
        $author = t('service.message_meta.neizvestno_kto');
        $own = false;
        if (is_array($from)) {
            /** @var mixed $fromId */
            $fromId = $from['id'] ?? null;
            /** @var mixed $first */
            $first = $from['first_name'] ?? null;
            $own = (is_int($fromId) || is_string($fromId)) && (string) $fromId === $botId;
            $author = $own
                ? t('service.message_meta.ty_agent') . ($run === null ? '' : t('service.message_meta.v_zapuske') . $run)
                : (is_string($first) ? self::inline($first, 64) : t('service.message_meta.uchastnik'));
        }
        /** @var mixed $text */
        $text = $reply['text'] ?? ($reply['caption'] ?? null);
        // the agent's own message is quoted whole (it wrote it); what someone else wrote is one short inert line
        $piece = !is_string($text) ? '' : ($own ? mb_substr(trim($text), 0, self::QUOTE) : self::inline($text));
        return sprintf(
            t('service.message_meta.ono_otvechaet_na_soobschenie_kotoroe'),
            is_int($id) || is_string($id) ? (string) $id : '?',
            $author,
            $piece === '' ? '' : ': «' . SecretMasker::mask($piece) . '»',
        );
    }

    /** The id of the message this one answers, when it is the agent's own; null otherwise. */
    public static function repliedToAgent(IncomingMessage $message, string $botId): ?string {
        $reply = self::reply_($message);
        /** @var mixed $from */
        $from = $reply['from'] ?? null;
        /** @var mixed $fromId */
        $fromId = is_array($from) ? ($from['id'] ?? null) : null;
        /** @var mixed $id */
        $id = $reply['message_id'] ?? null;
        return (is_int($fromId) || is_string($fromId)) && (string) $fromId === $botId && (is_int($id) || is_string($id)) ? (string) $id : null;
    }

    /** @return array<array-key, mixed> */
    private static function reply_(IncomingMessage $message): array {
        /** @var mixed $reply */
        $reply = $message->raw['reply_to_message'] ?? null;
        return is_array($reply) ? $reply : [];
    }
}
