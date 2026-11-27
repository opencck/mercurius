<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Llm;

use Amp\Cancellation;
use Amp\NullCancellation;
use Closure;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\FinishReason;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\ToolCall;
use Mercurius\Llm\ToolDefinition;
use Mercurius\Llm\Usage;
use Mercurius\Mcp\Models\McpModel;
use Throwable;

/**
 * The text models of a provider an MCP server offers, behind the interface of every model client. A request goes to the
 * tool of its model with the fixed arguments of the kind chat — the messages, the tools, the effort, the limit of the
 * answer — and the structured result is the answer: text, calls of tools, why it stopped, the tokens. MCP has no
 * stream of an answer: the whole answer comes at once, as one delta.
 *
 * Such a server sees the whole conversation: the system prompt, the memory, the results of tools. The owner is told so
 * when such a model is put into a category.
 *
 * spec: brief/skills-mcp.md#провайдеры-моделей-от-mcp — the kind chat
 */
final class McpChatClient implements LlmClient {
    /**
     * @param Closure(string): ?McpModel $model the chat model of the provider by its id
     * @param Closure(string, array<string, mixed>, Cancellation): array<array-key, mixed> $call calls a tool of the server: the text, whether it is an error, the structured content
     */
    public function __construct(
        private readonly Closure $model,
        private readonly Closure $call,
    ) {}

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        $model = ($this->model)($request->model);
        if ($model === null) {
            throw new LlmException(ErrorKind::BadRequest, sprintf('The MCP provider has no chat model "%s"', $request->model));
        }
        try {
            $answer = ($this->call)($model->tool, self::arguments($request), $cancellation ?? new NullCancellation());
        } catch (Throwable $e) {
            throw new LlmException(ErrorKind::Server, 'The MCP server of the model did not answer: ' . mb_substr($e->getMessage(), 0, 300), 502);
        }
        $text = isset($answer[0]) && is_string($answer[0]) ? $answer[0] : '';
        $structured = isset($answer[2]) && is_array($answer[2]) ? $answer[2] : null;
        if (($answer[1] ?? false) === true) {
            $quota = preg_match('~\b429\b|quota|RESOURCE_EXHAUSTED~i', $text) === 1;
            throw new LlmException($quota ? ErrorKind::RateLimited : ErrorKind::Server, mb_substr($text === '' ? 'The MCP model answered with an error' : $text, 0, 500), $quota ? 429 : 502);
        }
        return self::response($request->model, $structured, $text);
    }

    /** @return \Generator<int, TextDelta|StreamEnd, mixed, void> */
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable {
        $response = $this->complete($request, $cancellation);
        if ($response->text !== '') {
            yield new TextDelta($response->text);
        }
        yield new StreamEnd($response);
    }

    /** @return array<string, mixed> */
    private static function arguments(ChatRequest $request): array {
        $args = [
            'messages' => array_map(static function (Message $m): array {
                $row = ['role' => $m->role->value, 'content' => $m->content];
                if ($m->toolCalls !== []) {
                    $row['tool_calls'] = array_map(static fn(ToolCall $c): array => ['id' => $c->id, 'name' => $c->name, 'arguments' => $c->argumentsJson], $m->toolCalls);
                }
                if ($m->toolCallId !== null) {
                    $row['tool_call_id'] = $m->toolCallId;
                }
                return $row;
            }, $request->messages),
        ];
        if ($request->tools !== []) {
            $args['tools'] = array_map(static fn(ToolDefinition $t): array => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->parameters], $request->tools);
        }
        if ($request->reasoningEffort !== null) {
            $args['effort'] = $request->reasoningEffort;
        }
        if ($request->maxTokens !== null) {
            $args['max_tokens'] = $request->maxTokens;
        }
        return $args;
    }

    /** @param array<array-key, mixed>|null $structured */
    private static function response(string $model, ?array $structured, string $text): ChatResponse {
        $calls = [];
        $rows = isset($structured['tool_calls']) && is_array($structured['tool_calls']) ? $structured['tool_calls'] : [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row) || !isset($row['name']) || !is_string($row['name']) || $row['name'] === '') {
                continue;
            }
            /** @var mixed $arguments */
            $arguments = $row['arguments'] ?? '{}';
            $json = is_string($arguments) ? $arguments : json_encode($arguments === [] ? new \stdClass() : $arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id = isset($row['id']) && is_string($row['id']) && $row['id'] !== '' ? $row['id'] : 'call_' . $i;
            $calls[] = new ToolCall($id, $row['name'], $json === false ? '{}' : $json);
        }
        $said = isset($structured['text']) && is_string($structured['text']) ? $structured['text'] : ($structured === null ? $text : '');
        $finish = FinishReason::tryFrom(isset($structured['finish']) && is_string($structured['finish']) ? $structured['finish'] : '') ?? ($calls === [] ? FinishReason::Stop : FinishReason::ToolCalls);
        $usage = isset($structured['usage']) && is_array($structured['usage']) ? $structured['usage'] : [];
        $int = static fn(mixed $v): int => is_int($v) ? max(0, $v) : 0;
        return new ChatResponse($said, $calls, $finish, new Usage($int($usage['input'] ?? 0), $int($usage['output'] ?? 0)), $model, '');
    }
}
