<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Todo;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Todo\InvalidTodo;
use Mercurius\Todo\Item;
use Mercurius\Todo\TodoList;
use Mercurius\Todo\TodoStore;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

use function Mercurius\I18n\t;

/**
 * The plan of the conversation for multistep work: with `todos` — write (replace, or merge by id), without — read;
 * every call answers with the whole list, its revision and the counts by status. The plan belongs to the place: the
 * private chat, a chat or a topic of a project — everyone who writes there shares it — and ends with the
 * conversation.
 *
 * spec: brief/agent-loop.md#список-дел-беседы
 * ref: references/hermes-agent/tools/todo_tool.py@e96221fa:L250-L336 (todo_tool, TODO_SCHEMA) — one tool, write or
 *      read, the rules for the model in the description, the whole list back; here the list lives in a store by the
 *      conversation, not in the memory of the process
 */
final class TodoListTool implements Tool {
    public const NAME = 'todo_list';
    /** writes of two runs of one place at once: the later one is made again from the fresh list */
    private const ATTEMPTS = 3;

    public function __construct(private readonly TodoStore $store) {}

    /** The key of a conversation's plan in the store. */
    public static function place(int $conversationId): string {
        return 'conversation:' . $conversationId;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.todo_list.about'), [
            'type' => 'object',
            'properties' => [
                'todos' => [
                    'type' => 'array',
                    'description' => t('tool.todo_list.todos'),
                    'maxItems' => TodoList::MAX_ITEMS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'maxLength' => 64],
                            'content' => ['type' => 'string', 'description' => t('tool.todo_list.content')],
                            'status' => ['type' => 'string', 'enum' => Item::STATUSES],
                            'parent' => ['type' => 'string', 'maxLength' => 64, 'description' => t('tool.todo_list.parent')],
                        ],
                        'required' => ['id'],
                        'additionalProperties' => false,
                    ],
                ],
                'merge' => ['type' => 'boolean', 'description' => t('tool.todo_list.merge')],
            ],
            'additionalProperties' => false,
        ]);
    }

    /** It changes only the conversation's own plan: nothing outside, no card, a worker and a reading run may keep one too. */
    public function danger(array $args): DangerClass {
        return DangerClass::Read;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 10;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $place = self::place($context->conversationId);
        $todos = $args['todos'] ?? null;
        if ($todos === null) {
            return ToolResult::ok(self::answer($this->store->load($place)));
        }
        if (!is_array($todos) || !array_is_list($todos)) {
            return ToolResult::error(t('tool.todo_list.not_a_list'));
        }
        $merge = ($args['merge'] ?? false) === true;
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $before = $this->store->load($place);
            try {
                $after = $before->write($todos, $merge);
            } catch (InvalidTodo $e) {
                return ToolResult::error(sprintf(t('tool.todo_list.empty_item'), $e->itemId));
            }
            if ($after->revision === $before->revision || $this->store->save($place, $after, $before->revision)) {
                return ToolResult::ok(self::answer($after));
            }
        }
        return ToolResult::error(t('tool.todo_list.busy'));
    }

    /** The whole list, its revision and the counts, as hermes answers. */
    private static function answer(TodoList $list): string {
        return json_encode(['todos' => $list->toArray(), 'revision' => $list->revision, 'summary' => $list->summary()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
