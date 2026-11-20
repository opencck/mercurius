<?php

declare(strict_types=1);

/**
 * The daily digest, visitors and groups, the chat commands, the evaluation gate, export and import, Telegram updates.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\Deduplicator;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Telegram\UpdateParser;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Agent\HostKeyCards;
use OpenCCK\App\Agent\MemoryCards;
use OpenCCK\App\Agent\Tool\Memory\ExperienceSearchTool;
use OpenCCK\App\Agent\Tool\Memory\HistorySearchTool;
use OpenCCK\App\Agent\Tool\Memory\MemoryProposeTool;
use OpenCCK\App\Agent\Tool\Memory\MemorySearchTool;
use OpenCCK\App\Job\DailyDigest;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\JobCommands;
use OpenCCK\App\Service\McpCommands;
use OpenCCK\App\Service\MemoryCommands;
use OpenCCK\App\Service\ProjectCommands;
use OpenCCK\App\Service\ServerCommands;
use OpenCCK\App\Service\SkillCommands;
use OpenCCK\App\Service\TraceReports;
use OpenCCK\App\Service\VisitorService;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Repository\PostgresDigestJournal;
use OpenCCK\Infrastructure\Repository\PostgresEpisodeRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceReportRepository;
use Mercurius\Memory\App\Proposals;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\Infrastructure\Llm\ChatGpt\CodexAccount|null $codexAccount
 * @var OpenCCK\App\Export\AgentImport $agentImport
 * @var OpenCCK\App\Peer\AgentRequests $agentRequests
 * @var OpenCCK\Infrastructure\Repository\PostgresApprovalRepository $approvalStore
 * @var OpenCCK\App\Agent\ApprovalService $approvals
 * @var OpenCCK\App\Agent\ConversationAudience $audience
 * @var OpenCCK\App\Agent\Delegation\BackgroundDelegations $background
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var OpenCCK\App\Service\ChannelRegistry $channelRegistry
 * @var OpenCCK\App\Agent\Sandbox\Checkpoints|null $checkpoints
 * @var OpenCCK\Infrastructure\Repository\PostgresCommandAuditRepository $commandAudit
 * @var OpenCCK\Infrastructure\Config\Config $config
 * @var Mercurius\Memory\App\Consolidation $consolidation
 * @var Mercurius\Memory\App\Aliases $memoryAliases
 * @var OpenCCK\App\Agent\ContextMemory|null $contextMemory
 * @var OpenCCK\Infrastructure\Repository\PostgresConversationRepository $conversations
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var non-falsy-string|null $digestTime
 * @var OpenCCK\Infrastructure\Media\DocsService $docsService
 * @var OpenCCK\Infrastructure\Repository\PostgresEvalScoreRepository $evalScores
 * @var OpenCCK\Infrastructure\Repository\PostgresExperienceRepository $experience
 * @var OpenCCK\App\Service\ForumTopics $forumTopics
 * @var OpenCCK\Infrastructure\Repository\PostgresGroupRepository $groupRepository
 * @var OpenCCK\App\Agent\HostKeyCards $hostKeyCards
 * @var bool $importFailed
 * @var array{dir: string, commit: string}|null $imported
 * @var OpenCCK\Infrastructure\Repository\PostgresImprovementRepository $improvementRepository
 * @var OpenCCK\Infrastructure\Repository\PostgresJobRepository $jobRepository
 * @var OpenCCK\App\Job\JobScheduler $jobScheduler
 * @var OpenCCK\Infrastructure\Llm\ProviderRouter $llm
 * @var OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository $llmCalls
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var OpenCCK\App\Mcp\McpManager $mcpManager
 * @var OpenCCK\Infrastructure\Repository\PostgresMcpServerRepository $mcpServers
 * @var OpenCCK\Infrastructure\Media\MediaModels $mediaModels
 * @var OpenCCK\App\Agent\Media\MediaPrefs $mediaPrefs
 * @var OpenCCK\Infrastructure\Config\MediaSettings $mediaSettings
 * @var OpenCCK\App\Agent\MemoryCards $memoryCards
 * @var Mercurius\Memory\App\MemoryContext $memoryContext
 * @var Mercurius\Memory\Infrastructure\PostgresMemoryStore $memoryStore
 * @var Amp\Http\Client\HttpClient $modelHttp
 * @var OpenCCK\App\Agent\Model\ModelRoles $modelRoles
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var DateTimeZone $ownerZone
 * @var OpenCCK\App\Peer\PeerNetwork $peerNetwork
 * @var OpenCCK\Infrastructure\Repository\PostgresPeerRepository $peerRepository
 * @var OpenCCK\App\Service\PolicyService $policies
 * @var array{host: string, port: int<1, 65535>, user: string, password: string, name: string} $postgresAccess
 * @var OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository $principals
 * @var OpenCCK\Infrastructure\Repository\PostgresProjectRepository $projectRepository
 * @var OpenCCK\App\Service\ProjectTransfers $projectTransfers
 * @var OpenCCK\App\Improve\PromptNotes $promptNotes
 * @var Mercurius\Memory\App\Proposals $proposals
 * @var Mercurius\Memory\App\Recall $recall
 * @var OpenCCK\App\Settings\Restarter $restarter
 * @var OpenCCK\App\Service\RoleCatalog $roleCatalog
 * @var OpenCCK\Infrastructure\Repository\PostgresRunMessageRepository $runMessages
 * @var OpenCCK\Infrastructure\Repository\PostgresRunRepository $runs
 * @var OpenCCK\Infrastructure\Sandbox\HttpSandboxClient|null $sandbox
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\Infrastructure\Repository\PostgresServerRepository $serverRepository
 * @var OpenCCK\App\Settings\SettingsService $settingsService
 * @var OpenCCK\App\Skill\SkillImporter $skillImporter
 * @var OpenCCK\App\Skill\SkillLibrary $skillLibrary
 * @var OpenCCK\Infrastructure\Repository\PostgresProjectRepository $skillProjects
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var Mercurius\Messenger\Telegram\TelegramApi $telegramApi
 * @var Mercurius\Memory\Domain\TextAnalyzer $textAnalyzer
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\Infrastructure\Repository\PostgresTraceRepository $traces
 * @var OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository $transcript
 * @var OpenCCK\App\Vault\Vault $vault
 * @var non-empty-string $version
 */

