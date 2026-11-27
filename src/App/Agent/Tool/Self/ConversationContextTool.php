<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Service\ConversationPlaces;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ConversationRepository;

use function Mercurius\I18n\t;

/**
 * The context of this private conversation in words: show where it works and what it may switch to, switch it. The
 * run in progress keeps its context; the next message works in the new one.
 *
 * spec: brief/participants.md#контекст-беседы-в-личном-чате
 */
final class ConversationContextTool implements Tool {
    public const NAME = 'conversation_context';

    public function __construct(
        private readonly ConversationPlaces $places,
        private readonly ConversationRepository $conversations,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.conversation_context.about'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['show', 'switch']],
                'target' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.conversation_context.target')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return Args::string($args, 'action') === 'switch' ? DangerClass::Write : DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 15;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $meta = $this->conversations->meta($context->conversationId);
        if ($meta === null || !ConversationPlaces::isPrivate($meta->chatId, $meta->threadId)) {
            return ToolResult::error(t('place.private_only'));
        }
        $who = $context->principal;
        if (Args::string($args, 'action') === 'switch') {
            [$done, $answer] = $this->places->switchTo($who, $meta->channel, $meta->chatId, Args::string($args, 'target') ?? '');
            return new ToolResult($done ? $answer . ' ' . t('tool.conversation_context.next') : $answer, !$done);
        }
        $names = array_map(static fn(Project $p): string => $p->name . ' (#' . $p->id . ')', $this->places->choices($who));
        return new ToolResult(sprintf(t('place.now'), $this->places->of($who, $meta->channel, $meta->chatId)->title())
            . "\n" . sprintf(t('tool.conversation_context.choices'), $who->isOwner() ? 'personal, global' : 'personal', $names === [] ? '—' : implode(', ', $names)));
    }
}
