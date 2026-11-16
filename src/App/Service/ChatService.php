<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MessageHandler;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Repository\ConversationRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles one incoming message: checks who writes, answers commands, and hands everything else to the run scheduler.
 * It returns at once, so the chat queue is never held by a running task and /stop always gets through.
 *
 * spec: BRIEF.md#участники-люди-и-агенты — a stranger waits for the owner; a refused one is refused
 * spec: BRIEF.md#подтверждения-и-запреты — control commands bypass a busy conversation
 */
final class ChatService implements MessageHandler {
    public const REFUSAL = 'Это персональный агент. Доступа у вас нет.';
    public const NOT_TEXT = 'Пока я понимаю только текстовые сообщения.';
    public const NEW_CONVERSATION = 'Начат новый диалог. Предыдущий я больше не учитываю.';
    public const GREETING = "Здравствуйте! Я Меркурий, ваш персональный агент.\n\n/new — начать новый диалог.\n/stop — остановить текущую задачу.";
    public const NOTHING_TO_STOP = 'Сейчас ничего не выполняется.';
    public const NO_GRANTS = 'Сохранённых разрешений нет.';
    public const OWNER_ONLY = 'Эта команда только для владельца.';

    public function __construct(
        private readonly AccessService $access,
        private readonly ConversationRepository $conversations,
        private readonly RunScheduler $scheduler,
        private readonly Channel $channel,
        private readonly ?ApprovalService $approvals = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handle(IncomingMessage $message): void {
        $principal = $this->access->resolveMessage($message);
        if ($principal === null || $principal->status === PrincipalStatus::Rejected) {
            $this->logger->notice('Message from an unknown participant refused', ['channel' => $message->channel, 'user_id' => $message->userId]);
            $this->reply($message, self::REFUSAL);
            return;
        }
        if ($principal->status === PrincipalStatus::Pending) {
            $this->reply($message, VisitorService::WAITING);
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
        if ($command === '/stop') {
            // The run's status message says "Остановлено." itself.
            if (!$this->scheduler->stop($this->conversations->openConversation($principal->id, $message->channel, $message->chatId))) {
                $this->reply($message, self::NOTHING_TO_STOP);
            }
            return;
        }
        if ($command === '/permissions') {
            if (!$principal->isOwner()) {
                $this->reply($message, self::OWNER_ONLY);
            } elseif ($this->approvals?->showGrants($message->chatId, $message->messageId) !== true) {
                $this->reply($message, self::NO_GRANTS);
            }
            return;
        }
        if ($command === '/new') {
            $this->scheduler->stop($this->conversations->openConversation($principal->id, $message->channel, $message->chatId));
            $this->conversations->close($message->channel, $message->chatId);
            $this->reply($message, self::NEW_CONVERSATION);
            return;
        }

        $conversationId = $this->conversations->openConversation($principal->id, $message->channel, $message->chatId);
        $this->scheduler->submit($principal, $message, $conversationId);
    }

    private function reply(IncomingMessage $message, string $text): void {
        $result = $this->channel->send(new OutgoingMessage($message->chatId, $text, $message->messageId));
        if (!$result->success) {
            $this->logger->warning('Reply was not delivered', ['kind' => $result->errorKind?->value]);
        }
    }
}
