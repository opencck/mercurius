<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Closure;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\Permission;

use function Mercurius\I18n\t;

/**
 * A new or changed entry of .mcp.json waits: the owners get a card with what it runs and which secrets it gets from
 * which layer, and approve or refuse it. A press decides the entry the card showed: one changed meanwhile has a card
 * of its own. Only a member with the permission mcp decides.
 *
 * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — the card of an entry; the approval is the consent to its secrets
 */
final class McpEntryCards implements CallbackHandler {
    public const PREFIX = 'mc';

    /** @param Closure(IncomingCallback): ?Member $presser who pressed, by their account in the channel */
    public function __construct(
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
        private readonly McpFiles $files,
        private readonly McpSecrets $secrets,
        private readonly Closure $presser,
    ) {}

    public function ask(string $context, McpEntry $entry): void {
        $lines = [];
        foreach ($this->secrets->layers($entry, $context) as $name => $layer) {
            $lines[] = sprintf(t('mcp.cards.secret_line'), $name, t('mcp.cards.layer.' . ($layer ?? 'none')));
        }
        $text = sprintf(t('mcp.cards.ask'), $entry->name, $context, $entry->address(), $lines === [] ? t('mcp.cards.no_secrets') : implode("\n", $lines))
            . ($entry->secretsInArgs() ? "\n" . t('mcp.cards.args_warning') : '');
        $tail = sprintf(':%s:%s|%s', self::tag($entry), $context, $entry->name);
        $this->channel->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), $text, buttons: [[
            new Button(t('mcp.cards.approve'), self::PREFIX . ':a' . $tail),
            new Button(t('mcp.cards.refuse'), self::PREFIX . ':r' . $tail),
        ]]));
    }

    /** A short mark of the entry the card shows: the button carries it, a press checks it. */
    private static function tag(McpEntry $entry): string {
        return substr($entry->hash(), 0, 12);
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('~^mc:([ar]):([0-9a-f]{12}):((?:member|project):\d+|owners)\|([a-z0-9_]{1,24})$~', $callback->data, $m) !== 1) {
            $this->channel->answerCallback($callback->callbackId, t('mcp.cards.decided'));
            return;
        }
        [, $action, $tag, $context, $name] = $m;
        $who = ($this->presser)($callback);
        if ($who === null || !$who->role->may(Permission::Mcp)) {
            $this->channel->answerCallback($callback->callbackId, t('mcp.cards.no_right'));
            return;
        }
        $entry = $this->files->current($context, $name);
        if ($entry === null || !hash_equals(self::tag($entry), $tag)) {
            $this->channel->answerCallback($callback->callbackId, t('mcp.cards.decided'));
            return;
        }
        if ($action === 'a') {
            $text = $this->files->approve($context, $name, $who, $tag);
        } else {
            $this->files->refuse($context, $name, $who, $entry);
            $text = sprintf(t('mcp.cards.refused'), $name, $context);
        }
        $this->channel->answerCallback($callback->callbackId, mb_substr($text, 0, 180));
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $text);
        }
    }
}
