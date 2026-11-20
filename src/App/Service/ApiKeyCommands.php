<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\App\Api\ApiKeys;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Repository\PrincipalRepository;

use function Mercurius\I18n\t;

/**
 * The owner's `/apikey`: keys of the WebSocket API. `new <participant> [name]` shows a key once, `list` names the keys
 * that work, `revoke <#>` takes one away.
 *
 * spec: brief/operations.md#второй-канал-websocket-api
 */
final class ApiKeyCommands {
    public function __construct(
        private readonly ApiKeys $keys,
        private readonly PrincipalRepository $principals,
    ) {}

    public function handle(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument), 3) ?: [];
        $action = $parts[0] ?? '';
        if ($action === 'list' || $action === '') {
            return $this->keys->text();
        }
        if ($action === 'new') {
            $who = $this->principals->find((int) ltrim($parts[1] ?? '0', '#'));
            if ($who === null || $who->status !== PrincipalStatus::Active) {
                return t('api.keys.usage');
            }
            $key = $this->keys->issue($who, $parts[2] ?? '');
            return sprintf(t('api.keys.issued'), $who->displayName ?? ('#' . $who->id), $key);
        }
        if ($action === 'revoke') {
            return $this->keys->revoke((int) ltrim($parts[1] ?? '0', '#')) ? t('api.keys.revoked') : t('api.keys.no_key');
        }
        return t('api.keys.usage');
    }
}
