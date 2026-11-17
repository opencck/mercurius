<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent's connections with other agents: which are open both ways, opening one to a neighbour, closing one. The
 * agent decides which connections it needs; a task to an agent goes over a live connection with it, whichever side
 * opened it. Only for the owner's runs.
 *
 * spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17
 */
final class PeerLinksTool implements Tool {
    public const NAME = 'peer_links';

    public function __construct(private readonly PeerNetwork $network) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.peer_links.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'open', 'close']],
                'peer' => ['type' => 'string', 'maxLength' => 40],
                'id' => ['type' => 'integer', 'minimum' => 1],
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
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->isOwner()) {
            return ToolResult::error(t('tool.peer_links.owner_only'));
        }
        try {
            return ToolResult::ok(match (Args::string($args, 'action')) {
                'open' => $this->network->open(Args::string($args, 'peer') ?? ''),
                'close' => $this->network->closeLink(Args::int($args, 'id') ?? 0) ? t('tool.peer_links.closed') : t('tool.peer_links.no_link'),
                default => $this->network->linksText(),
            });
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
