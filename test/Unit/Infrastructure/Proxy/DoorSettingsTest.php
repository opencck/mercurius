<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Proxy;

use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Proxy\DoorSettings;
use PHPUnit\Framework\TestCase;

/**
 * Where the door for the commands of the sandbox leads: VLESS, the proxy of the sandbox, the default proxy, nowhere.
 */
final class DoorSettingsTest extends TestCase {
    public function testTheProxyOfTheSandboxWinsOverTheDefault(): void {
        self::assertNull(DoorSettings::proxy([]));
        self::assertSame('http://all.example:3128', DoorSettings::proxy(['DEFAULT_PROXY' => 'http://all.example:3128']));
        self::assertSame('socks5h://u:p@box.example:1080', DoorSettings::proxy(['SANDBOX_PROXY' => 'socks5h://u:p@box.example:1080', 'DEFAULT_PROXY' => 'http://all.example:3128']));
        self::assertSame('http://all.example:3128', DoorSettings::proxy(['SANDBOX_PROXY' => ' ', 'DEFAULT_PROXY' => 'http://all.example:3128']), 'an empty one is not set');
    }

    public function testAProxyThatCannotBeReadIsNamed(): void {
        foreach (['SANDBOX_PROXY', 'DEFAULT_PROXY'] as $name) {
            try {
                DoorSettings::proxy([$name => 'ftp://user:secret@x.example:21']);
                self::fail('a scheme the door does not speak');
            } catch (ConfigException $e) {
                self::assertStringContainsString($name, $e->getMessage());
                self::assertStringNotContainsString('secret', $e->getMessage());
            }
        }
    }

    public function testTheAgentIsToldTheDoorAndNotTheProxyBehindIt(): void {
        $vless = DoorSettings::about(['VLESS_URI' => 'vless://id-secret@vpn.example:443?security=tls']);
        self::assertStringContainsString('http://vless-proxy:8080', $vless);
        self::assertStringContainsString('socks5h://vless-proxy:1080', $vless);
        self::assertStringContainsString('VLESS', $vless);
        self::assertStringNotContainsString('vpn.example', $vless);

        $proxy = DoorSettings::about(['DEFAULT_PROXY' => 'http://user:secret@all.example:3128']);
        self::assertStringContainsString('http://vless-proxy:8080', $proxy);
        self::assertStringNotContainsString('all.example', $proxy);
        self::assertStringNotContainsString('secret', $proxy);
        self::assertStringNotContainsString('VLESS)', $proxy);

        self::assertStringNotContainsString('vless-proxy', DoorSettings::about([]), 'no door — no address to try');
    }

    public function testTheDoorIsOpenWithVlessOrAProxy(): void {
        self::assertFalse(DoorSettings::open([]));
        self::assertTrue(DoorSettings::open(['VLESS_URI' => 'vless://id@vpn.example:443?security=tls']));
        self::assertTrue(DoorSettings::open(['DEFAULT_PROXY' => 'http://all.example:3128']));
        self::assertTrue(DoorSettings::open(['SANDBOX_PROXY' => 'socks5://box.example:1080']));
        self::assertFalse(DoorSettings::open(['SANDBOX_PROXY' => 'nonsense']));
    }
}
