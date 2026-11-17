<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Repository\ServerRepository;
use OpenCCK\Domain\Server\Server;

/**
 * The first connection to a server shows its key to the owner: trust it or not. A changed key is only a warning — the
 * owner removes and adds the server again if the change is expected.
 *
 * spec: BRIEF.md#фаза-7-по-частям — the host key fingerprint goes to the owner at the first connection
 */
final class HostKeyCards implements CallbackHandler {
    public const PREFIX = 'hk';

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
    ) {}

    public function ask(Server $server, string $fingerprint, bool $changed): void {
        if ($changed) {
            $this->channel->send(new OutgoingMessage($this->ownerChatId, sprintf(
                "⚠ Ключ хоста сервера «%s» (%s) изменился.\nБыл: %s\nСтал: %s\nПодключения к нему отклоняются. Если смена ожидаема — удалите сервер и добавьте снова; если нет — возможна подмена.",
                $server->name,
                $server->address(),
                (string) $server->hostKey,
                $fingerprint,
            )));
            return;
        }
        $this->channel->send(new OutgoingMessage($this->ownerChatId, sprintf(
            "Новый сервер «%s» (%s@%s).\nОтпечаток ключа хоста: %s\nСверьте на сервере: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub (или другой ключ из /etc/ssh). Доверять?",
            $server->name,
            $server->username,
            $server->address(),
            $fingerprint,
        ), buttons: [[
            new Button('Доверять', sprintf('%s:%d:a', self::PREFIX, $server->id)),
            new Button('Не доверять', sprintf('%s:%d:r', self::PREFIX, $server->id)),
        ]]));
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^hk:(\d+):([ar])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $trust = $match[2] === 'a';
        $server = $this->servers->decideHostKey((int) $match[1], $trust);
        if ($server === null) {
            $this->channel->answerCallback($callback->callbackId, 'Уже решено.');
            return;
        }
        $text = $trust
            ? sprintf('Ключ сервера «%s» подтверждён: %s.', $server->name, (string) $server->hostKey)
            : sprintf('Ключу сервера «%s» не доверяем: подключения отклоняются, при следующем отпечаток придёт снова.', $server->name);
        $this->channel->answerCallback($callback->callbackId, $trust ? 'Доверяем.' : 'Не доверяем.');
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, $text);
        }
    }
}
