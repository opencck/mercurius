<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Server\SshTarget;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Repository\SshHostKeyRepository;
use OpenCCK\Domain\Ssh\SshHostKey;

use function Mercurius\I18n\t;

/**
 * The first connection to a server shows its key to the owner: trust it or not. A changed key is never trusted from a
 * card: the owner sees both and may forget the old one, then the next connection brings a card of its own.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the host key fingerprint goes to the owner at the first connection
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the key is kept by the entry and the address it was seen at
 */
final class HostKeyCards implements CallbackHandler {
    public const PREFIX = 'hk';

    public function __construct(
        private readonly SshHostKeyRepository $keys,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
    ) {}

    /**
     * @param SshHostKey $row the address the key was seen at
     * @param bool $jump the key is of the server in between
     */
    public function ask(SshTarget $target, SshHostKey $row, string $fingerprint, bool $changed, bool $jump): void {
        $user = $jump ? (string) $target->jumpUser : $target->user;
        $login = $user . '@' . $row->address;
        if ($changed) {
            $this->channel->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), sprintf(
                t('agent.host_key_cards.klyuch_hosta_servera_izmenilsya_byl'),
                $target->name,
                $login,
                (string) $row->hostKey,
                $fingerprint,
            ), buttons: [[
                new Button(t('agent.host_key_cards.forget'), sprintf('%s:%d:f:%s', self::PREFIX, $row->id, self::tag((string) $row->hostKey))),
            ]]));
            return;
        }
        $this->channel->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), sprintf(
            t('agent.host_key_cards.novyy_server_otpechatok_klyucha_hosta'),
            $target->name,
            $user,
            $row->address,
            $fingerprint,
        ), buttons: [[
            new Button(t('agent.host_key_cards.doveryat'), sprintf('%s:%d:a:%s', self::PREFIX, $row->id, self::tag($fingerprint))),
            new Button(t('agent.host_key_cards.ne_doveryat'), sprintf('%s:%d:r:%s', self::PREFIX, $row->id, self::tag($fingerprint))),
        ]]));
    }

    /** A short mark of the fingerprint the card shows: the button carries it, a press checks it. */
    private static function tag(string $fingerprint): string {
        return substr(hash('sha256', $fingerprint), 0, 12);
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^hk:(\d+):([arf]):([0-9a-f]{12})$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId, t('agent.host_key_cards.uzhe_resheno'));
            return;
        }
        $id = (int) $match[1];
        $row = $this->keys->find($id);
        if ($match[2] === 'f') {
            // the key the card showed as the old one: forgotten only while it is still the trusted one
            $trusted = $row?->hostKey;
            $done = $trusted !== null && hash_equals(self::tag($trusted), $match[3]) ? $this->keys->forget($id) : null;
            $this->finish($callback, $done === null ? null : sprintf(t('agent.host_key_cards.forgotten'), self::name($done)), t('agent.host_key_cards.forget'));
            return;
        }
        $trust = $match[2] === 'a';
        // spec: brief/servers.md#серверы-и-сеть — the press decides the key the card showed: a key that came
        //       in its place while the card waited has a card of its own
        $pending = $row?->pending;
        $decided = $pending !== null && hash_equals(self::tag($pending), $match[3]) ? $this->keys->decide($id, $trust, $pending) : null;
        $text = $decided === null ? null : ($trust
            ? sprintf(t('agent.host_key_cards.klyuch_servera_podtverzhden'), self::name($decided), (string) $decided->hostKey)
            : sprintf(t('agent.host_key_cards.klyuchu_servera_ne_doveryaem_podklyuchen'), self::name($decided)));
        $this->finish($callback, $text, $trust ? t('agent.host_key_cards.doveryaem') : t('agent.host_key_cards.ne_doveryaem'));
    }

    private function finish(IncomingCallback $callback, ?string $text, string $answer): void {
        if ($text === null) {
            $this->channel->answerCallback($callback->callbackId, t('agent.host_key_cards.uzhe_resheno'));
            return;
        }
        $this->channel->answerCallback($callback->callbackId, $answer);
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $text);
        }
    }

    /** The entry and the address: «web (web.example:22)». */
    private static function name(SshHostKey $row): string {
        $parts = explode('|', $row->server, 2);
        return sprintf('%s (%s)', $parts[1] ?? $row->server, $row->address);
    }
}
