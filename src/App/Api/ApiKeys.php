<?php

declare(strict_types=1);

namespace OpenCCK\App\Api;

use Closure;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Repository\ApiKeyRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use SensitiveParameter;

use function Mercurius\I18n\t;

/**
 * Keys of the WebSocket API: the owner issues one to a participant (themselves too), it is shown once and only its
 * SHA-256 is kept; a key brings the participant with the rights they have, a revoked key or a participant who is no
 * longer active does not get in.
 *
 * spec: brief/operations.md#второй-канал-websocket-api
 * ref: references/hermes-agent/gateway/platforms/api_server.py@e96221fa:L1413-L1433 (_check_auth) — a Bearer key,
 *      refused without it; hermes has one key of the owner, here a key per participant, kept only as a hash
 */
final class ApiKeys {
    public const PREFIX = 'mk_';

    /** @var list<Closure(int): void> */
    private array $revoked = [];

    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly PrincipalRepository $principals,
    ) {}

    /** A new key of the participant, to show once. */
    public function issue(Principal $principal, string $name): string {
        $key = self::PREFIX . bin2hex(random_bytes(24));
        $this->keys->add($principal->id, mb_substr(trim($name), 0, 60), hash('sha256', $key));
        return $key;
    }

    /** The participant of an `Authorization: Bearer <key>` header; null when the key is unknown, revoked or theirs is not active. */
    public function authenticate(#[SensitiveParameter] string $authorization): ?Principal {
        if (preg_match('~^Bearer\s+(' . self::PREFIX . '[0-9a-f]{48})$~', trim($authorization), $m) !== 1) {
            return null;
        }
        $id = $this->keys->principalOf(hash('sha256', $m[1]));
        $principal = $id === null ? null : $this->principals->find($id);
        return $principal !== null && $principal->status === PrincipalStatus::Active ? $principal : null;
    }

    /**
     * The participant of a key and the number of the key: a connection keeps the number, so a revoke reaches it.
     *
     * @return array{Principal, int}|null
     */
    public function session(#[SensitiveParameter] string $authorization): ?array {
        $principal = $this->authenticate($authorization);
        if ($principal === null || preg_match('~^Bearer\s+(' . self::PREFIX . '[0-9a-f]{48})$~', trim($authorization), $m) !== 1) {
            return null;
        }
        $id = $this->keys->idOf(hash('sha256', $m[1]));
        return $id === null ? null : [$principal, $id];
    }

    /** Whether the key still works for this participant and the participant is still active. */
    public function alive(int $keyId, int $principalId): bool {
        if ($this->keys->holder($keyId) !== $principalId) {
            return false;
        }
        return $this->principals->find($principalId)?->status === PrincipalStatus::Active;
    }

    /** @param Closure(int): void $revoked gets the number of a key the owner revoked */
    public function onRevoke(Closure $revoked): void {
        $this->revoked[] = $revoked;
    }

    public function principals(): PrincipalRepository {
        return $this->principals;
    }

    public function revoke(int $id): bool {
        if (!$this->keys->revoke($id)) {
            return false;
        }
        // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — a revoked key does not stay on a connection already open
        foreach ($this->revoked as $revoked) {
            $revoked($id);
        }
        return true;
    }

    /** The keys that work, without the keys themselves. */
    public function text(): string {
        $keys = $this->keys->active();
        if ($keys === []) {
            return t('api.keys.none');
        }
        $lines = [];
        foreach ($keys as $key) {
            $who = $this->principals->find($key['principal']);
            $lines[] = sprintf('#%d %s — %s (#%d)%s', $key['id'], $key['name'] === '' ? '—' : $key['name'], ($who === null ? '?' : ($who->displayName ?? '?')), $key['principal'], $key['used'] === null ? '' : ', ' . t('api.keys.used') . ' ' . gmdate('Y-m-d H:i', $key['used']) . ' UTC');
        }
        return t('api.keys.list') . "\n" . implode("\n", $lines);
    }
}
