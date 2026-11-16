<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\StreamEnd;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\TextDelta;
use Mercurius\Llm\Usage;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MessageHandler;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\TextSplitter;
use OpenCCK\Domain\Entity\ChatTurn;
use OpenCCK\Domain\Entity\LlmCallRecord;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\LlmCallRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Handles one incoming message: checks who writes, keeps the conversation, asks the model and streams
 * the answer back by editing one message, then records the usage.
 *
 * spec: BRIEF.md#участники-люди-и-агенты — strangers are refused
 */
final class ChatService implements MessageHandler {
    public const REFUSAL = 'Это персональный агент. Доступа у вас нет.';
    public const NOT_TEXT = 'Пока я понимаю только текстовые сообщения.';
    public const NEW_CONVERSATION = 'Начат новый диалог. Предыдущий я больше не учитываю.';
    public const GREETING = "Здравствуйте! Я Меркурий, ваш персональный агент.\n\n/new — начать новый диалог.";
    public const FAILURE = 'Не получилось получить ответ модели. Попробуйте ещё раз чуть позже.';
    public const EMPTY_ANSWER = 'Модель вернула пустой ответ.';
    private const PLACEHOLDER = '…';

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param non-empty-string $model
     * @param positive-int $maxTokens
     * @param positive-int $historyLimit
     * @param (Closure(): float)|null $clock seconds, for tests
     */
    public function __construct(
        private readonly AccessService $access,
        private readonly ConversationRepository $conversations,
        private readonly LlmCallRepository $calls,
        private readonly LlmClient $llm,
        private readonly Channel $channel,
        private readonly string $model,
        private readonly ?string $reasoningEffort,
        private readonly int $maxTokens,
        private readonly int $historyLimit,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly float $editInterval = 1.5,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public function handle(IncomingMessage $message): void {
        $principal = $this->access->resolve($message->channel, $message->userId, $message->displayName ?? $message->userName);
        if ($principal === null) {
            $this->logger->notice('Message from an unknown participant refused', ['channel' => $message->channel, 'user_id' => $message->userId]);
            $this->reply($message, self::REFUSAL);
            return;
        }

        $text = trim($message->text);
        if ($text === '') {
            $this->reply($message, self::NOT_TEXT);
            return;
        }
        $command = strtolower(explode(' ', $text, 2)[0]);
        if ($command === '/start') {
            $this->reply($message, self::GREETING);
            return;
        }
        if ($command === '/new') {
            $this->conversations->close($message->channel, $message->chatId);
            $this->reply($message, self::NEW_CONVERSATION);
            return;
        }

        $conversationId = $this->conversations->openConversation($principal->id, $message->channel, $message->chatId);
        $history = $this->conversations->recent($conversationId, $this->historyLimit);
        $this->conversations->append($conversationId, 'user', $text, $message->messageId);

        $request = new ChatRequest(
            model: $this->model,
            messages: [Message::system(SystemPrompt::text()), ...array_map(self::toMessage(...), $history), Message::user($text)],
            maxTokens: $this->maxTokens,
            reasoningEffort: $this->reasoningEffort,
        );

        $answer = $this->stream($message, $request, $conversationId);
        if ($answer !== null) {
            $this->conversations->append($conversationId, 'assistant', $answer);
        }
    }

    /** Streams the answer into one edited message; returns the final text or null on failure. */
    private function stream(IncomingMessage $message, ChatRequest $request, int $conversationId): ?string {
        $this->channel->typing($message->chatId);
        $placeholder = $this->channel->send(new OutgoingMessage($message->chatId, self::PLACEHOLDER, $message->messageId));
        $messageId = $placeholder->success ? $placeholder->messageId : null;

        $started = ($this->clock)();
        $lastEdit = $started;
        $shown = '';
        $text = '';
        try {
            foreach ($this->llm->stream($request) as $event) {
                if ($event instanceof TextDelta) {
                    $text .= $event->text;
                    $now = ($this->clock)();
                    if ($messageId !== null && $now - $lastEdit >= $this->editInterval && trim($text) !== '' && $text !== $shown) {
                        $this->channel->edit($message->chatId, $messageId, $text . ' ' . self::PLACEHOLDER);
                        $shown = $text;
                        $lastEdit = $now;
                    }
                } elseif ($event instanceof StreamEnd) {
                    $response = $event->response;
                    $this->record($conversationId, $response->model !== '' ? $response->model : $this->model, $response->finishReason->value, null, $started, $response->usage);
                    $final = trim($response->text) !== '' ? $response->text : self::EMPTY_ANSWER;
                    $this->deliver($message, $messageId, $final);
                    return $final;
                }
            }
            throw new LlmException(ErrorKind::Network, 'Stream ended without a final event');
        } catch (LlmException $e) {
            $this->logger->error('Model call failed', ['kind' => $e->kind->value, 'status' => $e->status, 'model' => $this->model]);
            $this->record($conversationId, $this->model, null, $e->kind->value, $started, null);
            $this->deliver($message, $messageId, self::FAILURE);
            return null;
        } catch (Throwable $e) {
            $this->logger->error('Chat handling failed', ['error' => $e::class]);
            $this->record($conversationId, $this->model, null, 'unknown', $started, null);
            $this->deliver($message, $messageId, self::FAILURE);
            return null;
        }
    }

    /** Final text: replaces the placeholder with the first part, sends the rest as new messages. */
    private function deliver(IncomingMessage $message, ?string $messageId, string $text): void {
        if ($messageId === null) {
            $this->reply($message, $text);
            return;
        }
        $parts = (new TextSplitter($this->channel->capabilities()->maxMessageLength))->split($text);
        $first = array_shift($parts) ?? $text;
        $this->channel->edit($message->chatId, $messageId, $first);
        foreach ($parts as $part) {
            $this->channel->send(new OutgoingMessage($message->chatId, $part));
        }
    }

    private function reply(IncomingMessage $message, string $text): void {
        $result = $this->channel->send(new OutgoingMessage($message->chatId, $text, $message->messageId));
        if (!$result->success) {
            $this->logger->warning('Reply was not delivered', ['kind' => $result->errorKind?->value]);
        }
    }

    private function record(int $conversationId, string $model, ?string $finishReason, ?string $errorKind, float $started, ?Usage $usage): void {
        $usage ??= new Usage();
        try {
            $this->calls->record(new LlmCallRecord(
                conversationId: $conversationId,
                purpose: 'chat',
                model: $model,
                finishReason: $finishReason,
                errorKind: $errorKind,
                inputTokens: max(0, $usage->inputTokens),
                outputTokens: max(0, $usage->outputTokens),
                cacheReadTokens: max(0, $usage->cacheReadTokens),
                cacheWriteTokens: max(0, $usage->cacheWriteTokens),
                reasoningTokens: max(0, $usage->reasoningTokens),
                costUsd: $usage->costUsd,
                durationMs: max(0, (int) round((($this->clock)() - $started) * 1000.0)),
            ));
        } catch (Throwable $e) {
            $this->logger->error('Could not record model usage', ['error' => $e::class]);
        }
    }

    private static function toMessage(ChatTurn $turn): Message {
        return $turn->role === 'user' ? Message::user($turn->content) : Message::assistant($turn->content);
    }
}
