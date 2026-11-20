<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Guard;

use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Closure;

use function Amp\Dns\resolve;

use function Mercurius\I18n\t;

/**
 * Keeps outgoing requests away from internal addresses: private, loopback, link-local (cloud metadata), CGNAT,
 * multicast, reserved, IPv4 wrapped in IPv6, and names without a dot (services of the compose project).
 * A name that does not resolve is refused too. Only the owner lifts the ban, for one host and one call or by a grant:
 * the tool then works through permitting(), a copy of the guard that lets that host alone through.
 *
 * spec: brief/agent-loop.md#контекст — internal addresses only with the owner's permission (owner's decision 2026-11-17)
 * ref: references/hermes-agent/tools/url_safety.py@e96221f:L87-L116, L291-L326 (_embedded_ipv4, _is_blocked_ip) —
 *      blocked classes, wrapped IPv4 seen through, DNS failure means refusal; we have no allow_private_urls switch
 */
final class AddressGuard {
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];
    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::/8', '100::/64', '2001:2::/48', '2001:20::/28', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param (Closure(string): list<string>)|null $resolver host => IP addresses, for tests
     * @param ?string $permitted the one host the owner allowed whatever its addresses; null — none
     */
    public function __construct(?Closure $resolver = null, private readonly ?string $permitted = null) {
        $this->resolver = $resolver ?? static function (string $host): array {
            try {
                $ips = array_map(static fn(mixed $record): string => $record instanceof DnsRecord ? $record->getValue() : '', resolve($host));
                return array_values(array_filter($ips, static fn(string $ip): bool => $ip !== ''));
            } catch (DnsException) {
                return [];
            }
        };
    }

    /** A copy that also lets $host through, whatever addresses it has: the owner allowed it. */
    public function permitting(string $host): self {
        return new self($this->resolver, self::normal($host));
    }

    /** Whether the host is refused for being internal (not for being unknown): the owner may allow such a host. */
    public function internal(string $host): bool {
        $host = self::normal($host);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isBlockedIp($host);
        }
        if (!str_contains(rtrim($host, '.'), '.')) {
            return $host !== '';
        }
        foreach (($this->resolver)($host) as $ip) {
            if (self::isBlockedIp($ip)) {
                return true;
            }
        }
        return false;
    }

    private static function normal(string $host): string {
        return strtolower(trim($host, '[]'));
    }

    /** Text of the refusal, or null when the host may be reached. */
    public function refusal(string $host): ?string {
        try {
            $this->resolve($host);
            return null;
        } catch (AddressRefused $e) {
            return $e->getMessage();
        }
    }

    /**
     * Addresses to connect to; every one of them is checked.
     *
     * @return non-empty-list<string>
     * @throws AddressRefused
     */
    public function resolve(string $host): array {
        $host = self::normal($host);
        if ($this->permitted !== null && $host === $this->permitted) {
            $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
            if ($ips === []) {
                throw new AddressRefused(sprintf(t('tool.address_guard.ne_udalos_nayti_adres'), $host));
            }
            return $ips;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } elseif (!str_contains(rtrim($host, '.'), '.')) {
            throw new AddressRefused(sprintf(t('tool.address_guard.adres_vnutrenniy_takie_zaprosy_zapresche'), $host));
        } else {
            $ips = ($this->resolver)($host);
        }
        if ($ips === []) {
            throw new AddressRefused(sprintf(t('tool.address_guard.ne_udalos_nayti_adres'), $host));
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                throw new AddressRefused(sprintf(t('tool.address_guard.adres_vnutrenniy_takie_zaprosy_zapresche'), $host));
            }
        }
        return $ips;
    }

    public static function isBlockedIp(string $ip): bool {
        $bytes = @inet_pton(trim($ip, '[]'));
        if ($bytes === false) {
            return true;
        }
        if (strlen($bytes) === 16) {
            $embedded = self::embeddedV4($bytes);
            if ($embedded !== null) {
                $bytes = $embedded;
            }
        }
        foreach (strlen($bytes) === 4 ? self::BLOCKED_V4 : self::BLOCKED_V6 as $network) {
            if (self::inNetwork($bytes, $network)) {
                return true;
            }
        }
        return false;
    }

    /** IPv4 inside an IPv6 wrapper: mapped ::ffff:a.b.c.d, translated ::ffff:0:a.b.c.d, NAT64 64:ff9b::a.b.c.d. */
    private static function embeddedV4(string $bytes): ?string {
        $prefix = substr($bytes, 0, 12);
        $mapped = str_repeat("\0", 10) . "\xff\xff";
        $translated = str_repeat("\0", 8) . "\xff\xff\0\0";
        $nat64 = "\0\x64\xff\x9b" . str_repeat("\0", 8);
        return in_array($prefix, [$mapped, $translated, $nat64], true) ? substr($bytes, 12) : null;
    }

    private static function inNetwork(string $bytes, string $cidr): bool {
        [$base, $bits] = explode('/', $cidr);
        $network = inet_pton($base);
        if ($network === false || strlen($network) !== strlen($bytes)) {
            return false;
        }
        $bits = (int) $bits;
        $whole = max(0, intdiv($bits, 8));
        if (strncmp($bytes, $network, $whole) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($bytes[$whole]) & $mask) === (ord($network[$whole]) & $mask);
    }
}
