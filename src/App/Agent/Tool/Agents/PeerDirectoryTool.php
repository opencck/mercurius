<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Peer\PeerDirectory;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Permission;

use function Mercurius\I18n\t;

/**
 * The directory of the network for a role with the permission peers: this agent, its neighbours with addresses and
 * keys, the live connections, and how many agents there are and how many of them are shown.
 *
 * spec: brief/network.md#справочник-сети
 */
final class PeerDirectoryTool implements Tool {
    public const NAME = 'peer_directory';

    private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public function __construct(
        private readonly PeerDirectory $directory,
        /** asks a neighbour for its directory; null — only this agent's own */
        private readonly ?PeerNetwork $network = null,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.peer_directory.about'), [
            'type' => 'object',
            'properties' => [
                'peer' => ['type' => 'string', 'maxLength' => 40, 'description' => t('tool.peer_directory.peer')],
            ],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        // names and addresses of neighbours come from their cards
        return true;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        if (!$context->principal->role->may(Permission::Peers)) {
            return ToolResult::error(t('tool.peer_directory.no_right'));
        }
        $peer = trim(Args::string($args, 'peer') ?? '');
        if ($peer !== '' && $this->network !== null) {
            // spec: brief/network.md#справочник-сети — the directory of a neighbour, by its method ListPeers
            try {
                $directory = $this->network->directoryOf($peer);
            } catch (DomainException $e) {
                return ToolResult::error($e->getMessage());
            }
            return ToolResult::ok((string) json_encode($directory, self::JSON));
        }
        return ToolResult::ok((string) json_encode($this->directory->of($context->principal), self::JSON));
    }
}
