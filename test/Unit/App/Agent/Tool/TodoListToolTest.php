<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ToolCall;
use Mercurius\Todo\Infrastructure\InMemoryTodoStore;
use OpenCCK\App\Agent\ConversationPlan;
use OpenCCK\App\Agent\Tool\Todo\TodoListTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * The plan of a conversation: the tool, and the plan given to every run of the conversation.
 *
 * spec: brief/agent-loop.md#список-дел-беседы
 */
final class TodoListToolTest extends AsyncTestCase {
    private function context(int $conversation): ToolContext {
        return new ToolContext(1, $conversation, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation());
    }

    /** @return array{todos: list<array<string, string>>, revision: int, summary: array<string, int>} */
    private static function decode(string $json): array {
        /** @var array{todos: list<array<string, string>>, revision: int, summary: array<string, int>} */
        return json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    }

    public function testThePlanIsWrittenMergedAndReadAsAWhole(): void {
        $tool = new TodoListTool(new InMemoryTodoStore());

        $written = self::decode($tool->execute(['todos' => [
            ['id' => '1', 'content' => 'Собрать ленты', 'status' => 'in_progress'],
            ['id' => '2', 'content' => 'Написать дайджест', 'status' => 'pending'],
        ]], $this->context(7))->content);
        self::assertSame(1, $written['revision']);
        self::assertSame(['total' => 2, 'pending' => 1, 'in_progress' => 1, 'completed' => 0, 'cancelled' => 0], $written['summary']);

        $merged = self::decode($tool->execute(['todos' => [['id' => '1', 'status' => 'completed'], ['id' => '2', 'status' => 'in_progress']], 'merge' => true], $this->context(7))->content);
        self::assertSame(['completed', 'in_progress'], array_column($merged['todos'], 'status'));

        $read = self::decode($tool->execute([], $this->context(7))->content);
        self::assertSame($merged['todos'], $read['todos']);
        self::assertSame([], self::decode($tool->execute([], $this->context(8))->content)['todos'], 'another conversation has its own plan');
    }

    public function testAnItemWithoutTextIsRefusedAndThePlanStays(): void {
        $tool = new TodoListTool(new InMemoryTodoStore());
        $tool->execute(['todos' => [['id' => '1', 'content' => 'шаг']]], $this->context(7));

        $refused = $tool->execute(['todos' => [['id' => '1', 'content' => 'шаг'], ['id' => '2']]], $this->context(7));

        self::assertTrue($refused->isError);
        self::assertStringContainsString('«2»', $refused->content);
        self::assertCount(1, self::decode($tool->execute([], $this->context(7))->content)['todos']);
    }

    public function testTheNextRunOfTheConversationIsGivenWhatIsLeftOfThePlan(): void {
        $llm = new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('t1', 'todo_list', '{"todos":[{"id":"1","content":"Собрать ленты","status":"completed"},{"id":"2","content":"Написать дайджест","status":"in_progress"}]}')),
            ScriptedLlm::text('Собрал, пишу дальше.'),
            ScriptedLlm::text('Продолжаю.'),
        ]);
        $stack = new AgentStack($llm);
        $store = new InMemoryTodoStore();
        $stack->registry->register(new TodoListTool($store));
        $stack->scheduler->usePlan(new ConversationPlan($store));

        $stack->say('собери дайджест');
        $stack->scheduler->awaitIdle();
        $stack->say('продолжай');
        $stack->scheduler->awaitIdle();

        $requests = $llm->requests;
        $last = end($requests);
        self::assertNotFalse($last);
        $messages = $last->messages;
        $turn = end($messages);
        self::assertNotFalse($turn);
        self::assertStringContainsString('продолжай', $turn->content);
        self::assertStringContainsString('[>] 2. Написать дайджест', $turn->content, 'the plan rides with the turn');
        self::assertStringNotContainsString('Собрать ленты', $turn->content, 'finished items are not given');
    }
}
