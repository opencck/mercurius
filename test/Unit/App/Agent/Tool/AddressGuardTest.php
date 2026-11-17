<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use OpenCCK\App\Agent\Tool\AddressGuard;
use PHPUnit\Framework\TestCase;

final class AddressGuardTest extends TestCase {
    /** @param array<string, list<string>> $dns */
    private static function guard(array $dns = []): AddressGuard {
        return new AddressGuard(static fn(string $host): array => $dns[$host] ?? []);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedIps(): iterable {
        $ips = [
            '10.0.0.1', '127.0.0.1', '169.254.169.254', '172.16.5.4', '192.168.1.1', '100.64.0.1', '0.0.0.0', '224.0.0.1',
            '::1', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::', 'fec0::1', 'ff02::1',
        ];
        foreach ($ips as $ip) {
            yield $ip => [$ip];
        }
    }

    /** @dataProvider blockedIps */
    public function testPrivateAndLoopbackAddressesAreRefused(string $ip): void {
        self::assertTrue(AddressGuard::isBlockedIp($ip));
        self::assertNotNull(self::guard()->refusal($ip));
    }

    public function testPublicAddressesPass(): void {
        self::assertFalse(AddressGuard::isBlockedIp('93.184.216.34'));
        self::assertFalse(AddressGuard::isBlockedIp('2606:2800:220:1:248:1893:25c8:1946'));
    }

    public function testComposeServiceNameIsRefused(): void {
        self::assertSame(
            'Адрес postgres — внутренний: запрос только с разрешения владельца.',
            self::guard(['postgres' => ['93.184.216.34']])->refusal('postgres'),
        );
    }

    public function testNameResolvingToPrivateAddressIsRefused(): void {
        $guard = self::guard(['evil.example.com' => ['93.184.216.34', '10.0.0.5']]);

        self::assertSame('Адрес evil.example.com — внутренний: запрос только с разрешения владельца.', $guard->refusal('evil.example.com'));
    }

    public function testUnresolvedNameIsRefused(): void {
        self::assertSame('Не удалось найти адрес nowhere.example.', self::guard()->refusal('nowhere.example'));
    }

    public function testInternalIsToldApartFromUnknown(): void {
        $guard = self::guard(['evil.example.com' => ['10.0.0.5'], 'example.com' => ['93.184.216.34']]);

        self::assertTrue($guard->internal('evil.example.com'));
        self::assertTrue($guard->internal('postgres'));
        self::assertTrue($guard->internal('[::1]'));
        self::assertFalse($guard->internal('example.com'));
        self::assertFalse($guard->internal('nowhere.example'), 'a name that does not resolve is not internal');
    }

    public function testThePermittedHostAloneIsReachable(): void {
        $guard = self::guard(['nas.home.lan' => ['192.168.1.10'], 'postgres' => ['172.18.0.3'], 'other.lan' => ['10.0.0.2']]);

        self::assertSame(['192.168.1.10'], $guard->permitting('NAS.home.lan')->resolve('nas.home.lan'));
        self::assertSame(['172.18.0.3'], $guard->permitting('postgres')->resolve('postgres'));
        self::assertSame(['10.0.0.1'], $guard->permitting('10.0.0.1')->resolve('10.0.0.1'));
        self::assertNotNull($guard->permitting('nas.home.lan')->refusal('other.lan'), 'only the host the owner allowed');
        self::assertNotNull($guard->refusal('nas.home.lan'), 'the shared guard is unchanged');
    }

    public function testResolveReturnsCheckedAddresses(): void {
        self::assertSame(['93.184.216.34'], self::guard(['example.com' => ['93.184.216.34']])->resolve('example.com'));
    }
}