$dailyDigest = new DailyDigest($projectRepository, $jobRepository, new PostgresDigestJournal($db), $jobScheduler, $channel, $ownerDirectory, $digestTime, $logger);
// spec: brief/operations.md#память-в-сводке-и-квитанция-извлечения — the summary counts the memory proposals waiting for the owner
$dailyDigest->useMemory($memoryStore);
$dailyDigest->useAudit($commandAudit);
$tools->register(new MemorySearchTool($recall, $audience));
$tools->register(new \OpenCCK\App\Agent\Tool\Memory\MemoryFeedbackTool($memoryStore, $audience));
// spec: brief/memory.md#словари — the agent fills the owner's table of words that mean one thing
$tools->register(new \OpenCCK\App\Agent\Tool\Memory\MemoryAliasTool($memoryAliases));
// spec: brief/memory.md#файл-памяти-места — the MEMORY.md of this place or the person's own
if ($contextMemory !== null) {
    $tools->register(new \OpenCCK\App\Agent\Tool\Memory\MemoryFileTool($contextMemory, $conversations, $audience));
}
$tools->register(new MemoryProposeTool($proposals, $memoryCards, $audience));
$tools->register(new HistorySearchTool(new PostgresEpisodeRepository($db), $audience));
$tools->register(new ExperienceSearchTool($experience, $audience));
$background->useScheduler($scheduler);
$approvals->onDecision($scheduler->resumeRun(...));
$visitors = new VisitorService($principals, $channel, $ownerDirectory, $logger);
// spec: brief/channels.md#группа — groups approved by the owner; the bot's name finds mentions
try {
    $me = $telegramApi->getMe();
    $botUsername = isset($me['username']) && is_string($me['username']) ? $me['username'] : null;
} catch (Throwable $e) {
    $logger->warning('Bot name is not known: mentions in groups are not seen', ['error' => $e::class]);
    $botUsername = null;
}
$groupGate = new \OpenCCK\App\Service\GroupGate(
    $groupRepository,
    $principals,
    $channel,
    $ownerDirectory,
    explode(':', $telegram->token(), 2)[0],
    $botUsername,
    $logger,
    $forumTopics,
    $runMessages,
    $visitors,
    new \OpenCCK\Infrastructure\Repository\PostgresGroupTalkRepository($db),
    // spec: brief/channels.md#группа — tokens the runs of a group spent since midnight UTC
    static function (string $channel, string $chatId) use ($db): int {
        $row = $db->execute(
            "SELECT coalesce(sum(r.tokens_used), 0)::bigint AS spent FROM runs r JOIN conversations c ON c.id = r.conversation_id
             WHERE c.channel = ? AND c.chat_id = ? AND r.created_at >= date_trunc('day', now())",
            [$channel, $chatId],
        )->fetchRow();
        $spent = $row['spent'] ?? 0;
        return is_int($spent) ? $spent : 0;
    },
    is_numeric($groupFreeTokens = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'GROUP_FREE_DAILY_TOKENS')) ? (int) $groupFreeTokens : 200000,
);
// spec: brief/channels.md#права-запуска-в-группе — a run in a group gets no more rights than the weakest one who spoke there
$scheduler->useGroupSpeakers($groupGate->speakers(...));
// spec: brief/channels.md#группа — other bots are heard in groups (as visitors, by mention), the guard ends their loops;
// participants name the bots and visitors not to hear
$groupGate->useBots(new \OpenCCK\App\Service\BotLoopGuard());
$groupIgnores = new \OpenCCK\Infrastructure\Repository\PostgresGroupIgnoreRepository($db);
$groupGate->useIgnores($groupIgnores);
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\GroupIgnoreTool($groupIgnores, $conversations, $groupRepository));
// spec: brief/channels.md#личные-сообщения-участникам — personal messages to participants, their answers come back to who asked
$messageRelays = new \OpenCCK\Infrastructure\Repository\PostgresMessageRelayRepository($db);
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\SendMessageTool($principals, $channel, $messageRelays, TelegramChannel::NAME, $sandbox, $peerNetwork));
// spec: brief/operations.md#второй-канал-websocket-api — keys of participants for the WebSocket API
$apiKeys = new \OpenCCK\App\Api\ApiKeys(new \OpenCCK\Infrastructure\Repository\PostgresApiKeyRepository($db), $principals);
$chat = new ChatService(
    new AccessService($principals, TelegramChannel::NAME, $telegram->ownerId, $visitors),
    $conversations,
    $scheduler,
    $channel,
    $approvals,
    new TraceReports(new PostgresTraceReportRepository($db), $traces, $peerRepository, static fn(int $runId): bool => $ownerDirectory->isFormerOwner($runs->find($runId)?->principalId)),
    $logger,
    new MemoryCommands($memoryStore, $proposals, $memoryContext, $consolidation, $memoryAliases),
    new ProjectCommands($projectRepository),
    new ServerCommands($vault, $serverRepository, $commandAudit),
    new SkillCommands($skillLibrary, static fn(string $channel, string $chatId, string $thread): ?\OpenCCK\Domain\Project\Project => $skillProjects->active($channel, $chatId, $thread), $skillImporter, $checkpoints),
    new McpCommands($mcpManager, $mcpServers),
    new JobCommands($jobRepository, $jobScheduler, $vault, $dailyDigest, $config->appUrl),
    // spec: brief/operations.md#как-сделано-поставка — /doctor checks the installation
    new \OpenCCK\App\Service\Doctor($_ENV, $db, PATH_ROOT . '/migrations', PATH_ROOT . '/storage', HttpClients::create(), $commandAudit, $settingsService->unknown(...)),
    new \OpenCCK\App\Service\SettingsCommands($settingsService, $restarter),
    new \OpenCCK\App\Service\PeerCommands($peerNetwork, $peerRepository),
    $groupGate,
    explode(':', $telegram->token(), 2)[0],
    $runMessages,
    // spec: brief/internet.md#приём — files of messages go into the sandbox and are described to the agent; the files of
    // an answer to the agent's question go to the one who asked
    new \OpenCCK\App\Service\RelayService($messageRelays, $principals, $conversations, $scheduler, $channel, $logger, $attachmentIntake = new \OpenCCK\App\Agent\Media\AttachmentIntake($channel, $sandbox, $mediaSettings, $ownerZone, logger: $logger, maxFile: $telegram->fileLimit, models: $mediaModels, prefs: $mediaPrefs, docs: $docsService), $policies),
    $attachmentIntake,
    $policies,
    new \OpenCCK\App\Service\ApiKeyCommands($apiKeys, $principals),
    new \OpenCCK\App\Service\PromptCommands($promptNotes, $evalScores),
);
// spec: brief/operations.md#расход-что-знает-агент — spend by topics, projects, participants and periods of the owner's days
$tools->register(new \OpenCCK\App\Agent\Tool\Self\SpendingTool(new TraceReports(new PostgresTraceReportRepository($db), $traces), $runs, $runMessages, $conversations, $principals, $ownerZone->getName()));
$tools->register(new \OpenCCK\App\Agent\Tool\Self\SettingsTool($settingsService, $restarter));
$apiOwner = $principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null);
$evalJudge = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'EVAL_JUDGE_MODEL') ?? '';
$evalRuns = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'EVAL_RUNS');
// spec: brief/self-improvement.md#границы — the judge may be of any provider: jev:<model> is the judging service Jev
$jevKey = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'JEV_API_KEY');
$jevJudge = str_starts_with($evalJudge, 'jev:') && $jevKey !== null
    ? new \OpenCCK\Infrastructure\Eval\JevJudge($modelHttp, $jevKey, substr($evalJudge, 4) === '' ? 'jev-latest' : substr($evalJudge, 4))
    : null;
