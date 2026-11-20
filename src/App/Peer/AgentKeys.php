<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Mercurius\Peer\IdentityKeys;
use Mercurius\Peer\AgentIdentity;
use Closure;
use DomainException;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;

use function Mercurius\I18n\t;

/**
 * The agent's key pairs: the main one (it signs the card and the answers) and named ones it presents to chosen
 * neighbours. A named key is made the first time it is asked for and kept in the vault; the agent makes them itself,
 * without asking the owner (the owner's decision of 2026-11-17).
 *
 * spec: brief/network.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
 */
final class AgentKeys implements IdentityKeys {
    public const NAME_PATTERN = '~^[a-z0-9][a-z0-9_-]{0,31}$~';
    /** the vault name of the main key; a named key is this, '-' and its name */
    public const VAULT_NAME = 'a2a-identity-key';

    /** @var array<string, AgentIdentity> */
    private array $loaded = [];

    /** @param Closure(): ?Principal $owner the owner holds the keys in the vault */
    public function __construct(
        private readonly Vault $vault,
        private readonly Closure $owner,
        private readonly AgentIdentity $main,
    ) {}

    /** The main key from the vault, made and kept there on the first start. */
    public static function loadMain(Vault $vault, Principal $owner): AgentIdentity {
        $pem = $vault->reveal(self::VAULT_NAME, $owner);
        if ($pem === null) {
            $pem = AgentIdentity::generate()->privatePem();
            $vault->put(self::VAULT_NAME, 'private_key', $pem, $owner);
        }
        return AgentIdentity::fromPem($pem);
    }

    public function main(): AgentIdentity {
        return $this->main;
    }

    /** The key by name ('' — the main one), made and kept when there is none yet. */
    public function get(string $name): AgentIdentity {
        if ($name === '') {
            return $this->main;
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('peer.agent_keys.bad_name'));
        }
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }
        $owner = ($this->owner)() ?? throw new DomainException(t('peer.peer_network.vladelets_ne_nayden'));
        $secret = self::VAULT_NAME . '-' . $name;
        $pem = $this->vault->reveal($secret, $owner);
        if ($pem === null) {
            $made = AgentIdentity::generate();
            $this->vault->put($secret, 'private_key', $made->privatePem(), $owner);
            return $this->loaded[$name] = $made;
        }
        return $this->loaded[$name] = AgentIdentity::fromPem($pem);
    }

    public function exists(string $name): bool {
        $owner = ($this->owner)();
        return $name === '' || ($owner !== null && in_array(self::VAULT_NAME . '-' . $name, array_column($this->vault->names($owner), 'name'), true));
    }

    /** @return list<array{name: string, fingerprint: string}> the main key ('') and the named ones */
    public function list(): array {
        $keys = [['name' => '', 'fingerprint' => $this->main->fingerprint()]];
        $owner = ($this->owner)();
        if ($owner === null) {
            return $keys;
        }
        $prefix = self::VAULT_NAME . '-';
        foreach (array_column($this->vault->names($owner), 'name') as $secret) {
            if (str_starts_with($secret, $prefix)) {
                $name = substr($secret, strlen($prefix));
                $keys[] = ['name' => $name, 'fingerprint' => $this->get($name)->fingerprint()];
            }
        }
        return $keys;
    }
}
