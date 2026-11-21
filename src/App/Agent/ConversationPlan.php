<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Todo\TodoStore;
use OpenCCK\App\Agent\Tool\Todo\TodoListTool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Mercurius\I18n\t;

/**
 * What of a conversation's plan is still to do, for the model: at the start of every run of the conversation (the
 * history of earlier runs goes without their tool rounds, so the list itself is not there) and next to the summary
 * after the context was shortened. Finished items are not given, or the model does them again.
 *
 * spec: brief/agent-loop.md#список-дел-беседы
 * ref: references/hermes-agent/agent/conversation_compression.py@e96221fa:L2974-L3000 (_fold_todo_snapshot) — the live
 *      list folded in after compression; here also at the start of a run
 */
final class ConversationPlan {
    public function __construct(
        private readonly TodoStore $store,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** The active items with a header; null — nothing is left to do or the plan cannot be read now. */
    public function active(int $conversationId): ?string {
        try {
            return $this->store->load(TodoListTool::place($conversationId))->render(t('agent.todo.header'));
        } catch (Throwable $e) {
            $this->logger->warning('The plan of the conversation was not read', ['conversation' => $conversationId, 'error' => $e::class]);
            return null;
        }
    }
}
