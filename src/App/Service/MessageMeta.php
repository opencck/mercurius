<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Agent\Tool\SecretMasker;

/**
 * What the agent knows about a message besides its text: its number, the forum topic, and the message it answers
 * (author and a piece of its text). The owner asked "which message am I replying to?" and the agent could not tell.
 *
 * spec: BRIEF.md#telegram-группы-каналы-и-темы — the agent sees where and to what a message answers
 * ref: references/hermes-agent/gateway/inbound_reply_context.py@e96221fa:L60-L81 (reply_pointer) — the whole replied-to
 * text, not a preview, and "your previous message" for a reply to the agent; it goes into the turn's message, never the
 * system prompt. We add the message and topic numbers: the agent is asked about them in groups.
 */
final class MessageMeta {
    /** A Telegram message is at most 4096 characters; the cap only guards against a malformed update. */
    private const QUOTE = 4096;

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
        return sprintf('[Это сообщение #%s%s]', $message->messageId, $parts === [] ? '' : '; ' . implode('; ', $parts));
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
            $name = is_string($value) ? $value : null;
        }
        return $name === null ? sprintf('тема #%s', $message->threadId) : sprintf('тема «%s» (#%s)', $name, $message->threadId);
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
        $author = 'неизвестно кто';
        if (is_array($from)) {
            /** @var mixed $fromId */
            $fromId = $from['id'] ?? null;
            /** @var mixed $first */
            $first = $from['first_name'] ?? null;
            $author = (is_int($fromId) || is_string($fromId)) && (string) $fromId === $botId
                ? 'ты (агент)' . ($run === null ? '' : ' в запуске #' . $run)
                : (is_string($first) ? $first : 'участник');
        }
        /** @var mixed $text */
        $text = $reply['text'] ?? ($reply['caption'] ?? null);
        $piece = is_string($text) ? mb_substr(trim($text), 0, self::QUOTE) : '';
        return sprintf(
            'оно отвечает на сообщение #%s, которое написал %s%s',
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
