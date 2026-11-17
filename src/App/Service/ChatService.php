<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MessageHandler;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
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
    public const GREETING = "Здравствуйте! Я Меркурий, ваш персональный агент.\n\n/new — начать новый диалог.\n/stop — остановить текущую задачу.

Для владельца: /permissions — сохранённые разрешения, /usage — расход, /runs — последние запуски, /trace <номер> — разбор запуска, /delegation — как агент делит задачи, /memory — что я помню, /forget <номер> — забыть факт, /consolidate — проверить память, /projects и /project <имя> — проекты и проект этого чата, /secret <имя> <значение> — сохранить секрет, /servers — серверы, /audit — журнал команд, /skills — скиллы, /mcp — MCP-серверы, /jobs — задания, /hook — webhook задания, /digest — сводка дел, /doctor — проверка установки, /settings — настройки, /set — изменить, /restart — перезапуск.";
    public const NOTHING_TO_STOP = 'Сейчас ничего не выполняется.';
    public const NO_GRANTS = 'Сохранённых разрешений нет.';
    public const OWNER_ONLY = 'Эта команда только для владельца.';
    public const PRIVATE_ONLY = 'Отчёты показываю только в личном чате.';
    public const MEMORY_PRIVATE = 'Память показываю только в личном чате.';
    public const SECRET_PRIVATE = 'Секреты — только в личном чате; сообщение с секретом я удалю.';
    private const REPORT_LIMIT = 3500;

    public function __construct(
        private readonly AccessService $access,
        private readonly ConversationRepository $conversations,
        private readonly RunScheduler $scheduler,
        private readonly Channel $channel,
        private readonly ?ApprovalService $approvals = null,
        private readonly ?TraceReports $reports = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?MemoryCommands $memory = null,
        private readonly ?ProjectCommands $projects = null,
        private readonly ?ServerCommands $servers = null,
        private readonly ?SkillCommands $skills = null,
        private readonly ?McpCommands $mcp = null,
        private readonly ?JobCommands $jobs = null,
        private readonly ?Doctor $doctor = null,
        private readonly ?SettingsCommands $settings = null,
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
        if (in_array($command, ['/usage', '/runs', '/trace', '/delegation'], true) && $this->reports !== null) {
            $this->report($principal->isOwner(), $command, trim(substr($text, strlen($command))), $message);
            return;
        }
        if (in_array($command, ['/memory', '/forget', '/consolidate'], true) && $this->memory !== null) {
            // spec: BRIEF.md#контекст-по-месту-и-аудитории — memory is the owner's and is shown only in a private chat
            if (!$principal->isOwner()) {
                $this->reply($message, self::OWNER_ONLY);
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, self::MEMORY_PRIVATE);
            } else {
                $this->reply($message, match ($command) {
                    '/memory' => $this->memory->overview($message->channel, $message->chatId),
                    '/consolidate' => $this->memory->consolidate(),
                    default => $this->memory->forget(substr($text, strlen($command))),
                });
            }
            return;
        }
        if ($command === '/secret' && $this->servers !== null) {
            // spec: BRIEF.md#фаза-7-по-частям — a secret goes to the vault and its message leaves the chat
            $argument = substr($text, strlen($command));
            $carries = trim($argument) !== '' && !str_starts_with(trim($argument), '- ');
            if ($principal->role === Role::Visitor) {
                $this->reply($message, self::OWNER_ONLY);
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, self::SECRET_PRIVATE);
            } else {
                $this->reply($message, $this->servers->secret($principal, $argument));
            }
            if ($carries && !$this->channel->delete($message->chatId, $message->messageId)) {
                $this->reply($message, 'Не удалось удалить сообщение с секретом — удалите его сами.');
            }
            return;
        }
        if (in_array($command, ['/settings', '/set', '/restart'], true) && $this->settings !== null) {
            // spec: BRIEF.md#настройки-агента — settings by commands, without a model; a secret value leaves the chat
            $argument = substr($text, strlen($command));
            if (!$principal->isOwner()) {
                $this->reply($message, self::OWNER_ONLY);
            } elseif ($command === '/set' && SettingsCommands::carriesSecret($argument) && !$message->isPrivateChat) {
                $this->reply($message, self::SECRET_PRIVATE);
            } else {
                $this->reply($message, match ($command) {
                    '/settings' => $this->settings->show($argument),
                    '/set' => $this->settings->set($principal, $argument),
                    default => $this->settings->restart(),
                });
            }
            if ($command === '/set' && SettingsCommands::carriesSecret($argument) && !$this->channel->delete($message->chatId, $message->messageId)) {
                $this->reply($message, 'Не удалось удалить сообщение с ключом — удалите его сами.');
            }
            return;
        }
        if ($command === '/doctor' && $this->doctor !== null) {
            $this->reply($message, $principal->isOwner() ? Doctor::render($this->doctor->run()) : self::OWNER_ONLY);
            return;
        }
        if (in_array($command, ['/jobs', '/hook', '/digest'], true) && $this->jobs !== null) {
            $this->reply($message, match (true) {
                !$principal->isOwner() => self::OWNER_ONLY,
                $command === '/jobs' => $this->jobs->jobs(),
                $command === '/hook' => $this->jobs->hook(substr($text, strlen($command))),
                default => $this->jobs->digest(),
            });
            return;
        }
        if ($command === '/mcp' && $this->mcp !== null) {
            $this->reply($message, $principal->isOwner() ? $this->mcp->handle(substr($text, strlen($command))) : self::OWNER_ONLY);
            return;
        }
        if (in_array($command, ['/skills', '/skill'], true) && $this->skills !== null) {
            $this->reply($message, !$principal->isOwner() ? self::OWNER_ONLY : ($command === '/skills' ? $this->skills->list() : $this->skills->skill(substr($text, strlen($command)))));
            return;
        }
        if ($command === '/secrets' && $this->servers !== null) {
            $this->reply($message, $principal->role !== Role::Visitor && $message->isPrivateChat ? $this->servers->secrets($principal) : self::SECRET_PRIVATE);
            return;
        }
        if (in_array($command, ['/servers', '/audit'], true) && $this->servers !== null) {
            $this->reply($message, match (true) {
                !$principal->isOwner() => self::OWNER_ONLY,
                $command === '/servers' => $this->servers->servers(),
                default => $this->servers->audit(),
            });
            return;
        }
        if (in_array($command, ['/project', '/projects'], true) && $this->projects !== null) {
            if (!$principal->isOwner()) {
                $this->reply($message, self::OWNER_ONLY);
            } else {
                $this->reply($message, $command === '/projects'
                    ? $this->projects->list($message->channel, $message->chatId)
                    : $this->projects->project($message->channel, $message->chatId, substr($text, strlen($command))));
            }
            return;
        }
        if ($command === '/new') {
            $this->scheduler->stop($this->conversations->openConversation($principal->id, $message->channel, $message->chatId));
            $this->conversations->close($message->channel, $message->chatId);
            $this->reply($message, self::NEW_CONVERSATION);
            return;
        }

        $message = $this->withoutSecrets($principal, $message);
        $conversationId = $this->conversations->openConversation($principal->id, $message->channel, $message->chatId);
        $this->scheduler->submit($principal, $message, $conversationId);
    }

    /**
     * A secret pasted into an ordinary message: the message leaves the chat, its text is kept in the vault (in a private
     * chat), and the agent gets the text with the secret masked and the name it is kept under.
     *
     * spec: BRIEF.md#фаза-7-по-частям — a secret that appeared in Telegram is deleted by the bot and kept in the vault
     */
    private function withoutSecrets(Principal $principal, IncomingMessage $message): IncomingMessage {
        $masked = SecretMasker::mask($message->text);
        if ($masked === $message->text || $this->servers === null) {
            return $message;
        }
        $name = $message->isPrivateChat && $principal->role !== Role::Visitor ? $this->servers->stash($principal, $message->text, $message->messageId) : null;
        $deleted = $this->channel->delete($message->chatId, $message->messageId);
        $this->reply($message, sprintf(
            'В сообщении был секрет: %s%s',
            $deleted ? 'я удалил сообщение из чата' : 'удалить сообщение не удалось — удалите его сами',
            $name === null ? '; сохранить секрет можно только в личном чате командой /secret.' : sprintf('; текст сохранён в хранилище как «%s».', $name),
        ));
        $note = $name === null ? '' : sprintf("\n\n[Сообщение содержало секрет: оно удалено из чата, полный текст — в хранилище секретов как «%s».]", $name);
        return new IncomingMessage(
            $message->channel,
            $message->chatId,
            $message->userId,
            $message->userName,
            $message->displayName,
            $masked . $note,
            $message->messageId,
            $message->replyToMessageId,
            $message->isPrivateChat,
            $message->receivedAt,
            $message->updateId,
            $message->threadId,
            $message->isEdited,
        );
    }

    /** spec: BRIEF.md#трассировка-и-метрики — reports for the owner; a long one goes as a file */
    private function report(bool $owner, string $command, string $argument, IncomingMessage $message): void {
        if (!$owner || $this->reports === null) {
            $this->reply($message, self::OWNER_ONLY);
            return;
        }
        // spec: BRIEF.md#контекст-по-месту-и-аудитории — a group hears what is said; reports are for the owner alone
        if (!$message->isPrivateChat) {
            $this->reply($message, self::PRIVATE_ONLY);
            return;
        }
        $text = match ($command) {
            '/usage' => $this->reports->usage(ctype_digit($argument) ? (int) $argument : 7),
            '/runs' => $this->reports->runs(),
            '/delegation' => $this->reports->delegation(ctype_digit($argument) ? (int) $argument : 14),
            default => ctype_digit($argument) ? $this->reports->trace((int) $argument) : null,
        } ?? TraceReports::NO_RUN;
        if (mb_strlen($text) > self::REPORT_LIMIT) {
            $this->channel->sendFile($message->chatId, sprintf('%s-%s.txt', ltrim($command, '/'), $argument === '' ? 'report' : $argument), $text, 'Отчёт целиком');
            return;
        }
        $this->reply($message, $text);
    }

    private function reply(IncomingMessage $message, string $text): void {
        $result = $this->channel->send(new OutgoingMessage($message->chatId, $text, $message->messageId));
        if (!$result->success) {
            $this->logger->warning('Reply was not delivered', ['kind' => $result->errorKind?->value]);
        }
    }
}
