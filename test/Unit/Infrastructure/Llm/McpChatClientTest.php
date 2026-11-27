<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Llm;

use Amp\Cancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;
use Mercurius\Mcp\Models\McpModel;
use OpenCCK\Infrastructure\Llm\McpChatClient;

/**
 * A text model of a provider of an MCP answers through the tool of its model: the messages and the tools go as the
 * fixed arguments of the kind chat, the structured result is the answer.
 *
 * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp
 */
final class McpChatClientTest extends AsyncTestCase {
    /** @var list<array{string, array<string, mixed>}> */
    private array $asked = [];

    /** @param array<array-key, mixed> $answer */
    private function client(array $answer): McpChatClient {
        return new McpChatClient(
            static fn(string $id): ?McpModel => $id === 'talk' ? new McpModel('talk', 'chat', 'chat', null, ['text'], true, '', null, 128000) : null,
            function (string $tool, array $args, Cancellation $c) use ($answer): array {
                $this->asked[] = [$tool, $args];
                return $answer;
            },
        );
    }

    public function testTheConversationGoesToTheToolAndTheStructuredResultIsTheAnswer(): void {
        $client = $this->client(['', false, ['text' => 'Посмотрю.', 'tool_calls' => [['id' => 'c1', 'name' => 'read', 'arguments' => ['path' => 'a.txt']]], 'usage' => ['input' => 120, 'output' => 9]]]);
        $request = new ChatRequest('talk', [
            new Message(Role::System, 'Ты агент.'),
            new Message(Role::User, 'что в a.txt?'),
            new Message(Role::Assistant, '', [new ToolCall('c0', 'list', '{}')]),
            new Message(Role::Tool, 'a.txt', [], 'c0'),
        ], [new ToolDefinition('read', 'reads a file', ['type' => 'object'])], 500, null, 'low');

        $answer = $client->complete($request);

        self::assertSame('chat', $this->asked[0][0]);
        $args = json_encode($this->asked[0][1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('{"role":"tool","content":"a.txt","tool_call_id":"c0"}', $args);
        self::assertStringContainsString('"tool_calls":[{"id":"c0","name":"list","arguments":"{}"}]', $args);
        self::assertStringContainsString('"tools":[{"name":"read"', $args);
        self::assertStringContainsString('"effort":"low","max_tokens":500', $args);
        self::assertSame('Посмотрю.', $answer->text);
        self::assertSame(FinishReason::ToolCalls, $answer->finishReason);
        self::assertSame('{"path":"a.txt"}', $answer->toolCalls[0]->argumentsJson ?? null);
        self::assertSame([120, 9], [$answer->usage->inputTokens, $answer->usage->outputTokens]);
    }

    public function testTheServerCallsOnlyToolsItWasOfferedAndItsTextLosesInvisibleCharacters(): void {
        // spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp — the answer of a server is untrusted data
        $calls = [
            ['id' => 'a', 'name' => 'read', 'arguments' => '{"path":"a.txt"}'],
            ['id' => 'b', 'name' => 'run_command', 'arguments' => '{"command":"rm -rf /"}'],
            ['id' => 'c', 'name' => 'read', 'arguments' => 'not json'],
            ['id' => 'd', 'name' => 'read', 'arguments' => '[1, 2]'],
        ];
        for ($i = 0; $i < 30; $i++) {
            $calls[] = ['id' => 'm' . $i, 'name' => 'read', 'arguments' => []];
        }
        $answer = $this->client(['', false, ['text' => "Готово\u{E0041}\u{200B}.", 'tool_calls' => $calls]])
            ->complete(new ChatRequest('talk', [new Message(Role::User, 'hi')], [new ToolDefinition('read', 'reads a file', ['type' => 'object'])]));

        self::assertSame('Готово.', $answer->text);
        self::assertSame('a', $answer->toolCalls[0]->id ?? null);
        self::assertNotContains('run_command', array_map(static fn(ToolCall $c): string => $c->name, $answer->toolCalls), 'a tool it was not offered');
        self::assertNotContains('c', array_map(static fn(ToolCall $c): string => $c->id, $answer->toolCalls), 'arguments that are not an object');
        self::assertNotContains('d', array_map(static fn(ToolCall $c): string => $c->id, $answer->toolCalls));
        self::assertCount(McpChatClient::MAX_CALLS, $answer->toolCalls);
    }

    public function testTheStreamIsTheWholeAnswerAtOnceAndAnErrorIsAnErrorOfAModel(): void {
        $events = iterator_to_array($this->client(['', false, ['text' => 'Привет.']])->stream(new ChatRequest('talk', [new Message(Role::User, 'hi')])), false);
        self::assertInstanceOf(TextDelta::class, $events[0]);
        self::assertInstanceOf(StreamEnd::class, $events[1]);
        self::assertSame(FinishReason::Stop, $events[1]->response->finishReason);

        try {
            $this->client(['429 You exceeded your current quota', true, null])->complete(new ChatRequest('talk', [new Message(Role::User, 'hi')]));
            self::fail('an error of the server is an error of the model');
        } catch (LlmException $e) {
            self::assertSame(ErrorKind::RateLimited, $e->kind, 'a quota is a limit: the run moves to the next model');
        }
        $this->expectException(LlmException::class);
        $this->client([])->complete(new ChatRequest('other', [new Message(Role::User, 'hi')]));
    }
}
