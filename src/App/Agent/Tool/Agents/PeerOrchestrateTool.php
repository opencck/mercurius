<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Agents;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\LongRunning;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\PeerRepository;

use function Amp\async;
use function Amp\Future\await;
use function Mercurius\I18n\t;

/**
 * Gives parts of a task to several neighbouring agents at once and brings their answers together, in the order of the
 * parts; a part not answered within `wait` seconds is named, and its answer comes to the chat later.
 *
 * spec: brief/network.md#вызов-и-раздача-соседям — peer_orchestrate next to peer_delegate (decision В9)
 * ref: references/hermes-agent/plugins/platforms/a2a/tools.py@e96221fa:L254-L289 (a2a_orchestrate) — parallel calls, at
 *      most 6, answers joined in blocks; hermes sends the same message to every agent with a capability, here each
 *      part is its own task to its own agent, and the wait is one for all parts
 */
final class PeerOrchestrateTool implements Tool, ReadsOutside, LongRunning {
    public const NAME = 'peer_orchestrate';
    public const PARTS_MAX = 6;

    public function __construct(
        private readonly PeerNetwork $network,
        private readonly PeerRepository $peers,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        $names = $this->network->names();
        return new ToolDefinition(self::NAME, t('tool.peer_orchestrate.about'), [
            'type' => 'object',
            'properties' => [
                'parts' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => self::PARTS_MAX,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'peer' => $names === [] ? ['type' => 'string', 'maxLength' => 40] : ['type' => 'string', 'enum' => $names],
                            'task' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 8000],
                        ],
                        'required' => ['peer', 'task'],
                    ],
                ],
                'wait' => ['type' => 'integer', 'minimum' => 5, 'maximum' => PeerCallTool::WAIT_MAX, 'description' => t('tool.peer_call.wait')],
            ],
            'required' => ['parts'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Write;
    }

    /** spec: brief/agent-loop.md#подтверждения-и-запреты — answers of other agents are outside text */
    public function readsOutside(array $args): bool {
        return true;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return PeerCallTool::WAIT_MAX + 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        /** @var mixed $raw */
        $raw = $args['parts'] ?? [];
        $parts = [];
        if (is_array($raw)) {
            foreach (array_slice($raw, 0, self::PARTS_MAX) as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $peer = isset($part['peer']) && is_string($part['peer']) ? $part['peer'] : null;
                $task = isset($part['task']) && is_string($part['task']) ? trim($part['task']) : '';
                if ($peer !== null && $task !== '') {
                    $parts[] = ['peer' => $peer, 'task' => $task];
                }
            }
        }
        if (count($parts) < 2) {
            return ToolResult::error(t('tool.peer_orchestrate.needs'));
        }
        $wait = (float) max(5, min(PeerCallTool::WAIT_MAX, Args::int($args, 'wait') ?? 180));
        /** @var array<int, \Amp\Future<array{text: string, ended: bool}|string>> $futures */
        $futures = [];
        foreach ($parts as $i => $part) {
            $futures[$i] = async(fn(): array|string => PeerCallTool::call($this->network, $this->peers, $this->conversations, $context, $part['peer'], $part['task'], $wait));
        }
        /** @var array<int, array{text: string, ended: bool}|string> $results */
        $results = await($futures);
        $lines = [];
        $answered = 0;
        foreach ($parts as $i => $part) {
            $result = $results[$i] ?? t('tool.peer_call.needs');
            if (is_array($result) && $result['ended']) {
                $answered++;
            }
            $lines[] = sprintf('%d. %s — %s', $i + 1, $part['peer'], is_string($result) ? t('tool.peer_orchestrate.not_given') . ' ' . $result : $result['text']);
        }
        return ToolResult::ok(sprintf(t('tool.peer_orchestrate.head'), $answered, count($parts)) . "\n\n" . implode("\n\n", $lines));
    }
}
