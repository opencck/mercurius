<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Amp\DeferredFuture;
use Closure;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;

use function Mercurius\I18n\t;

/**
 * A request of an MCP server for consent (elicitation): a card to the owner of the entry, the press is the answer.
 * Only a yes or no is asked: a form with fields beyond booleans is declined — a secret is never typed into a card.
 * Nobody to ask — decline; no press in time — cancel.
 *
 * spec: brief/skills-mcp.md#процессы-и-реестр-инструментов-по-контекстам
 * ref: references/hermes-agent/tools/mcp_elicitation_form.py@e96221fa:L264-L343 (ask_consent, _UNSUBMITTED) — a form
 *      with no fields is a yes or no; nobody reached reads as decline, an expired prompt as cancel. hermes asks the
 *      fields of any form; here only booleans, set to true on consent
 * ref: references/ssh-mcp/src/guard/elicitation.ts@e04ebf2:L43-L84 — ssh-mcp asks with confirm: boolean and decides by action
 */
final class McpElicitations implements CallbackHandler {
    public const PREFIX = 'me';
    private const MESSAGE_MAX = 1500;

    private int $next = 0;
    /** @var array<int, array{DeferredFuture<array<string, mixed>>, string, ?int, list<string>}> waiting: the answer, the chat, who answers, the boolean fields */
    private array $waiting = [];

    /**
     * @param Closure(string): ?array{string, ?int} $whoAnswers the chat of the owner of a context's entries and the
     *        member who presses there (null — any owner in the owners' chat); null — nobody to ask
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly Closure $whoAnswers,
        private readonly float $seconds = 600.0,
    ) {}

    /**
     * @param array<array-key, mixed> $params the request of the server: message, requestedSchema
     * @return array<string, mixed> the result of elicitation/create
     */
    public function ask(string $context, string $server, array $params): array {
        $fields = self::booleans($params['requestedSchema'] ?? null);
        $whom = ($this->whoAnswers)($context);
        if ($fields === null || $whom === null) {
            return ['action' => 'decline'];
        }
        [$chat, $member] = $whom;
        $id = ++$this->next;
        /** @var DeferredFuture<array<string, mixed>> $answer */
        $answer = new DeferredFuture();
        $this->waiting[$id] = [$answer, $chat, $member, $fields];
        $message = isset($params['message']) && is_string($params['message']) ? McpManager::clean(mb_substr($params['message'], 0, self::MESSAGE_MAX)) : '';
        $this->channel->send(new OutgoingMessage($chat, sprintf(t('mcp.elicit.ask'), $server, $context, $message), buttons: [[
            new Button(t('mcp.elicit.allow'), sprintf('%s:%d:a', self::PREFIX, $id)),
            new Button(t('mcp.elicit.refuse'), sprintf('%s:%d:d', self::PREFIX, $id)),
        ]]));
        // no press in time: the server hears cancel (the card stays, a later press is already decided)
        $timer = \Revolt\EventLoop::delay($this->seconds, static function () use ($answer): void {
            if (!$answer->isComplete()) {
                $answer->complete(['action' => 'cancel']);
            }
        });
        try {
            return $answer->getFuture()->await();
        } finally {
            \Revolt\EventLoop::cancel($timer);
            unset($this->waiting[$id]);
        }
    }

    /**
     * The names of the boolean fields of the form; null — a field of another kind is asked.
     *
     * @return list<string>|null
     */
    private static function booleans(mixed $schema): ?array {
        /** @psalm-suppress MixedAssignment the schema is any JSON from the server; checked here */
        $properties = is_array($schema) && isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $names = [];
        /** @psalm-suppress MixedAssignment a field of the form is any JSON value; only booleans are taken */
        foreach ($properties as $name => $spec) {
            if (!is_array($spec) || ($spec['type'] ?? null) !== 'boolean') {
                return null;
            }
            $names[] = (string) $name;
        }
        return $names;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('~^me:(\d+):([ad])$~', $callback->data, $m) !== 1 || !isset($this->waiting[(int) $m[1]])) {
            $this->channel->answerCallback($callback->callbackId, t('mcp.elicit.decided'));
            return;
        }
        [$answer, $chat, $member, $fields] = $this->waiting[(int) $m[1]];
        // a card in a member's private chat is theirs to press; the owners' chat is checked by the router
        if ($callback->chatId !== $chat || ($member !== null && $callback->userId !== $chat)) {
            $this->channel->answerCallback($callback->callbackId, t('mcp.elicit.not_yours'));
            return;
        }
        unset($this->waiting[(int) $m[1]]);
        $allowed = $m[2] === 'a';
        $answer->complete($allowed ? ['action' => 'accept', 'content' => array_fill_keys($fields, true)] : ['action' => 'decline']);
        $this->channel->answerCallback($callback->callbackId, t($allowed ? 'mcp.elicit.allowed' : 'mcp.elicit.refused'));
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, t($allowed ? 'mcp.elicit.allowed' : 'mcp.elicit.refused'));
        }
    }
}