$promptGate = new \OpenCCK\App\Improve\PromptGate(
    new \OpenCCK\App\Eval\EvalRunner($llm, $evalJudge === '' || $jevJudge !== null ? null : $llm, $evalJudge === '' || $jevJudge !== null ? null : $evalJudge, $runs, $transcript, $conversations, $llmCalls, $apiOwner, $jevJudge),
    \OpenCCK\App\Eval\EvalTask::load(PATH_ROOT . '/src/App/Eval/tasks.json'),
    $llmSettings->model,
    $evalRuns !== null && ctype_digit($evalRuns) ? (int) $evalRuns : 3,
    $evalJudge !== '',
);
// spec: brief/self-improvement.md#самоулучшение — scores of the set by category for each version of the notes
$promptGate->useScores($evalScores);
$improvements = new \OpenCCK\App\Improve\Improvements(
    $improvementRepository,
    $promptNotes,
    $channel,
    $ownerDirectory,
    // spec: brief/self-improvement.md#ворота-изменения-промпта — the owner may switch off the check of a prompt change by the set
    \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'IMPROVE_GATE') === '0'
        ? static fn(string $current, string $candidate): \OpenCCK\App\Improve\GateVerdict => new \OpenCCK\App\Improve\GateVerdict(true, \Mercurius\I18n\t('improve.gate_off'))
        : $promptGate(...),
    static fn(string $key, string $value): string => $settingsService->set($apiOwner, $key, $value),
    $logger,
    background: true,
    // spec: brief/self-improvement.md#самоизменение-с-разрешением-навсегда — self-modification may be allowed for good, kept as a grant
    granted: static fn(string $key): bool => $approvalStore->hasGrant($apiOwner->id, $key),
    grant: static function (string $key) use ($approvalStore, $apiOwner): void {
        $approvalStore->addGrant($apiOwner->id, $key);
    },
);
// spec: brief/self-improvement.md#самоулучшение — a proposal a restart left in checking goes back to the owner
\Revolt\EventLoop::defer(static function () use ($improvements, $logger): void {
    try {
        $improvements->recover();
    } catch (\Throwable $e) {
        $logger->warning('Proposals left in checking were not recovered', ['error' => $e::class]);
    }
});
$tools->register(new \OpenCCK\App\Agent\Tool\Self\ImprovementProposeTool($improvements));
$chat->useImprovements($improvements);
// spec: brief/self-improvement.md#самоулучшение — /models: the roles, their history and a rollback
$chat->useModelRoles($modelRoles);
// spec: brief/operations.md#экспорт-и-импорт-агента — /export: the archive of the agent sealed by the owner's password
$exportAgent = static function (string $password, \Amp\Cancellation $cancellation) use ($postgresAccess, $sandbox, $version): string {
        $example = @file_get_contents(PATH_ROOT . '/.env.example');
        $export = new \OpenCCK\App\Export\AgentExport(
            \OpenCCK\App\Export\ProcessRunner::closure($cancellation),
            $postgresAccess,
            PATH_ROOT . '/storage',
            \OpenCCK\App\Export\AgentExport::env($_ENV, is_string($example) ? $example : ''),
            $version,
            $sandbox === null ? null : static function (string $to, \Amp\Cancellation $c) use ($sandbox): void {
                $sandbox->archive($to, $c);
            },
        );
        return $export->export($password, $cancellation);
};
// spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — /codex: sign in to the owner's ChatGPT subscription
if ($codexAccount !== null) {
    $chat->useCodex(new \OpenCCK\App\Service\CodexCommands($codexAccount, $channel, $logger));
}
$chat->useExport(new \OpenCCK\App\Export\ExportCommands($exportAgent, $channel, $logger));
// spec: brief/operations.md#экспорт-и-импорт-агента — /import: the archive checked, the current state sealed, then a restart
$chat->useImport(new \OpenCCK\App\Export\ImportCommands(
    $agentImport,
    $exportAgent,
    static fn() => $restarter->request(),
    $channel,
    $logger,
    PATH_ROOT . '/storage/import',
    // spec: brief/operations.md#экспорт-и-импорт-агента — /import memory, /import skills: a part of the archive, nothing replaced
    new \OpenCCK\App\Export\PartialImport(
        \OpenCCK\App\Export\ProcessRunner::closure(),
        $memoryStore,
        $textAnalyzer,
        $sandbox,
        static function (int $id) use ($principals, $policies): ?\OpenCCK\Domain\Agent\SandboxLimits {
            $principal = $principals->find($id);
            return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
        },
    ),
));
// after an import: the working folders back through the executor, and the owner told how it went
if ($imported !== null || $importFailed) {
    \Amp\async(static function () use ($imported, $sandbox, $channel, $ownerDirectory, $logger): void {
        \Amp\delay(5);
        if ($imported === null) {
            $channel->send(new OutgoingMessage($ownerDirectory->chatId(), \Mercurius\I18n\t('import.start_failed')));
            return;
        }
        $workspace = $imported['dir'] . '/workspace.tar.gz';
        $state = \Mercurius\I18n\t('import.workspace_none');
        if (is_file($workspace) && filesize($workspace) > 0) {
            $state = \Mercurius\I18n\t('import.workspace_failed');
            for ($try = 0; $sandbox !== null && $try < 12; $try++) {
                try {
                    $sandbox->unarchive($workspace, new \Amp\TimeoutCancellation(3600));
                    $state = \Mercurius\I18n\t('import.workspace_back');
                    break;
                } catch (\Throwable $e) {
                    $logger->warning('Working folders not restored yet', ['error' => $e::class, 'try' => $try]);
                    \Amp\delay(10);
                }
            }
        }
        if ($state !== \Mercurius\I18n\t('import.workspace_failed')) {
            \OpenCCK\App\Export\AgentImport::remove($imported['dir']);
        }
        $channel->send(new OutgoingMessage($ownerDirectory->chatId(), sprintf(\Mercurius\I18n\t('import.done'), $imported['commit'] === '' ? '?' : substr($imported['commit'], 0, 12), $state)));
    })->ignore();
}
// spec: brief/participants.md#несколько-пользователей — the owner passes ownership on by /owner and a card
$ownership = new \OpenCCK\App\Service\OwnershipTransfer($principals, $channel, $ownerDirectory, $logger);
$chat->useOwnership($ownership);
$chat->useRoles($roleCatalog);
// spec: brief/participants.md#свои-роли — a holder of reports does not see the private chats of others
$chat->useRunVisibility(static function (int $runId, Principal $viewer) use ($runs, $conversations): bool {
    $run = $runs->find($runId);
    $meta = $run === null ? null : $conversations->meta($run->conversationId);
    return $run !== null && $meta !== null && ($run->principalId === $viewer->id || str_starts_with($meta->chatId, '-'));
});
if (!(new \OpenCCK\App\Job\Initiative($jobRepository, $ownerZone, title: 'self-review', promptKey: 'job.self_review.prompt'))->sync($apiOwner, TelegramChannel::NAME, $ownerDirectory->chatId(), \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'SELF_REVIEW_SCHEDULE') ?? '')) {
    $logger->warning('SELF_REVIEW_SCHEDULE is not a schedule (daily:HH:MM, every:<minutes>, weekly:...)');
}
$presses = new CallbackRouter($channel, $ownerDirectory, [\OpenCCK\App\Improve\Improvements::PREFIX => $improvements, 'ap' => $approvals, 'pg' => $approvals, 'pv' => $visitors, \OpenCCK\App\Peer\AgentRequests::PREFIX => $agentRequests, 'gr' => $groupGate, MemoryCards::PREFIX => $memoryCards, MemoryCards::FORGET => $memoryCards, HostKeyCards::PREFIX => $hostKeyCards, \OpenCCK\App\Service\OwnershipTransfer::PREFIX => $ownership, \OpenCCK\App\Service\ProjectTransfers::PREFIX => $projectTransfers], ['p' . $apiOwner->id], [MemoryCards::PREFIX, 'pv', \OpenCCK\App\Service\ProjectTransfers::PREFIX]);

$botId = (int) explode(':', $telegram->token(), 2)[0];
$parser = new UpdateParser($botId, allowBots: true);
// spec: brief/channels.md#канал-необязательные-возможности-и-разрезанные-тексты — a text Telegram split at 4096 characters is glued back (2 s, as hermes)
$dispatcher = new ChatDispatcher($chat, $logger, callbacks: $presses, memberships: new \OpenCCK\App\Service\MembershipRouter([$groupGate, $channelRegistry]), posts: $channelRegistry, splitDelay: 2.0);
// spec: brief/channels.md#канал-необязательные-возможности-и-разрезанные-тексты — updates taken are kept a day in the database:
// a redelivery after a restart is not run twice
$seenUpdates = new \OpenCCK\Infrastructure\Repository\PostgresSeenUpdates($db);
$deduplicator = new Deduplicator(firstSeen: $seenUpdates->fresh(...));
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(3600, static function () use ($seenUpdates): void {
    try {
        $seenUpdates->prune();
    } catch (\Throwable) {
    }
}));
