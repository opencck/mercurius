<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Peer\AgentKeys;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent's keys for other agents: the list with fingerprints, a new key, the key a neighbour sees. The agent makes
 * and picks keys itself — the owner is not asked (the owner's decision of 2026-11-17); only for the owner's runs.
 *
 * spec: brief/network.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17
 */
final class PeerKeysTool implements Tool {
    public const NAME = 'peer_keys';

    public function __construct(
        private readonly PeerNetwork $network,
        private readonly AgentKeys $keys,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.peer_keys.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'create', 'use']],
                'key' => ['type' => 'string', 'maxLength' => 32, 'description' => t('tool.peer_keys.key')],
                'peer' => ['type' => 'string', 'maxLength' => 40],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'list' ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.peer_keys.owner_only'));
        }
        $key = trim(Args::string($args, 'key') ?? '');
        try {
            return ToolResult::ok(match (Args::string($args, 'action')) {
                'create' => $this->network->makeKey($key),
                'use' => $this->network->useKey(Args::string($args, 'peer') ?? '', $key),
                default => implode("\n", array_map(static fn(array $k): string => sprintf('%s — %s', $k['name'] === '' ? t('peer.peer_network.main_key') : $k['name'], $k['fingerprint']), $this->keys->list())),
            });
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
