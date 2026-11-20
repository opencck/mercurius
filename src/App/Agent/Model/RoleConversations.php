<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\RoutingDecision;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\RoutingDecisionRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves the open conversations of a role to its new model. A conversation is the role's when it still has the old
 * model of the role and it got this role when it opened (the router's decision, or an earlier move). Without such a
 * record the model alone decides, and only when the old model stood in this one role. A run already going keeps its
 * model: the next run of the conversation takes the new one.
 *
 * spec: brief/agent-loop.md#модели — a change of the model of a role reaches the open conversations of that role
 * ref: references/hermes-agent/agent/agent_runtime_helpers.py@e96221fa:L2386-L2423 (switch_model) — hermes swaps the
 *      model of a live session and recomputes the context window of its compressor; here the swap waits for the
 *      next run, whose compactor reads the window of the run's model
 */
final class RoleConversations {
    public const METHOD = 'role_change';

    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly RoutingDecisionRepository $decisions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param bool $soleRole the old model stood in this role only: a conversation with no record of its role is moved
     * @return int how many open conversations moved
     */
    public function move(string $role, ModelRef $old, ModelRef $new, bool $soleRole): int {
        if ((string) $old === (string) $new) {
            return 0;
        }
        try {
            $open = $this->conversations->openModels();
            $roles = $this->decisions->openingRoles(array_keys($open));
        } catch (Throwable $e) {
            $this->logger->error('Open conversations of a role were not read', ['role' => $role, 'error' => $e::class]);
            return 0;
        }
        $moved = 0;
        foreach ($open as $conversationId => $model) {
            if (!self::same($model, $old)) {
                continue;
            }
            $opened = $roles[$conversationId] ?? null;
            if ($opened !== null ? $opened !== $role : !$soleRole) {
                continue;
            }
            $reason = sprintf('роль %s: %s → %s', $role, $old, $new);
            try {
                $this->conversations->switchModel($conversationId, (string) $new);
                $this->decisions->record(new RoutingDecision($conversationId, self::METHOD, self::METHOD, $role, (string) $new, $reason));
            } catch (Throwable $e) {
                $this->logger->error('A conversation did not move to the new model of its role', ['conversation' => $conversationId, 'error' => $e::class]);
                continue;
            }
            $this->logger->notice('Conversation moved to the new model of its role', ['conversation' => $conversationId, 'role' => $role, 'from' => (string) $old, 'to' => (string) $new]);
            $moved++;
        }
        return $moved;
    }

    /** Stored models may lack the provider (written before providers): «z-ai/x» is «openrouter:z-ai/x». */
    private static function same(string $model, ModelRef $ref): bool {
        try {
            return (string) ModelRef::parse($model) === (string) $ref;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
