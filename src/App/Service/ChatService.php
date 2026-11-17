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

use function Mercurius\I18n\t;

/**
 * Handles one incoming message: checks who writes, answers commands, and hands everything else to the run scheduler.
 * It returns at once, so the chat queue is never held by a running task and /stop always gets through.
 *
 * spec: BRIEF.md#участники-люди-и-агенты — a stranger waits for the owner; a refused one is refused
 * spec: BRIEF.md#подтверждения-и-запреты — control commands bypass a busy conversation
 */
final class ChatService implements MessageHandler {
    // spec: BRIEF.md#язык-агента — the texts live in the mercurius-i18n catalogs; these are their keys
    public const REFUSAL = 'chat.refusal';
    public const NOT_TEXT = 'chat.not_text';
    public const NEW_CONVERSATION = 'chat.new_conversation';
    public const GREETING = 'chat.greeting';
    public const NOTHING_TO_STOP = 'chat.nothing_to_stop';
    public const NO_GRANTS = 'chat.no_grants';
    public const OWNER_ONLY = 'chat.owner_only';
    public const PRIVATE_ONLY = 'chat.private_only';
    public const MEMORY_PRIVATE = 'chat.memory_private';
    public const SECRET_PRIVATE = 'chat.secret_private';
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
        private readonly ?PeerCommands $peers = null,
        private readonly ?GroupGate $groups = null,
        /** the bot's id: a reply to its own message is named so */
        private readonly string $botId = '',
        /** names the run that wrote the message a reply answers */
        private readonly ?\OpenCCK\Domain\Repository\RunMessageRepository $runMessages = null,
    ) {}

    /** The run that wrote the agent's message this one answers; null when it is not known. */
    private function runOfReply(IncomingMessage $message): ?int {
        $replied = MessageMeta::repliedToAgent($message, $this->botId);
        return $replied === null ? null : $this->runMessages?->runOf($message->channel, $message->chatId, $replied);
    }

    public function handle(IncomingMessage $message): void {
        if (!$message->isPrivateChat && $this->groups !== null) {
            // spec: BRIEF.md#telegram-группы-каналы-и-темы — a group decides who is answered and when
            $routed = $this->groups->route($message);
            if ($routed === null) {
                return;
            }
            [$known, $message, $silenceAllowed] = $routed;
            $this->proceed($known, $message, $silenceAllowed);
            return;
        }
        $principal = $this->access->resolveMessage($message);
        if ($principal === null || $principal->status === PrincipalStatus::Rejected) {
            $this->logger->notice('Message from an unknown participant refused', ['channel' => $message->channel, 'user_id' => $message->userId]);
            $this->reply($message, t(self::REFUSAL));
            return;
        }
        if ($principal->status === PrincipalStatus::Pending) {
            $this->reply($message, \Mercurius\I18n\t(VisitorService::WAITING));
            return;
        }
        $this->proceed($principal, $message);
    }

    private function proceed(Principal $principal, IncomingMessage $message, bool $silenceAllowed = false): void {

        $text = trim($message->text);
        if ($text === '') {
            $this->reply($message, t(self::NOT_TEXT));
            return;
        }
        $command = strtolower(explode(' ', $text, 2)[0]);
        if ($command === '/start') {
            $this->reply($message, t(self::GREETING));
            return;
        }
        if ($command === '/stop') {
            // The run's status message says "Остановлено." itself.
            if (!$this->scheduler->stop($this->conversations->openConversation($principal->id, $message->channel, $message->chatId, MessageMeta::topicKey($message)))) {
                $this->reply($message, t(self::NOTHING_TO_STOP));
            }
            return;
        }
        if ($command === '/permissions') {
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif ($this->approvals?->showGrants($message->chatId, $message->messageId) !== true) {
                $this->reply($message, t(self::NO_GRANTS));
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
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::MEMORY_PRIVATE));
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
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } else {
                $this->reply($message, $this->servers->secret($principal, $argument));
            }
            if ($carries && !$this->channel->delete($message->chatId, $message->messageId)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s'));
            }
            return;
        }
        if (in_array($command, ['/settings', '/set', '/restart'], true) && $this->settings !== null) {
            // spec: BRIEF.md#настройки-агента — settings by commands, without a model; a secret value leaves the chat
            $argument = substr($text, strlen($command));
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif ($command === '/set' && SettingsCommands::carriesSecret($argument) && !$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } else {
                $this->reply($message, match ($command) {
                    '/settings' => $this->settings->show($argument),
                    '/set' => $this->settings->set($principal, $argument),
                    default => $this->settings->restart(),
                });
            }
            if ($command === '/set' && SettingsCommands::carriesSecret($argument) && !$this->channel->delete($message->chatId, $message->messageId)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s_2'));
            }
            return;
        }
        if (in_array($command, ['/peer', '/peers'], true) && $this->peers !== null) {
            // spec: BRIEF.md#сеть-агентов — tokens are shown once, so only in the private chat
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                !$message->isPrivateChat => t(self::SECRET_PRIVATE),
                default => $this->peers->handle($principal, $command === '/peers' ? '' : substr($text, strlen($command))),
            });
            return;
        }
        if ($command === '/doctor' && $this->doctor !== null) {
            $this->reply($message, $principal->isOwner() ? Doctor::render($this->doctor->run()) : t(self::OWNER_ONLY));
            return;
        }
        if (in_array($command, ['/jobs', '/hook', '/digest'], true) && $this->jobs !== null) {
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                $command === '/jobs' => $this->jobs->jobs(),
                $command === '/hook' => $this->jobs->hook(substr($text, strlen($command))),
                default => $this->jobs->digest(),
            });
            return;
        }
        if ($command === '/mcp' && $this->mcp !== null) {
            $this->reply($message, $principal->isOwner() ? $this->mcp->handle(substr($text, strlen($command))) : t(self::OWNER_ONLY));
            return;
        }
        if (in_array($command, ['/skills', '/skill'], true) && $this->skills !== null) {
            $this->reply($message, !$principal->isOwner() ? t(self::OWNER_ONLY) : ($command === '/skills' ? $this->skills->list() : $this->skills->skill(substr($text, strlen($command)))));
            return;
        }
        if ($command === '/secrets' && $this->servers !== null) {
            $this->reply($message, $principal->role !== Role::Visitor && $message->isPrivateChat ? $this->servers->secrets($principal) : t(self::SECRET_PRIVATE));
            return;
        }
        if (in_array($command, ['/servers', '/audit'], true) && $this->servers !== null) {
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                $command === '/servers' => $this->servers->servers(),
                default => $this->servers->audit(),
            });
            return;
        }
        if (in_array($command, ['/project', '/projects'], true) && $this->projects !== null) {
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } else {
                $this->reply($message, $command === '/projects'
                    ? $this->projects->list($message->channel, $message->chatId, MessageMeta::topicKey($message))
                    : $this->projects->project($message->channel, $message->chatId, substr($text, strlen($command)), MessageMeta::topicKey($message)));
            }
            return;
        }
        if ($command === '/new') {
            $this->scheduler->stop($this->conversations->openConversation($principal->id, $message->channel, $message->chatId, MessageMeta::topicKey($message)));
            $this->conversations->close($message->channel, $message->chatId, MessageMeta::topicKey($message));
            $this->reply($message, t(self::NEW_CONVERSATION));
            return;
        }

        $message = $this->withoutSecrets($principal, $message);
        if ($message->isPrivateChat) {
            // spec: BRIEF.md#telegram-группы-каналы-и-темы — the agent sees what a message answers
            $header = MessageMeta::header($message, $this->botId, run: $this->runOfReply($message));
            if ($header !== '') {
                $message = new IncomingMessage(
                    $message->channel,
                    $message->chatId,
                    $message->userId,
                    $message->userName,
                    $message->displayName,
                    $header . "\n" . $message->text,
                    $message->messageId,
                    $message->replyToMessageId,
                    $message->isPrivateChat,
                    $message->receivedAt,
                    $message->updateId,
                    $message->threadId,
                    $message->isEdited,
                    $message->raw,
                );
            }
        }
        $conversationId = $this->conversations->openConversation($principal->id, $message->channel, $message->chatId, MessageMeta::topicKey($message));
        $this->scheduler->submit($principal, $message, $conversationId, $silenceAllowed);
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
            t('service.chat.v_soobschenii_byl_sekret'),
            $deleted ? t('service.chat.ya_udalil_soobschenie_iz_chata') : t('service.chat.udalit_soobschenie_ne_udalos_udalite'),
            $name === null ? t('service.chat.sohranit_sekret_mozhno_tolko_v') : sprintf(t('service.chat.tekst_sohranen_v_hranilische_kak'), $name),
        ));
        $note = $name === null ? '' : sprintf(t('service.chat.soobschenie_soderzhalo_sekret_ono_udalen'), $name);
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
            $this->reply($message, t(self::OWNER_ONLY));
            return;
        }
        // spec: BRIEF.md#контекст-по-месту-и-аудитории — a group hears what is said; reports are for the owner alone
        if (!$message->isPrivateChat) {
            $this->reply($message, t(self::PRIVATE_ONLY));
            return;
        }
        $text = match ($command) {
            '/usage' => $this->reports->usage(ctype_digit($argument) ? (int) $argument : 7),
            '/runs' => $this->reports->runs(),
            '/delegation' => $this->reports->delegation(ctype_digit($argument) ? (int) $argument : 14),
            default => ctype_digit($argument) ? $this->reports->trace((int) $argument) : null,
        } ?? \Mercurius\I18n\t(TraceReports::NO_RUN);
        if (mb_strlen($text) > self::REPORT_LIMIT) {
            $this->channel->sendFile($message->chatId, sprintf('%s-%s.txt', ltrim($command, '/'), $argument === '' ? 'report' : $argument), $text, t('service.chat.otchet_tselikom'));
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
