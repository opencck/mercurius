<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The agent's connections with other agents: which are open both ways, opening one to a neighbour, closing one. The
 * agent decides which connections it needs; a task to an agent goes over a live connection with it, whichever side
 * opened it. Whoever has the tool by their policy may use it: the owner, or a participant the owner gave it to
 * (request_tool).
 *
 * spec: brief/network.md#участник-просит-инструмент
 * spec: brief/network.md#подключения-агентов
 */
final class PeerLinksTool implements Tool {
    public const NAME = 'peer_links';

    public function __construct(private readonly PeerNetwork $network) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.peer_links.about') . ' ' . t('tool.peer_links.events_about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['list', 'open', 'close', 'events']],
                'peer' => ['type' => 'string', 'maxLength' => 40],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(Args::string($args, 'action'), ['list', 'events'], true) ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (Args::string($args, 'action') === 'events') {
            // spec: brief/network.md#события-проекта-для-агентов — what neighbours told about the projects we work with there
            $events = $this->network->events();
            if ($events === []) {
                return ToolResult::ok(t('tool.peer_links.no_events'));
            }
            $lines = array_map(static fn(array $e): string => sprintf('%s %s: %s', gmdate('Y-m-d H:i', $e['at']), $e['from'], (string) json_encode($e['event'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)), array_slice($events, -30));
            return ToolResult::ok(\OpenCCK\App\Agent\Tool\Guard\UntrustedWrapper::wrap('peer_events', implode("\n", $lines)));
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
