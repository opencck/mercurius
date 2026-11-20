<?php

declare(strict_types=1);

/**
 * The channel: Telegram (directly, by proxy or VLESS; the cloud Bot API or a local server) and the WebSocket API.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use OpenCCK\Infrastructure\Http\HttpClients;

/**
 * Made by the parts before this one:
 *
 * @var Monolog\Logger $logger
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 */

// Channel: Telegram — directly, through a proxy or VLESS (TELEGRAM_PROXY); the cloud Bot API or a local server.
// spec: brief/channels.md#telegram-прокси-и-локальный-сервер-bot-api
$telegramHttp = HttpClients::create($telegram->route(), $logger);
$telegramFiles = rtrim($telegram->filesRoot, '/') . '/';
// a local server keeps files on its disk; they are read only inside its folder, up to the limit
$telegramLocalFile = static function (string $path, int $maxBytes) use ($telegramFiles): ?string {
    if (!str_starts_with($path, $telegramFiles) || str_contains($path, '..')) {
        return null;
    }
    try {
        return \Amp\File\getSize($path) > $maxBytes ? null : \Amp\File\read($path);
    } catch (\Amp\File\FilesystemException) {
        return null;
    }
};
$telegramApi = new TelegramApi($telegram->token(), $telegramHttp, $telegram->apiUrl ?? TelegramApi::DEFAULT_BASE_URL, localFile: $telegramLocalFile);
// Once, when moving to a local server, the bot leaves the cloud Bot API (logOut): updates then go to the local one.
// Back to the cloud — the marker is removed; Telegram lets a bot back only 10 minutes after logOut.
$cloudLoggedOut = PATH_ROOT . '/storage/telegram-cloud-logged-out';
if ($telegram->apiUrl !== null && !is_file($cloudLoggedOut)) {
    try {
        (new TelegramApi($telegram->token(), $telegramHttp))->call('logOut');
        $logger->notice('The bot left the cloud Bot API for the local server');
    } catch (\Mercurius\Messenger\Telegram\TelegramException $e) {
        $logger->notice('Cloud logOut', ['result' => mb_substr($e->getMessage(), 0, 200)]);
    }
    @file_put_contents($cloudLoggedOut, date('c'));
} elseif ($telegram->apiUrl === null && is_file($cloudLoggedOut)) {
    @unlink($cloudLoggedOut);
}
$logger->info('Telegram route', ['proxy' => $telegram->route() === null ? 'direct' : (str_starts_with($telegram->route(), 'vless') ? 'vless' : 'proxy'), 'api' => $telegram->apiUrl ?? 'cloud']);
// spec: brief/operations.md#второй-канал-websocket-api — chats ws:… go to the WebSocket API, the rest to Telegram
$wsChannel = new \Mercurius\Messenger\Websocket\WebsocketChannel();
$channel = new \Mercurius\Messenger\RoutingChannel(new TelegramChannel($telegramApi, logger: $logger), [\Mercurius\Messenger\Websocket\WebsocketChannel::PREFIX => $wsChannel]);
