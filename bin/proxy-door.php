<?php

declare(strict_types=1);

/**
 * The door to the owner's proxy for the commands of the sandbox: an HTTP proxy on 8080 and a SOCKS5 proxy on 1080
 * that open every connection through SANDBOX_PROXY (or DEFAULT_PROXY). It runs in the service `vless-proxy` when
 * the installation has no VLESS; with VLESS that service runs the `vless-proxy` of rekryt/amphp-vless instead.
 *
 * spec: brief/agent-loop.md#прокси-для-команд-песочницы
 */

use Mercurius\Connectors\RouteFactory;
use OpenCCK\Infrastructure\Proxy\DoorSettings;
use OpenCCK\Infrastructure\Proxy\ProxyDoor;
use Psr\Log\AbstractLogger;

use function Amp\trapSignal;

require dirname(__DIR__) . '/vendor/autoload.php';

$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void {
        fwrite(STDERR, sprintf("%s %s %s\n", is_string($level) ? $level : 'log', (string) $message, json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)));
    }
};

try {
    $proxy = DoorSettings::proxy(getenv());
    if ($proxy === null) {
        // the door never dials a site directly: that would hide a direct connection behind the word "proxy"
        fwrite(STDERR, "No proxy for the door: set SANDBOX_PROXY or DEFAULT_PROXY.\n");
        exit(1);
    }
    $door = new ProxyDoor(RouteFactory::fromUri($proxy), $logger);
} catch (Throwable $e) {
    // the message of a route error is written by the connectors without the secret part of the address
    fwrite(STDERR, 'The door cannot start: ' . $e->getMessage() . "\n");
    exit(1);
}
$door->http('0.0.0.0:8080');
$door->socks('0.0.0.0:1080');
fwrite(STDERR, 'The door is open through ' . RouteFactory::redact($proxy) . "\n");
trapSignal([SIGINT, SIGTERM]);
$door->stop();
