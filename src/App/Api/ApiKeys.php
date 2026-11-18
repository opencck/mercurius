<?php

declare(strict_types=1);

namespace OpenCCK\App\Api;

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
 * spec: BRIEF.md#второй-канал-websocket-api
 * ref: references/hermes-agent/gateway/platforms/api_server.py@e96221fa:L1413-L1433 (_check_auth) — a Bearer key,
 *      refused without it; hermes has one key of the owner, here a key per participant, kept only as a hash
 */
final class ApiKeys {
    public const PREFIX = 'mk_';

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

    public function principals(): PrincipalRepository {
        return $this->principals;
    }

    public function revoke(int $id): bool {
        return $this->keys->revoke($id);
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
