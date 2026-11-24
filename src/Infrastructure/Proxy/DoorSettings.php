<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Proxy;

use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\Env;

use function Mercurius\I18n\t;

/**
 * Where the door of the installation's way out leads and whether it is open. With VLESS_URI the door is the VLESS
 * tunnel; else it leads to the owner's proxy: SANDBOX_PROXY, or DEFAULT_PROXY when that one is empty; none of the
 * three — there is no door, and a command in the sandbox goes out directly, as it always could.
 *
 * spec: brief/agent-loop.md#прокси-для-команд-песочницы
 */
final class DoorSettings {
    /** the doors as a command in the sandbox reaches them: the name of the service in the network of the sandbox */
    public const HTTP = 'http://vless-proxy:8080';
    public const SOCKS = 'socks5h://vless-proxy:1080';

    /**
     * The proxy behind the door when the installation has no VLESS; null — none is set.
     *
     * @param array<array-key, mixed> $env
     * @throws ConfigException
     */
    public static function proxy(array $env): ?string {
        foreach (['SANDBOX_PROXY', 'DEFAULT_PROXY'] as $name) {
            $proxy = Env::raw($env, $name);
            if ($proxy === null) {
                continue;
            }
            if (preg_match('#^(http|socks5h?)://[^\s/]+$#i', $proxy) !== 1) {
                throw ConfigException::invalid($name, 'a proxy like socks5h://user:pass@host:1080 or http://host:3128');
            }
            return $proxy;
        }
        return null;
    }

    /**
     * What the agent tells about the door now: its addresses and where it leads, or that there is none. The address
     * of the owner's proxy is not told: it stays with the door.
     *
     * @param array<array-key, mixed> $env
     */
    public static function about(array $env): string {
        if (!self::open($env)) {
            return t('self.proxy.off');
        }
        return sprintf(t('self.proxy'), self::HTTP, self::SOCKS, t(Env::raw($env, 'VLESS_URI') !== null ? 'self.proxy.vless' : 'self.proxy.owner'));
    }

    /**
     * Whether the door is open: the installation has VLESS or a proxy for the sandbox.
     *
     * @param array<array-key, mixed> $env
     */
    public static function open(array $env): bool {
        try {
            return Env::raw($env, 'VLESS_URI') !== null || self::proxy($env) !== null;
        } catch (ConfigException) {
            // a proxy that cannot be read: the door does not start with it
            return false;
        }
    }
}
