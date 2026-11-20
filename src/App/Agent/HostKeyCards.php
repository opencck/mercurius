<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;

use function Mercurius\I18n\t;

/**
 * The first connection to a server shows its key to the owner: trust it or not. A changed key is only a warning — the
 * owner removes and adds the server again if the change is expected.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the host key fingerprint goes to the owner at the first connection
 */
final class HostKeyCards implements CallbackHandler {
    public const PREFIX = 'hk';

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
    ) {}

    public function ask(Server $server, string $fingerprint, bool $changed): void {
        if ($changed) {
            $this->channel->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), sprintf(
                t('agent.host_key_cards.klyuch_hosta_servera_izmenilsya_byl'),
                $server->name,
                $server->address(),
                (string) $server->hostKey,
                $fingerprint,
            )));
            return;
        }
        $this->channel->send(new OutgoingMessage(OwnerDirectory::chat($this->ownerChatId), sprintf(
            t('agent.host_key_cards.novyy_server_otpechatok_klyucha_hosta'),
            $server->name,
            $server->username,
            $server->address(),
            $fingerprint,
        ), buttons: [[
            new Button(t('agent.host_key_cards.doveryat'), sprintf('%s:%d:a:%s', self::PREFIX, $server->id, self::tag($fingerprint))),
            new Button(t('agent.host_key_cards.ne_doveryat'), sprintf('%s:%d:r:%s', self::PREFIX, $server->id, self::tag($fingerprint))),
        ]]));
    }

    /** A short mark of the fingerprint the card shows: the button carries it, a press checks it. */
    private static function tag(string $fingerprint): string {
        return substr(hash('sha256', $fingerprint), 0, 12);
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^hk:(\d+):([ar]):([0-9a-f]{12})$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId, t('agent.host_key_cards.uzhe_resheno'));
            return;
        }
        $trust = $match[2] === 'a';
        // spec: brief/servers.md#серверы-и-сеть — the press decides the key the card showed: a key that came
        //       in its place while the card waited has a card of its own
        $pending = $this->servers->find((int) $match[1])?->pendingHostKey;
        $server = $pending !== null && hash_equals(self::tag($pending), $match[3]) ? $this->servers->decideHostKey((int) $match[1], $trust, $pending) : null;
        if ($server === null) {
            $this->channel->answerCallback($callback->callbackId, t('agent.host_key_cards.uzhe_resheno'));
            return;
        }
        $text = $trust
            ? sprintf(t('agent.host_key_cards.klyuch_servera_podtverzhden'), $server->name, (string) $server->hostKey)
            : sprintf(t('agent.host_key_cards.klyuchu_servera_ne_doveryaem_podklyuchen'), $server->name);
        $this->channel->answerCallback($callback->callbackId, $trust ? t('agent.host_key_cards.doveryaem') : t('agent.host_key_cards.ne_doveryaem'));
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $text);
        }
    }
}
