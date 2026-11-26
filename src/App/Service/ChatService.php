<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use Mercurius\Messenger\MessageHandler;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Repository\ConversationRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Mercurius\I18n\t;

/**
 * Handles one incoming message: checks who writes, answers commands, and hands everything else to the run scheduler.
 * It returns at once, so the chat queue is never held by a running task and /stop always gets through.
 *
 * spec: brief/participants.md#участники-люди-и-агенты — a stranger waits for the owner; a refused one is refused
 * spec: brief/agent-loop.md#подтверждения-и-запреты — control commands bypass a busy conversation
 */
final class ChatService implements MessageHandler {
    // spec: brief/operations.md#язык-агента — the texts live in the mercurius-i18n catalogs; these are their keys
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
    /** @var array<string, true> messages of the update being handled that are already deleted */
    private array $gone = [];

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
        /** answers to the agent's personal messages go back to who asked */
        private readonly ?RelayService $relays = null,
        /** files of messages go into the sandbox and are described to the agent; null — files are not taken */
        private readonly ?\OpenCCK\App\Agent\Media\AttachmentIntake $intake = null,
        private readonly ?\OpenCCK\App\Service\PolicyService $policies = null,
        /** keys of the WebSocket API; null — no such channel */
        private readonly ?ApiKeyCommands $apiKeys = null,
        /** versions of the notes to the prompt; null — no self-improvement */
        private readonly ?PromptCommands $prompt = null,
    ) {}

    /** proposals of improvements: /proposals shows the undecided ones again */
    private ?\OpenCCK\App\Improve\Improvements $improvements = null;

    public function useImprovements(\OpenCCK\App\Improve\Improvements $improvements): void {
        $this->improvements = $improvements;
    }

    /** the owner passes ownership on: /owner <number> */
    private ?OwnershipTransfer $ownership = null;

    public function useOwnership(OwnershipTransfer $ownership): void {
        $this->ownership = $ownership;
    }

    /** @var (\Closure(int, \OpenCCK\Domain\Entity\Member): bool)|null whether a participant who is not the owner may see the trace of a run */
    private ?\Closure $runVisible = null;

    /** @param \Closure(int, \OpenCCK\Domain\Entity\Member): bool $visible */
    public function useRunVisibility(\Closure $visible): void {
        $this->runVisible = $visible;
    }

    /** the owner's list of roles: /roles and /role */
    private ?RoleCatalog $roles = null;
    private ?\OpenCCK\App\Agent\Model\ModelRoles $modelRoles = null;

    /** spec: brief/self-improvement.md#самоулучшение — /models: roles, their history and a rollback */
    public function useModelRoles(\OpenCCK\App\Agent\Model\ModelRoles $roles): void {
        $this->modelRoles = $roles;
    }

    private ?\OpenCCK\App\Export\ExportCommands $export = null;

    /** spec: brief/operations.md#экспорт-и-импорт-агента — /export: the owner's archive of the agent */
    public function useExport(\OpenCCK\App\Export\ExportCommands $export): void {
        $this->export = $export;
    }

    private ?\OpenCCK\App\Export\ImportCommands $import = null;

    /** spec: brief/operations.md#экспорт-и-импорт-агента — /import: the agent replaced by an archive */
    public function useImport(\OpenCCK\App\Export\ImportCommands $import): void {
        $this->import = $import;
    }

    private ?CodexCommands $codex = null;
    private ?ClaudeCommands $claude = null;

    /** spec: brief/models.md#вход-в-подписку-claude — /claude: the owner's Claude subscription */
    public function useClaude(ClaudeCommands $claude): void {
        $this->claude = $claude;
    }

    /** spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — /codex: the owner's ChatGPT subscription */
    public function useCodex(CodexCommands $codex): void {
        $this->codex = $codex;
    }

    /** /models — the roles and their latest changes; /models rollback <number> — a role back to that record. */
    private function modelsCommand(\OpenCCK\App\Agent\Model\ModelRoles $roles, Member $who, string $argument): string {
        $parts = preg_split('/\s+/', trim($argument)) ?: [];
        if (($parts[0] ?? '') === 'rollback') {
            return $roles->rollback((int) ltrim($parts[1] ?? '0', '#'), $who);
        }
        $lines = [];
        foreach ($roles->current() as $role) {
            $lines[] = sprintf('%s: %s (%s)', $role->role, $role->model, $role->setBy === \OpenCCK\Domain\Agent\ModelRole::SET_BY_LIST ? $role->reason : $role->setBy);
        }
        $history = $roles->history(10);
        $lines[] = '';
        $lines[] = $history === [] ? t('model.model_roles.history_empty') : t('model.model_roles.history');
        foreach ($history as $record) {
            $lines[] = sprintf('#%d %s → %s · %s · %s', $record['id'], $record['role'], $record['model'], gmdate('Y-m-d H:i', $record['at']), mb_substr($record['reason'], 0, 120));
        }
        return implode("\n", $lines);
    }

    private ?\OpenCCK\App\Agent\BanList $bans = null;
    private ?\OpenCCK\App\Export\ProjectArchiveCommands $projectArchives = null;
    private ?\OpenCCK\App\Peer\StreamCommands $streamCommands = null;
    private ?OwnerOverview $overview = null;

    public function useOverview(OwnerOverview $overview): void {
        $this->overview = $overview;
    }

    public function useStreams(\OpenCCK\App\Peer\StreamCommands $commands): void {
        $this->streamCommands = $commands;
    }

    public function useProjectArchives(\OpenCCK\App\Export\ProjectArchiveCommands $archives): void {
        $this->projectArchives = $archives;
    }

    private ?SshKeyCommands $sshKeys = null;

    public function useSshKeys(SshKeyCommands $keys): void {
        $this->sshKeys = $keys;
    }

    public function useBans(\OpenCCK\App\Agent\BanList $bans): void {
        $this->bans = $bans;
    }

    public function useRoles(RoleCatalog $roles): void {
        $this->roles = $roles;
    }

    /** /roles — the list; /role add|set|remove … — a change. */
    private function roleCommand(RoleCatalog $roles, string $argument): string {
        $parts = preg_split('/\s+/', trim($argument), 4) ?: [];
        try {
            return match ($parts[0] ?? '') {
                'add' => $roles->add($parts[1] ?? '', trim(($parts[2] ?? '') . ' ' . ($parts[3] ?? ''))),
                'set' => $roles->set($parts[1] ?? '', $parts[2] ?? '', $parts[3] ?? ''),
                'remove' => $roles->remove($parts[1] ?? ''),
                default => $roles->describe(),
            };
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /** The run that wrote the agent's message this one answers; null when it is not known. */
    private function runOfReply(IncomingMessage $message): ?int {
        $replied = MessageMeta::repliedToAgent($message, $this->botId);
        return $replied === null ? null : $this->runMessages?->runOf($message->channel, $message->chatId, $replied);
    }

    public function handle(IncomingMessage $message): void {
        $this->gone = [];
        if (!$message->isPrivateChat && $this->groups !== null) {
            // spec: brief/channels.md#группа — a group decides who is answered and when
            $routed = $this->groups->route($message);
            if ($routed === null) {
                return;
            }
            [$known, $message, $silenceAllowed] = $routed;
            $this->proceed($known, $message, $silenceAllowed);
            return;
        }
        $principal = $this->access->resolveMessage($message);
        if ($principal === null || $principal->status === MemberStatus::Rejected) {
            $this->logger->notice('Message from an unknown participant refused', ['channel' => $message->channel, 'user_id' => $message->userId]);
            $this->reply($message, t(self::REFUSAL));
            return;
        }
        if ($principal->status === MemberStatus::Pending) {
            $this->reply($message, \Mercurius\I18n\t(VisitorService::WAITING));
            return;
        }
        // spec: brief/channels.md#личные-сообщения-участникам — an answer to the agent's question goes to the conversation that asked
        if ($this->relays?->answer($principal, $message) === true) {
            return;
        }
        $this->proceed($principal, $message);
    }

    private function proceed(Member $principal, IncomingMessage $message, bool $silenceAllowed = false): void {

        $text = trim($message->text);
        if ($message->attachments === [] && $message->repliedAttachments === []) {
            // spec: brief/operations.md#команды-в-одном-сообщении — every command line is its own command
            $pieces = CommandBatch::split($text);
            if (count($pieces) > 1) {
                foreach ($pieces as $piece) {
                    $this->proceed($principal, self::withText($message, $piece), $silenceAllowed);
                }
                return;
            }
        }
        // spec: brief/internet.md#приём — a message with files is not empty: a photo without a caption reaches the agent
        $intake = $this->intake;
        $policies = $this->policies;
        $attached = ($message->attachments !== [] || $message->repliedAttachments !== []) && $intake !== null && $policies !== null;
        if ($text === '' && !$attached) {
            $this->reply($message, t(self::NOT_TEXT));
            return;
        }
        $original = $message;
        // a command ends at any white space: "/claude\tcode" is the command /claude
        $command = $text === '' ? '' : strtolower((preg_split('~\s~u', $text, 2) ?: [''])[0]);
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
        // spec: brief/participants.md#обзор-владельца — a participant at a glance, from the owner's own chat
        if ($command === '/overview' && $this->overview !== null) {
            $this->reply($message, $this->overview->show($principal, trim(substr($text, strlen($command)))));
            return;
        }
        // spec: brief/network.md#потоки-мест-для-агентов — the owner gives agents the streams of places
        if (in_array($command, ['/stream', '/streams'], true) && $this->streamCommands !== null) {
            $this->reply($message, $this->streamCommands->handle($principal, $message, $command, trim(substr($text, strlen($command)))));
            return;
        }
        // spec: brief/agent-loop.md#запреты — bans by command: the owner of the agent, and the owner of a project for it
        if ($command === '/deny' && $this->bans !== null) {
            $this->bans->command($principal, trim(substr($text, strlen($command))), $message->chatId, $message->messageId);
            return;
        }
        if ($command === '/permissions') {
            $argument = trim(substr($text, strlen($command)));
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (str_starts_with(strtolower($argument), 'allow') && $this->approvals !== null) {
                // spec: brief/agent-loop.md#разрешения-навсегда — a grant for an area as a whole, by the owner's command
                $this->reply($message, $this->approvals->allow($principal, substr($argument, 5)));
            } elseif ($this->approvals?->showGrants($message->chatId, $message->messageId, $argument) !== true) {
                $this->reply($message, t(self::NO_GRANTS));
            }
            return;
        }
        if (in_array($command, ['/usage', '/runs', '/trace', '/delegation'], true) && $this->reports !== null) {
            $this->report($principal, $command, trim(substr($text, strlen($command))), $message);
            return;
        }
        if (in_array($command, ['/memory', '/forget', '/consolidate', '/dream', '/aliases', '/probe'], true) && $this->memory !== null) {
            // spec: brief/participants.md#контекст-по-месту-и-аудитории — memory is the owner's and is shown only in a private chat
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::MEMORY_PRIVATE));
            } else {
                $this->reply($message, match ($command) {
                    '/memory' => $this->memory->show(substr($text, strlen($command)), $message->channel, $message->chatId),
                    '/consolidate', '/dream' => $this->memory->consolidate(substr($text, strlen($command))),
                    '/aliases' => $this->memory->aliases(substr($text, strlen($command))),
                    '/probe' => $this->memory->probe(substr($text, strlen($command))),
                    default => $this->memory->forget(substr($text, strlen($command))),
                });
            }
            return;
        }
        if ($command === '/secret' && $this->servers !== null) {
            // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a secret goes to the vault and its message leaves the chat
            $argument = substr($text, strlen($command));
            $carries = trim($argument) !== '' && !str_starts_with(trim($argument), '- ');
            if (!$principal->role->may(Permission::Secrets)) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } else {
                $this->reply($message, $this->servers->secret($principal, $argument));
            }
            if ($carries && !$this->deleteOnce($message)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s'));
            }
            return;
        }
        if ($command === '/codex' && $this->codex !== null) {
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } else {
                $this->reply($message, $this->codex->handle($message->chatId, substr($text, strlen($command))));
            }
            return;
        }
        if ($command === '/claude' || str_starts_with($command, '/claude@')) {
            // the code of a sign-in is a secret: its message leaves the chat, and the command never reaches the history —
            // also where the module is not switched on, so a code sent there does not go to the model as text
            $argument = (preg_split('~\s~u', $text, 2) ?: [])[1] ?? '';
            if ($this->claude === null) {
                $this->reply($message, t('claude.module_off'));
            } elseif (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } else {
                $this->reply($message, $this->claude->handle($argument));
            }
            if (ClaudeCommands::carriesSecret($argument) && !$this->deleteOnce($message)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s'));
            }
            return;
        }
        if ($command === '/export' && $this->export !== null) {
            // spec: brief/operations.md#экспорт-и-импорт-агента — the password goes in a private chat, and its message leaves the chat
            $argument = trim(substr($text, strlen($command)));
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } else {
                $this->reply($message, $this->export->start($message->chatId, null, $argument));
            }
            if ($argument !== '' && !$this->deleteOnce($message)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s'));
            }
            return;
        }
        if ($command === '/import' && $this->import !== null) {
            // spec: brief/operations.md#экспорт-и-импорт-агента — the archive comes with its password, and the message leaves the chat
            $argument = trim(substr($text, strlen($command)));
            $file = $message->attachments[0] ?? $message->repliedAttachments[0] ?? null;
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif (!$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } else {
                $this->reply($message, $this->import->start($message->chatId, $argument, $file, $principal->id));
            }
            if (!in_array(strtolower($argument), ['', 'yes', 'no', 'memory', 'skills'], true) && !$this->deleteOnce($message)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s'));
            }
            return;
        }
        if ($command === '/models' && $this->modelRoles !== null) {
            $this->reply($message, $principal->isOwner() ? $this->modelsCommand($this->modelRoles, $principal, substr($text, strlen($command))) : t(self::OWNER_ONLY));
            return;
        }
        if (in_array($command, ['/settings', '/set', '/restart'], true) && $this->settings !== null) {
            // spec: brief/operations.md#настройки-агента — settings by commands, without a model; a secret value leaves the chat
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
            if ($command === '/set' && SettingsCommands::carriesSecret($argument) && !$this->deleteOnce($message)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s_2'));
            }
            return;
        }
        if (in_array($command, ['/peer', '/peers'], true) && $this->peers !== null) {
            // spec: brief/network.md#подключения-агентов — tokens are shown once, so only in the private chat
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                !$message->isPrivateChat => t(self::SECRET_PRIVATE),
                default => $this->peers->handle($principal, $command === '/peers' ? '' : substr($text, strlen($command))),
            });
            return;
        }
        if (in_array($command, ['/roles', '/role'], true) && $this->roles !== null) {
            // spec: brief/participants.md#свои-роли — only the owner, only in the private chat
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                !$message->isPrivateChat => t(self::PRIVATE_ONLY),
                default => $this->roleCommand($this->roles, $command === '/roles' ? '' : substr($text, strlen($command))),
            });
            return;
        }
        if ($command === '/owner' && $this->ownership !== null) {
            // spec: brief/participants.md#несколько-пользователей — only the owner, only in the private chat, always by a card
            $answer = match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                !$message->isPrivateChat => t(self::PRIVATE_ONLY),
                default => $this->ownership->ask($principal, $message->chatId, trim(substr($text, strlen($command)))),
            };
            if ($answer !== null) {
                $this->reply($message, $answer);
            }
            return;
        }
        if ($command === '/proposals' && $this->improvements !== null) {
            // spec: brief/self-improvement.md#самоулучшение — a lost card is shown again
            if (!$principal->isOwner()) {
                $this->reply($message, t(self::OWNER_ONLY));
            } elseif ($this->improvements->showOpen() === 0) {
                $this->reply($message, t('improve.none_open'));
            }
            return;
        }
        if ($command === '/prompt' && $this->prompt !== null) {
            // spec: brief/self-improvement.md#самоулучшение — versions of the notes and a rollback, the owner only
            $this->reply($message, $principal->isOwner() ? $this->prompt->handle(substr($text, strlen($command))) : t(self::OWNER_ONLY));
            return;
        }
        if ($command === '/apikey' && $this->apiKeys !== null) {
            // spec: brief/operations.md#второй-канал-websocket-api — a key is shown once, so only in the private chat
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                !$message->isPrivateChat => t(self::SECRET_PRIVATE),
                default => $this->apiKeys->handle(substr($text, strlen($command))),
            });
            return;
        }
        if ($command === '/doctor' && $this->doctor !== null) {
            $this->reply($message, $principal->isOwner() ? Doctor::render($this->doctor->run()) : t(self::OWNER_ONLY));
            return;
        }
        if (in_array($command, ['/jobs', '/hook'], true) && $this->jobs !== null) {
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                $command === '/jobs' => $this->jobs->jobs(),
                default => $this->jobs->hook(substr($text, strlen($command))),
            });
            return;
        }
        if ($command === '/mcp' && $this->mcp !== null) {
            // spec: brief/participants.md#свои-роли — MCP servers are managed by whoever has the permission mcp
            $this->reply($message, $principal->role->may(Permission::Mcp) ? $this->mcp->handle(substr($text, strlen($command)), $principal) : t(self::OWNER_ONLY));
            return;
        }
        if (in_array($command, ['/skills', '/skill'], true) && $this->skills !== null) {
            $this->reply($message, !$principal->isOwner() ? t(self::OWNER_ONLY) : ($command === '/skills' ? $this->skills->list($principal, $message) : $this->skills->skill($principal, $message, substr($text, strlen($command)))));
            return;
        }
        if ($command === '/secrets' && $this->servers !== null) {
            $this->reply($message, $principal->role->may(Permission::Secrets) && $message->isPrivateChat ? $this->servers->secrets($principal, substr($text, strlen($command))) : t(self::SECRET_PRIVATE));
            return;
        }
        if (in_array($command, ['/servers', '/server', '/audit'], true) && $this->servers !== null) {
            $this->reply($message, match (true) {
                !$principal->isOwner() => t(self::OWNER_ONLY),
                $command === '/servers' => $this->servers->servers(),
                $command === '/server' => $this->servers->server(substr($text, strlen($command))),
                default => $this->servers->audit(),
            });
            return;
        }
        // spec: brief/servers.md#ключи-ssh-в-песочнице — one's own key goes into ~/.ssh past the model; the file leaves the chat
        if ($command === '/sshkey' && $this->sshKeys !== null) {
            $words = preg_split('~\s+~', trim(substr($text, strlen($command)))) ?: [];
            $file = $message->attachments[0] ?? $message->repliedAttachments[0] ?? null;
            if (!$message->isPrivateChat) {
                $this->reply($message, t('sshkey.private_only'));
            } elseif (strtolower($words[0] ?? '') !== 'add') {
                $this->reply($message, t('sshkey.usage'));
            } else {
                $this->reply($message, $this->sshKeys->add($principal, $words[1] ?? '', $file));
            }
            if ($file !== null && !$this->deleteOnce($message)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s'));
            }
            return;
        }
        // spec: brief/participants.md#экспорт-и-импорт-проекта — a project moves as a sealed file; its password leaves the chat
        $sub = strtolower(explode(' ', trim(substr($text, strlen($command))), 2)[0]);
        if ($command === '/project' && in_array($sub, ['export', 'import'], true) && $this->projectArchives !== null) {
            $argument = trim(substr(trim(substr($text, strlen($command))), strlen($sub)));
            if (!$message->isPrivateChat) {
                $this->reply($message, t(self::SECRET_PRIVATE));
            } elseif ($sub === 'export') {
                $this->reply($message, $this->projectArchives->export($principal, $message->chatId, $argument));
            } else {
                $this->reply($message, $this->projectArchives->import($principal, $message->chatId, $argument, $message->attachments[0] ?? $message->repliedAttachments[0] ?? null));
            }
            if ($argument !== '' && !$this->deleteOnce($message)) {
                $this->reply($message, t('service.chat.ne_udalos_udalit_soobschenie_s'));
            }
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
            // spec: brief/channels.md#группа — the agent sees what a message answers
            $header = MessageMeta::header($message, $this->botId, run: $this->runOfReply($message));
            if ($header !== '') {
                $message = self::withText($message, $header . "\n" . $message->text);
            }
        }
        $conversationId = $this->conversations->openConversation($principal->id, $message->channel, $message->chatId, MessageMeta::topicKey($message));
        $outside = null;
        // spec: brief/memory.md#как-сделано-области-и-факты — facts are looked up by what the participant wrote, not by what
        //      their files said
        $own = $message->text;
        if (($original->attachments !== [] || $original->repliedAttachments !== []) && $intake !== null && $policies !== null) {
            // the spend of the media models is counted on the conversation
            $taken = $intake->intake($principal, $policies->forPrincipal($principal), $original, new \Amp\TimeoutCancellation(180), $conversationId);
            $outside = $taken->outside ? 'attachments' : null;
            $message = self::withText($message, trim($message->text . "\n\n" . $taken->text));
            // the participant's own voice is their words: it counts with the text
            $own = $taken->outside ? $own : $message->text;
        }
        $this->scheduler->submit($principal, $message, $conversationId, $silenceAllowed, $outside, $own);
    }

    /**
     * A secret pasted into an ordinary message: the message leaves the chat, its text is kept in the vault (in a private
     * chat), and the agent gets the text with the secret masked and the name it is kept under.
     *
     * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a secret that appeared in Telegram is deleted by the bot and kept in the vault
     */
    private function withoutSecrets(Member $principal, IncomingMessage $message): IncomingMessage {
        if (!SecretMasker::carriesSecret($message->text) || $this->servers === null) {
            return $message;
        }
        $masked = SecretMasker::mask($message->text);
        if ($masked === $message->text) {
            return $message;
        }
        $name = $message->isPrivateChat && $principal->role->may(Permission::Secrets) ? $this->servers->stash($principal, $message->text, $message->messageId) : null;
        $deleted = $this->deleteOnce($message);
        $this->reply($message, sprintf(
            t('service.chat.v_soobschenii_byl_sekret'),
            $deleted ? t('service.chat.ya_udalil_soobschenie_iz_chata') : t('service.chat.udalit_soobschenie_ne_udalos_udalite'),
            $name === null ? t('service.chat.sohranit_sekret_mozhno_tolko_v') : sprintf(t('service.chat.tekst_sohranen_v_hranilische_kak'), $name),
        ));
        $note = $name === null ? '' : sprintf(t('service.chat.soobschenie_soderzhalo_sekret_ono_udalen'), $name);
        return self::withText($message, $masked . $note);
    }

    /**
     * Deletes the message from the chat once: several commands of one message that carried secrets delete it together.
     */
    private function deleteOnce(IncomingMessage $message): bool {
        $key = $message->chatId . ':' . $message->messageId;
        if (isset($this->gone[$key])) {
            return true;
        }
        $deleted = $this->channel->delete($message->chatId, $message->messageId);
        if ($deleted) {
            $this->gone[$key] = true;
        }
        return $deleted;
    }

    /** The same message with another text; everything else, the files included, stays. */
    private static function withText(IncomingMessage $message, string $text): IncomingMessage {
        return new IncomingMessage(
            $message->channel,
            $message->chatId,
            $message->userId,
            $message->userName,
            $message->displayName,
            $text,
            $message->messageId,
            $message->replyToMessageId,
            $message->isPrivateChat,
            $message->receivedAt,
            $message->updateId,
            $message->threadId,
            $message->isEdited,
            $message->raw,
            $message->attachments,
            $message->repliedAttachments,
        );
    }

    /** spec: brief/operations.md#команды-и-отчёты-владельца — reports for the owner; a long one goes as a file */
    private function report(\OpenCCK\Domain\Entity\Member $principal, string $command, string $argument, IncomingMessage $message): void {
        // spec: brief/participants.md#свои-роли — reports are a permission of a role too
        if ((!$principal->isOwner() && !$principal->role->may(\OpenCCK\Domain\Entity\Permission::Reports)) || $this->reports === null) {
            $this->reply($message, t(self::OWNER_ONLY));
            return;
        }
        // spec: brief/participants.md#контекст-по-месту-и-аудитории — a group hears what is said; reports are for the owner alone
        if (!$message->isPrivateChat) {
            $this->reply($message, t(self::PRIVATE_ONLY));
            return;
        }
        $words = preg_split('/\s+/', trim($argument)) ?: [];
        // spec: brief/operations.md#команды-и-отчёты-владельца — /trace <run> <step>: the step's prompt and answer as a file
        if ($command === '/trace' && count($words) === 2 && ctype_digit($words[0]) && ctype_digit($words[1])) {
            $run = (int) $words[0];
            $visible = $principal->isOwner() || $this->runVisible === null || ($this->runVisible)($run, $principal);
            $payload = $visible ? $this->reports->payload($run, (int) $words[1]) : null;
            if ($payload !== null && $payload['json'] !== null) {
                $this->channel->sendFile($message->chatId, sprintf('trace-%d-%d.json', $run, (int) $words[1]), $payload['json'], t('service.chat.otchet_tselikom'));
                return;
            }
            $this->reply($message, $payload['note'] ?? \Mercurius\I18n\t(TraceReports::NO_RUN));
            return;
        }
        $text = match ($command) {
            '/usage' => $this->reports->usage(ctype_digit($argument) ? (int) $argument : 7),
            '/runs' => $this->reports->runs(
                isset($words[0]) && ctype_digit($words[0]) ? (int) $words[0] : 10,
                isset($words[0]) && !ctype_digit($words[0]) ? $words[0] : ($words[1] ?? null),
            ),
            '/delegation' => $this->reports->delegation(ctype_digit($argument) ? (int) $argument : 14),
            // the trace of a run in another one's private chat is the owner's to see
            default => ctype_digit($argument) && ($principal->isOwner() || $this->runVisible === null || ($this->runVisible)((int) $argument, $principal)) ? $this->reports->trace((int) $argument) : null,
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
