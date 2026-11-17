<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\ModelCatalog\OpenAICatalog;
use Mercurius\Llm\ModelCatalog\OpenRouterCatalog;
use Mercurius\Llm\OpenAI\OpenAIClient;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\RetryingClient;
use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\Deduplicator;
use Mercurius\Messenger\Telegram\LongPolling;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Telegram\WebhookProcessor;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Agent\Context\ContextCompactor;
use OpenCCK\App\Agent\Delegation\BackgroundDelegations;
use OpenCCK\App\Agent\Delegation\DelegateTaskTool;
use OpenCCK\App\Agent\Delegation\WorkerReportTool;
use OpenCCK\App\Agent\Delegation\WorkerRunner;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelSelector;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Model\RequestClassifier;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\SpendGuard;
use OpenCCK\App\Agent\Tool\AddressGuard;
use OpenCCK\App\Agent\Tool\HttpRequestTool;
use OpenCCK\App\Agent\Tool\ListFilesTool;
use OpenCCK\App\Agent\Tool\ReadFileTool;
use OpenCCK\App\Agent\Tool\RunCommandTool;
use OpenCCK\App\Agent\Tool\SetModelRoleTool;
use OpenCCK\App\Agent\Trace\Tracer;
use OpenCCK\App\Agent\Tool\SetParticipantPolicyTool;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\WriteFileTool;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\HealthService;
use OpenCCK\App\Service\MetricsText;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\App\Service\TraceReports;
use OpenCCK\App\Service\VisitorService;
use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\API\Handler\MetricsHandler;
use OpenCCK\Infrastructure\API\Handler\TelegramWebhookHandler;
use OpenCCK\Infrastructure\API\Server;
use OpenCCK\Infrastructure\Config\AgentSettings;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Config\SandboxSettings;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use OpenCCK\Infrastructure\Config\TraceSettings;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\DatabaseHealthCheck;
use OpenCCK\Infrastructure\Database\Migrator;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Logger\LoggerFactory;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Infrastructure\Llm\ProviderRouter;
use OpenCCK\Infrastructure\Module\AgentModule;
use OpenCCK\Infrastructure\Module\ModelCatalogModule;
use OpenCCK\Infrastructure\Module\MemoryConsolidationModule;
use OpenCCK\Infrastructure\Module\TraceRetentionModule;
use OpenCCK\Infrastructure\Module\TelegramModule;
use OpenCCK\Infrastructure\Repository\PostgresApprovalRepository;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\HostKeyCards;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\MemoryCards;
use OpenCCK\App\Agent\Tool\MemoryProposeTool;
use OpenCCK\App\Agent\Tool\ExperienceSearchTool;
use OpenCCK\App\Agent\Tool\HistorySearchTool;
use OpenCCK\App\Agent\Tool\MemorySearchTool;
use OpenCCK\App\Agent\Tool\SshRunTool;
use OpenCCK\Infrastructure\API\Handler\JobHookHandler;
use OpenCCK\Infrastructure\Repository\PostgresDigestJournal;
use OpenCCK\App\Service\JobCommands;
use OpenCCK\App\Job\HookReceiver;
use OpenCCK\App\Job\DailyDigest;
use OpenCCK\Infrastructure\Module\SchedulerModule;
use OpenCCK\Infrastructure\Repository\PostgresJobRepository;
use OpenCCK\App\Agent\Tool\JobManageTool;
use OpenCCK\App\Agent\Tool\JobCreateTool;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Infrastructure\Repository\PostgresMcpServerRepository;
use OpenCCK\Infrastructure\Mcp\HttpMcpClient;
use OpenCCK\App\Service\McpCommands;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\Infrastructure\Repository\PostgresSkillRepository;
use OpenCCK\App\Service\SkillCommands;
use OpenCCK\App\Skill\SkillCatalog;
use OpenCCK\App\Agent\Tool\SkillProposeTool;
use OpenCCK\App\Agent\Tool\SkillViewTool;
use OpenCCK\App\Agent\SkillCards;
use OpenCCK\Infrastructure\Browser\BrowserClient;
use OpenCCK\App\Agent\Tool\BrowserTool;
use OpenCCK\App\Web\TextExtractor;
use OpenCCK\App\Agent\Tool\WebSearchTool;
use OpenCCK\App\Agent\Tool\WebReadTool;
use OpenCCK\App\Agent\Tool\OpenRouterByokTool;
use OpenCCK\App\Agent\Tool\TcpProbeTool;
use OpenCCK\App\Agent\Tool\FileWriteTool;
use OpenCCK\App\Agent\Tool\SqlQueryTool;
use OpenCCK\App\Agent\Tool\ServerAddTool;
use OpenCCK\App\Agent\Tool\ServersTool;
use OpenCCK\App\Agent\Tool\ProjectEditTool;
use OpenCCK\App\Agent\Tool\ProjectTool;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Infrastructure\Config\MemorySettings;
use OpenCCK\Infrastructure\Config\VaultSettings;
use OpenCCK\App\Service\MemoryCommands;
use OpenCCK\CommandPolicy\CommandClassifier;
use OpenCCK\App\Vault\Vault;
use OpenCCK\App\Service\ServerCommands;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\App\Service\ProjectCommands;
use OpenCCK\Memory\App\Consolidation;
use OpenCCK\Memory\App\MemoryContext;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\Domain\ContentScreen;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\App\Recall;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Memory\Infrastructure\PostgresConsolidationJournal;
use OpenCCK\Memory\Infrastructure\PostgresMemoryStore;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository;
use OpenCCK\Infrastructure\Repository\PostgresMetricsRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelRoleRepository;
use OpenCCK\Infrastructure\Repository\PostgresPolicyRepository;
use OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository;
use OpenCCK\Infrastructure\Repository\PostgresDelegationRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelReliabilityRepository;
use OpenCCK\Infrastructure\Repository\PostgresRoutingDecisionRepository;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceReportRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceRepository;
use OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository;
use OpenCCK\Infrastructure\Ssh\AmpSshShell;
use OpenCCK\Infrastructure\Repository\PostgresCommandAuditRepository;
use OpenCCK\Infrastructure\Repository\PostgresServerRepository;
use OpenCCK\Infrastructure\Repository\PostgresSecretRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Infrastructure\Repository\PostgresEpisodeRepository;
use OpenCCK\Infrastructure\Repository\PostgresExperienceRepository;
use OpenCCK\Infrastructure\Sandbox\HttpSandboxClient;
use Revolt\EventLoop;

use function Mercurius\I18n\t;

require __DIR__ . '/vendor/autoload.php';

const PATH_ROOT = __DIR__;

Dotenv::createImmutable(PATH_ROOT)->safeLoad();

// What the start needs before the database: the process, Telegram, the vault key. The rest may come from the
// settings the owner keeps in the dialog (over .env).
try {
    $config = Config::fromEnv($_ENV);
    $telegram = TelegramSettings::fromEnv($_ENV, $config->appUrl);
    $vaultSettings = VaultSettings::fromEnv($_ENV);
} catch (ConfigException $e) {
    fwrite(STDERR, json_encode(['level' => 'CRITICAL', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(78); // EX_CONFIG
}

date_default_timezone_set($config->timezone);
$logger = LoggerFactory::create($config->logLevel);
// An error escaping a fiber is logged instead of stopping the whole process.
EventLoop::setErrorHandler(static fn(Throwable $e) => $logger->error('Unhandled error in the event loop', ['error' => $e::class]));
$version = getenv('APP_VERSION');
$version = is_string($version) && $version !== '' ? $version : 'dev';

$db = Database::pool($config->postgres);
$applied = (new Migrator($db, $logger))->migrate(Migrator::loadDirectory(PATH_ROOT . '/migrations'));
$logger->info('Migrations checked', ['applied' => $applied]);

// spec: BRIEF.md#настройки-агента — settings from the dialog over .env; a stored value is checked by the start checks
// when it is kept, and once more here: if they fail, the agent starts on .env and says why.
$principals = new PostgresPrincipalRepository($db);
$vault = new Vault(new PostgresSecretRepository($db), $vaultSettings->key());
$restarter = new \OpenCCK\App\Settings\Restarter();
$validateSettings = static function (array $env): ?string {
    try {
        $checked = Config::fromEnv($env);
        TelegramSettings::fromEnv($env, $checked->appUrl);
        LlmSettings::fromEnv($env);
        AgentSettings::fromEnv($env);
        SandboxSettings::fromEnv($env);
        TraceSettings::fromEnv($env);
        MemorySettings::fromEnv($env);
        VaultSettings::fromEnv($env);
        \OpenCCK\Infrastructure\Config\SearchSettings::build($env, HttpClients::create(), \OpenCCK\Infrastructure\Config\Env::raw($env, 'OPENROUTER_API_KEY'));
        try {
            new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($env, 'OWNER_TIMEZONE') ?? '+03:00');
        } catch (Throwable) {
            return 'OWNER_TIMEZONE: неизвестный часовой пояс — пример: Europe/Moscow или +03:00';
        }
        $quiet = \OpenCCK\Infrastructure\Config\Env::raw($env, 'QUIET_HOURS');
        if ($quiet !== null && preg_match('~^([01]?\d|2[0-3])-([01]?\d|2[0-3])$~', $quiet) !== 1) {
            return 'QUIET_HOURS: «с-до» в часах, например 23-8';
        }
        $digest = \OpenCCK\Infrastructure\Config\Env::raw($env, 'DIGEST_TIME');
        if ($digest !== null && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $digest) !== 1) {
            return 'DIGEST_TIME: ЧЧ:ММ, например 09:00';
        }
    } catch (ConfigException $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        return $e::class;
    }
    return null;
};
$settingsService = new \OpenCCK\App\Settings\SettingsService(
    new \OpenCCK\Infrastructure\Repository\PostgresSettingRepository($db),
    $vault,
    $_ENV,
    $validateSettings,
    static fn(): ?Principal => $principals->findByIdentity(TelegramChannel::NAME, $telegram->ownerId),
);
$overlay = $settingsService->overlay();
$settingsProblem = $validateSettings($overlay);
if ($settingsProblem === null) {
    $_ENV = $overlay;
} else {
    $logger->error('Stored settings ignored: the agent starts on .env', ['problem' => $settingsProblem]);
}
// spec: BRIEF.md#язык-агента — the language of everything the agent writes itself; Russian by default
/** @var mixed $agentLanguage */
$agentLanguage = $_ENV['AGENT_LANGUAGE'] ?? 'ru';
\Mercurius\I18n\Lang::use(new \Mercurius\I18n\CatalogTranslator(is_string($agentLanguage) ? $agentLanguage : 'ru', \Mercurius\I18n\Catalogs::bundled()));
$logger->info('Agent language', ['language' => \Mercurius\I18n\Lang::current()->language()]);
try {
    $llmSettings = LlmSettings::fromEnv($_ENV);
    $agentSettings = AgentSettings::fromEnv($_ENV);
    $sandboxSettings = SandboxSettings::fromEnv($_ENV);
    $traceSettings = TraceSettings::fromEnv($_ENV);
    $memorySettings = MemorySettings::fromEnv($_ENV);
} catch (ConfigException $e) {
    $logger->critical('Configuration error', ['message' => $e->getMessage()]);
    exit(78); // EX_CONFIG
}

// Models: one client per provider with a key, chosen by the model's prefix; both go through VLESS when VLESS_URI is set.
// spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai
$modelHttp = HttpClients::create($llmSettings->routeUri(), $logger);
$onRetry = static fn(LlmException $e, int $attempt, float $delay): null => $logger->warning(
    'Retrying model call',
    ['kind' => $e->kind->value, 'attempt' => $attempt, 'delay' => $delay],
);
$clients = [];
$catalogSources = [];
$openRouterKey = $llmSettings->openRouterKey();
if ($openRouterKey !== null) {
    $clients[ModelRef::OPENROUTER] = new RetryingClient(new OpenRouterClient($modelHttp, $openRouterKey, appName: 'Mercurius', appUrl: $config->appUrl), onRetry: $onRetry);
    $catalogSources[ModelRef::OPENROUTER] = (new OpenRouterCatalog($modelHttp, $openRouterKey))->fetch(...);
}
$openAiKey = $llmSettings->openAiKey();
if ($openAiKey !== null) {
    $clients[ModelRef::OPENAI] = new RetryingClient(new OpenAIClient($modelHttp, $openAiKey), onRetry: $onRetry);
    // The OpenAI list has ids only: the public OpenRouter catalog describes them.
    $catalogSources[ModelRef::OPENAI] = (new OpenAICatalog($modelHttp, $openAiKey, new OpenRouterCatalog($modelHttp)))->fetch(...);
}
// Model catalog per provider, model roles, the choice of a conversation's model.
$modelCatalog = new PostgresModelCatalogRepository($db);
$llm = new ProviderRouter($clients, $modelCatalog);
$modelRoles = new ModelRoles(new PostgresModelRoleRepository($db), $modelCatalog, $llmSettings->providers());
$modelRoles->seed();
$routingDecisions = new PostgresRoutingDecisionRepository($db);
$modelRouter = new ModelRouter(
    new RequestClassifier($llm, static fn(): string => (string) $modelRoles->model(ModelRole::CHEAP), $logger),
    $modelRoles,
    $modelCatalog,
    $routingDecisions,
    $llmSettings->contextWindow,
    $logger,
);
$logger->info('Model route', ['route' => $llmSettings->routeUri() === null ? 'direct' : 'vless', 'providers' => $llmSettings->providers(), 'model' => $llmSettings->model]);

// Channel: Telegram.
$telegramApi = new TelegramApi($telegram->token(), HttpClients::create());
$channel = new TelegramChannel($telegramApi, logger: $logger);

// Agent: runs, tools, scheduler.
$conversations = new PostgresConversationRepository($db);
$runs = new PostgresRunRepository($db);
$runMessages = new \OpenCCK\Infrastructure\Repository\PostgresRunMessageRepository($db);
$deliveryRepository = new \OpenCCK\Infrastructure\Repository\PostgresDeliveryRepository($db);
$transcript = new PostgresTranscriptRepository($db);
$policies = new PolicyService(new PostgresPolicyRepository($db), $agentSettings->ownerLimits(), $sandboxSettings->ownerLimits());
$addressGuard = new AddressGuard();
$tools = new ToolRegistry();
// spec: BRIEF.md#подтверждения-и-запреты — a host the owner allowed is dialled through a guard that lets it alone in
$guardedClient = static fn(AddressGuard $guard): \Amp\Http\Client\HttpClient => HttpClients::guarded($guard);
$tools->register(new HttpRequestTool(HttpClients::guarded($addressGuard), $addressGuard, $vault, $guardedClient));
// spec: BRIEF.md#интернет-и-браузер — the browser service, when its token is set
$browserToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_TOKEN');
if ($browserToken !== null) {
    $tools->register(new BrowserTool(
        new BrowserClient(HttpClients::create(), \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_URL') ?? 'http://browser:3000', $browserToken),
        $channel,
        $telegram->ownerId,
        $addressGuard,
    ));
}
// spec: BRIEF.md#интернет-и-браузер — pages as text; search by the model's web search on OpenRouter
$tools->register(new WebReadTool(HttpClients::guarded($addressGuard), $addressGuard, new TextExtractor(), $guardedClient));
// spec: BRIEF.md#решения-владельца-2026-11-17 — search providers in the order of SEARCH_PROVIDERS
[$webSearch, $searchSkipped, $searchEach] = \OpenCCK\Infrastructure\Config\SearchSettings::build($_ENV, $modelHttp, $llmSettings->openRouterKey());
if ($searchSkipped !== []) {
    $logger->warning('Search providers without settings are skipped', ['providers' => $searchSkipped]);
}
if ($webSearch !== null) {
    $tools->register(new WebSearchTool($webSearch, $searchEach, array_map('\Mercurius\I18n\t', \OpenCCK\Infrastructure\Config\SearchSettings::ABOUT)));
}
$tools->register(new SetParticipantPolicyTool($principals, $policies));
$tools->register(new \OpenCCK\App\Agent\Tool\ParticipantsTool($principals, $policies));
$tools->register(new SetModelRoleTool($modelRoles));
// Sandbox: commands and files of the agent; off without SANDBOX_TOKEN.
$sandboxToken = $sandboxSettings->token();
$sandbox = $sandboxToken === null ? null : new HttpSandboxClient(HttpClients::create(), $sandboxSettings->url, $sandboxToken);
if ($sandbox !== null) {
    $tools->register(new ReadFileTool($sandbox));
    $tools->register(new ListFilesTool($sandbox));
    $tools->register(new WriteFileTool($sandbox));
    $tools->register(new RunCommandTool($sandbox, $agentSettings->toolTimeout));
}
$logger->info('Sandbox', ['enabled' => $sandbox !== null]);
// Tracing: steps of every run, masked payloads.
$traces = new PostgresTraceRepository($db);
$tracer = new Tracer($traces, $modelCatalog, $logger);
// Approvals: cards in the owner's private chat (in Telegram its id is the owner's user id).
$approvalStore = new PostgresApprovalRepository($db);
$approvals = new ApprovalService($approvalStore, $principals, $channel, $telegram->ownerId, $agentSettings->approvalTimeout, $logger, $tracer);
$llmCalls = new PostgresLlmCallRepository($db);
$loop = new AgentLoop(
    $llm,
    $runs,
    $transcript,
    $conversations,
    $llmCalls,
    $tools,
    new ToolExecutor(logger: $logger, sandbox: $sandbox),
    $llmSettings->maxTokens,
    $llmSettings->reasoningEffort === 'none' ? null : $llmSettings->reasoningEffort,
    $llmSettings->historyLimit,
    $logger,
    approvals: $approvalStore,
    gate: $approvals,
    // Context compaction: the window and the cheap model come from the settings until the model catalog.
    compactor: new ContextCompactor(
        $llm,
        $transcript,
        $conversations,
        $runs,
        $llmCalls,
        $llmSettings->contextWindow,
        $llmSettings->cheapModel,
        $logger,
        windowOf: $modelRouter->window(...),
        cheapModelOf: $modelRouter->cheapModel(...),
        events: $tracer,
    ),
    events: $tracer,
    fallback: new FallbackModels($modelRoles, $modelCatalog, $routingDecisions, $logger),
    spend: new SpendGuard($llmCalls, $agentSettings->dailySpendCap, $agentSettings->monthlySpendCap, $logger),
);
// Delegation: workers run through the same loop, so the tool is registered once the loop exists.
$workers = new WorkerRunner($loop, $runs, $transcript, $conversations, $tools, $modelRoles, $agentSettings->workerMaxTurns, $agentSettings->workerTokenBudget, $logger, new ModelSelector($modelCatalog, $modelRoles, $routingDecisions, $logger, new PostgresModelReliabilityRepository($db)), $agentSettings->maxWorkersPerRun);
$background = new BackgroundDelegations($workers, new PostgresDelegationRepository($db), $runs, $principals, $policies, $logger);
$tools->register(new DelegateTaskTool($workers, $background));
$tools->register(new WorkerReportTool($runs, $transcript));
$scheduler = new RunScheduler(
    $loop,
    $runs,
    $transcript,
    $conversations,
    $principals,
    $policies,
    $channel,
    $llmSettings->model,
    $logger,
    router: $modelRouter,
    events: $tracer,
    providers: $llmSettings->providers(),
    runMessages: $runMessages,
    answers: $deliveryRepository,
);
$scheduler->useApprovals($approvals);
$scheduler->useBackground($background);
$memoryStore = new PostgresMemoryStore($db);
$textAnalyzer = new TextAnalyzer();
$recall = new Recall($memoryStore, $textAnalyzer, null, $memorySettings->hrrWeight);
try {
    // Facts written before vectors existed get one; a handful, at start.
    $encoded = $memoryStore->encodeMissing(1000);
    if ($encoded > 0) {
        $logger->info('Memory vectors encoded', ['facts' => $encoded]);
    }
} catch (Throwable $e) {
    $logger->warning('Memory vectors were not encoded', ['error' => $e::class]);
}
$audience = new ConversationAudience($conversations);
$memoryContext = new MemoryContext($memoryStore, $recall);
$scheduler->useMemory($memoryContext, $audience);
if (!$memorySettings->hasSecret()) {
    $logger->warning('MEMORY_SECRET is not set: the key of forgotten texts comes from the bot token');
}
$proposals = new Proposals(
    $memoryStore,
    $textAnalyzer,
    new ContentScreen(static fn(string $text): bool => SecretMasker::mask($text) !== $text),
    $memorySettings->key($telegram->token()),
);
$memoryCards = new MemoryCards($proposals, $memoryStore, $channel, $telegram->ownerId, $principals, $logger);
$proposals->onForget($memoryCards->forgotten(...));
// spec: BRIEF.md#память-слои — a forgotten fact also leaves the transcripts it was recalled into
$experience = new PostgresExperienceRepository($db);
$workers->useExperience($experience);
$proposals->onForget(static function (Fact $fact) use ($transcript, $experience, $traces): void {
    $line = sprintf('[#%d] %s', $fact->id, $fact->text);
    $transcript->redact($line, sprintf('[#%d] (забыто)', $fact->id));
    $traces->redactPayloads($line, sprintf('[#%d] (забыто)', $fact->id));
    $experience->redact($fact->text, '(забыто)');
});
// spec: BRIEF.md#консолидация-dreaming — the nightly check: code only, the owner decides
$consolidation = new Consolidation(
    $memoryStore,
    $proposals,
    new PostgresConsolidationJournal($db),
    $memoryCards->show(...),
    static function (string $text) use ($channel, $telegram): void {
        $channel->send(new OutgoingMessage($telegram->ownerId, $text));
    },
);
// spec: BRIEF.md#фаза-7-по-частям — the vault, the registry of servers, SSH with a command policy and a journal
if (!$vault->enabled()) {
    $logger->warning('SECRETS_KEY is not set: the vault and SSH are off');
}
$serverRepository = new PostgresServerRepository($db);
$commandAudit = new PostgresCommandAuditRepository($db);
$hostKeyCards = new HostKeyCards($serverRepository, $channel, $telegram->ownerId);
$serverConsole = new ServerConsole(
    $serverRepository,
    $vault,
    new CommandClassifier(),
    $commandAudit,
    new AmpSshShell(),
    $hostKeyCards->ask(...),
    $logger,
    $llmSettings->routeUri(),
    $vaultSettings->confirmGroups,
);
$tools->register(new ServersTool($serverRepository));
$tools->register(new ServerAddTool($serverRepository, $vault));
$tools->register(new SshRunTool($serverConsole));
$tools->register(new SqlQueryTool($serverConsole, $vault));
$tools->register(new FileWriteTool($serverConsole, new CommandClassifier()));
$tools->register(new TcpProbeTool($addressGuard));
$tools->register(new OpenRouterByokTool($modelHttp, $vault));

// spec: BRIEF.md#скиллы-и-mcp — the catalog of skills: index in the prompt, bodies on demand, changes by the owner
$skillRepository = new PostgresSkillRepository($db);
$skillCatalog = new SkillCatalog($skillRepository);
$skillCards = new SkillCards($skillRepository, $channel, $telegram->ownerId);
$scheduler->useSkills($skillCatalog);
$tools->register(new SkillViewTool($skillCatalog));
$tools->register(new SkillProposeTool($skillCatalog, $skillCards));
// spec: BRIEF.md#скиллы-и-mcp — ordinary SKILL.md skills of other agents, through the SSRF guard
$skillHttp = HttpClients::guarded($addressGuard);
$skillImporter = new \OpenCCK\App\Skill\SkillImporter(
    [new \OpenCCK\App\Skill\GitHubSkillSource($skillHttp), new \OpenCCK\App\Skill\ArchiveSkillSource($skillHttp)],
    $skillCatalog,
    $skillRepository,
    $skillCards,
);
$tools->register(new \OpenCCK\App\Agent\Tool\SkillImportTool($skillImporter));

// spec: BRIEF.md#скиллы-и-mcp — external MCP servers over HTTP, and stdio ones in the sandbox, added and approved by the owner
$mcpServers = new PostgresMcpServerRepository($db);
/** @var McpManager|null $mcpManager the stdio client tells it when a server's tools change */
$mcpManager = null;
$stdioMcp = $sandbox === null ? null : new \OpenCCK\Infrastructure\Mcp\StdioMcpClient(
    $sandbox,
    // a server lives for days: its CPU time is capped by a day, not by the limit of one command
    new \OpenCCK\Domain\Agent\SandboxLimits($sandboxSettings->ownerLimits()->diskBytes, $sandboxSettings->ownerLimits()->processes, 86_400, $sandboxSettings->ownerLimits()->memoryBytes, $sandboxSettings->ownerLimits()->concurrentCommands),
    static function (string $server) use (&$mcpManager): void {
        $mcpManager?->toolsChanged($server);
    },
    $logger,
);
$mcpManager = new McpManager(
    $mcpServers,
    new \OpenCCK\Infrastructure\Mcp\RoutingMcpClient(new HttpMcpClient(HttpClients::create()), $stdioMcp),
    $vault,
    $tools,
    static fn(): ?Principal => $principals->findByIdentity(TelegramChannel::NAME, $telegram->ownerId),
    static function (string $text) use ($channel, $telegram): void {
        $channel->send(new OutgoingMessage($telegram->ownerId, $text));
    },
    $logger,
    stdio: $stdioMcp !== null,
);
\Revolt\EventLoop::defer($mcpManager->connectAll(...));
// spec: BRIEF.md#скиллы-и-mcp — servers down at start are tried again, connected ones are listed again for changes
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(30, static function (string $id) use ($mcpManager): void {
    \Amp\async($mcpManager->tick(...))->ignore();
}));
$tools->register(new \OpenCCK\App\Agent\Tool\McpResourcesTool($mcpManager));
// spec: BRIEF.md#скиллы-и-mcp — the built-in mail server: the mailbox from the settings goes in headers of each request
$mailHeaders = \OpenCCK\Infrastructure\Config\MailSettings::headers($_ENV);
$mailToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MAIL_MCP_TOKEN');
if ($mailHeaders !== null && $mailToken !== null) {
    $mailServer = new \OpenCCK\Domain\Mcp\McpServer(0, 'mail', \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MAIL_MCP_URL') ?? 'http://mail-mcp:8090/mcp', null, \OpenCCK\Domain\Mcp\McpServer::ACTIVE, null, 'встроенный', $mailHeaders);
    \Revolt\EventLoop::defer(static fn() => $mcpManager->connectBuiltin($mailServer, $mailToken));
}

// spec: BRIEF.md#планировщик-и-проекты — jobs by a schedule, run without the owner, reported by the scheduler
$ownerZone = new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'OWNER_TIMEZONE') ?? '+03:00');
$quietRaw = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'QUIET_HOURS') ?? '23-8';
$quietHours = preg_match('~^(\d{1,2})-(\d{1,2})$~', $quietRaw, $q) === 1 ? [(int) $q[1] % 24, (int) $q[2] % 24] : null;
$jobRepository = new PostgresJobRepository($db);
$jobTokens = max(1000, (int) (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'JOB_TOKEN_BUDGET') ?? '100000'));
$jobScheduler = new JobScheduler($jobRepository, $loop, $runs, $transcript, $conversations, $principals, $policies, $tools, $channel, $llmSettings->model, $ownerZone, $quietHours, $logger, tokenBudget: $jobTokens);
$digestTime = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'DIGEST_TIME');
$digestTime = $digestTime !== null && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $digestTime) === 1 ? $digestTime : null;
$tools->register(new JobCreateTool($jobRepository, $conversations, $jobScheduler, new ContentScreen(static fn(string $text): bool => SecretMasker::mask($text) !== $text)));
$tools->register(new JobManageTool($jobRepository, $jobScheduler));

// spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту
$projectRepository = new PostgresProjectRepository($db);
$scheduler->useProjects(new ProjectContext($projectRepository));
$tools->register(new ProjectTool($projectRepository, $conversations));
$groupRepository = new \OpenCCK\Infrastructure\Repository\PostgresGroupRepository($db);
$forumTopics = new \OpenCCK\App\Service\ForumTopics($channel, $groupRepository, $projectRepository, $logger);
$tools->register(new ProjectEditTool($projectRepository, $conversations, $ownerZone, $forumTopics));
$tools->register(new \OpenCCK\App\Agent\Tool\ForumTopicTool($channel, $conversations));
$channelRepository = new \OpenCCK\Infrastructure\Repository\PostgresChannelRepository($db);
$channelRegistry = new \OpenCCK\App\Service\ChannelRegistry($channelRepository, $channel, $telegram->ownerId, $logger);
$tools->register(new \OpenCCK\App\Agent\Tool\ChannelPostTool($channel, $channelRepository));
$scheduler->useJobRuns($jobScheduler);

// spec: BRIEF.md#сеть-агентов — A2A v1.0: neighbours connect with their own tokens; the agent gives tasks to its own
$peerRepository = new \OpenCCK\Infrastructure\Repository\PostgresPeerRepository($db);
$selfUrl = $config->appUrl === null ? null : rtrim($config->appUrl, '/');
// The entrance token comes from .env; who speaks is known by the ECDSA key of each agent (kept in the vault).
$a2aToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_TOKEN');
$agentIdentity = $vault->enabled()
    ? \OpenCCK\App\Peer\AgentIdentity::load($vault, $principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null))
    : \OpenCCK\App\Peer\AgentIdentity::generate();
// spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17 — keys the agent makes itself, the
// transport of a neighbour from its card: a WebSocket opened with the entrance token, or HTTP
$agentKeys = new \OpenCCK\App\Peer\AgentKeys($vault, static fn(): ?Principal => $principals->findByIdentity(TelegramChannel::NAME, $telegram->ownerId), $agentIdentity);
$a2aConnect = static function (string $url, string $token): \OpenCCK\App\Peer\A2aChannel {
    if (preg_match('~^wss?://~', $url) !== 1) {
        throw new \RuntimeException('not a websocket address');
    }
    $handshake = (new \Amp\Websocket\Client\WebsocketHandshake($url, ['authorization' => 'Bearer ' . $token]))->withTcpConnectTimeout(10)->withTlsHandshakeTimeout(10);
    return new \OpenCCK\Infrastructure\Peer\WebsocketA2aChannel(\Amp\Websocket\Client\connect($handshake, new \Amp\TimeoutCancellation(20)));
};
// spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17 — live connections both ways, shared by the client and
// the server; requests a neighbour sends back on a connection this agent opened are answered by the server
$a2aLinks = new \OpenCCK\App\Peer\A2aLinks();
/** @var \OpenCCK\App\Peer\A2aServer|null $a2aServer set below; the client asks it who answers a neighbour */
$a2aServer = null;
$a2aServeFor = static function (string $fingerprint) use (&$a2aServer): ?\Closure {
    return $a2aServer?->handlerFor($fingerprint);
};
$peerNetwork = new \OpenCCK\App\Peer\PeerNetwork(
    $peerRepository,
    $principals,
    $vault,
    new \OpenCCK\App\Peer\A2aClient(HttpClients::create(), $agentIdentity, $agentKeys, $a2aConnect, $a2aLinks, $a2aServeFor),
    $conversations,
    $transcript,
    $channel,
    $selfUrl ?? 'local:' . $telegram->ownerId,
    $logger,
    $agentIdentity,
    static fn(): ?Principal => $principals->findByIdentity(TelegramChannel::NAME, $telegram->ownerId),
    $agentKeys,
    $a2aLinks,
);
$tools->register(new \OpenCCK\App\Agent\Tool\PeerDelegateTool($peerNetwork, $peerRepository, $conversations));
$tools->register(new \OpenCCK\App\Agent\Tool\PeerLinksTool($peerNetwork));
// a connection nobody used for an hour closes; the agent opens it again when it needs it
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(300, static function (string $id) use ($a2aLinks): void {
    $a2aLinks->closeIdle(3600.0);
}));
$tools->register(new \OpenCCK\App\Agent\Tool\PeerKeysTool($peerNetwork, $agentKeys));
// ws (by default) — neighbours connect by WebSocket only; http — by POST /a2a only
$a2aTransport = (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_TRANSPORT') ?? 'ws') === 'http' ? 'http' : 'ws';
$a2aServer = $selfUrl === null || $a2aToken === null || !$vault->enabled() ? null : new \OpenCCK\App\Peer\A2aServer(
    $peerRepository,
    $principals,
    $loop,
    $runs,
    $transcript,
    $conversations,
    $policies,
    $llmSettings->model,
    $selfUrl,
    \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_NAME') ?? 'Mercurius',
    \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_DESCRIPTION') ?? 'Персональный агент: задачи по правилам и правам своего владельца.',
    $logger,
    $agentIdentity,
    $a2aToken,
    transport: $a2aTransport,
    links: $a2aLinks,
);
if ($a2aServer !== null) {
    $scheduler->useJobRuns($a2aServer);
}
$dailyDigest = new DailyDigest($projectRepository, $jobRepository, new PostgresDigestJournal($db), $jobScheduler, $channel, $telegram->ownerId, $digestTime, $logger);
$tools->register(new MemorySearchTool($recall, $audience));
$tools->register(new MemoryProposeTool($proposals, $memoryCards, $audience));
$tools->register(new HistorySearchTool(new PostgresEpisodeRepository($db), $audience));
$tools->register(new ExperienceSearchTool($experience, $audience));
$background->useScheduler($scheduler);
$approvals->onDecision($scheduler->resumeRun(...));
$visitors = new VisitorService($principals, $channel, $telegram->ownerId, $logger);
// spec: BRIEF.md#telegram-группы-каналы-и-темы — groups approved by the owner; the bot's name finds mentions
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
    $telegram->ownerId,
    explode(':', $telegram->token(), 2)[0],
    $botUsername,
    $logger,
    $forumTopics,
    $runMessages,
    $visitors,
    new \OpenCCK\Infrastructure\Repository\PostgresGroupTalkRepository($db),
    // spec: BRIEF.md#группа — tokens the runs of a group spent since midnight UTC
    static function (string $channel, string $chatId) use ($db): int {
        $row = $db->execute(
            "SELECT coalesce(sum(r.tokens_used), 0)::bigint AS spent FROM runs r JOIN conversations c ON c.id = r.conversation_id
             WHERE c.channel = ? AND c.chat_id = ? AND r.created_at >= date_trunc('day', now())",
            [$channel, $chatId],
        )->fetchRow();
        $spent = $row['spent'] ?? 0;
        return is_int($spent) ? $spent : 0;
    },
    is_numeric($_ENV['GROUP_FREE_DAILY_TOKENS'] ?? null) ? (int) $_ENV['GROUP_FREE_DAILY_TOKENS'] : 200000,
);
// spec: BRIEF.md#личные-сообщения-участникам — personal messages to participants, their answers come back to who asked
$messageRelays = new \OpenCCK\Infrastructure\Repository\PostgresMessageRelayRepository($db);
$tools->register(new \OpenCCK\App\Agent\Tool\SendMessageTool($principals, $channel, $messageRelays, TelegramChannel::NAME));
$chat = new ChatService(
    new AccessService($principals, TelegramChannel::NAME, $telegram->ownerId, $visitors),
    $conversations,
    $scheduler,
    $channel,
    $approvals,
    new TraceReports(new PostgresTraceReportRepository($db), $traces),
    $logger,
    new MemoryCommands($memoryStore, $proposals, $memoryContext, $consolidation),
    new ProjectCommands($projectRepository),
    new ServerCommands($vault, $serverRepository, $commandAudit),
    new SkillCommands($skillRepository, $skillImporter),
    new McpCommands($mcpManager, $mcpServers),
    new JobCommands($jobRepository, $jobScheduler, $vault, $dailyDigest, $config->appUrl),
    // spec: BRIEF.md#решено-в-фазе-12 — /doctor checks the installation
    new \OpenCCK\App\Service\Doctor($_ENV, $db, PATH_ROOT . '/migrations', PATH_ROOT . '/storage', HttpClients::create()),
    new \OpenCCK\App\Service\SettingsCommands($settingsService, $restarter),
    new \OpenCCK\App\Service\PeerCommands($peerNetwork, $peerRepository),
    $groupGate,
    explode(':', $telegram->token(), 2)[0],
    $runMessages,
    new \OpenCCK\App\Service\RelayService($messageRelays, $principals, $conversations, $scheduler, $channel, $logger),
);
// spec: BRIEF.md#трассировка-и-метрики — spend by topics, projects, participants and periods of the owner's days
$tools->register(new \OpenCCK\App\Agent\Tool\SpendingTool(new TraceReports(new PostgresTraceReportRepository($db), $traces), $runs, $runMessages, $conversations, $principals, $ownerZone->getName()));
$tools->register(new \OpenCCK\App\Agent\Tool\SettingsTool($settingsService, $restarter));
$presses = new CallbackRouter($channel, $telegram->ownerId, ['ap' => $approvals, 'pg' => $approvals, 'pv' => $visitors, 'gr' => $groupGate, MemoryCards::PREFIX => $memoryCards, HostKeyCards::PREFIX => $hostKeyCards, SkillCards::PREFIX => $skillCards]);

$botId = (int) explode(':', $telegram->token(), 2)[0];
$parser = new UpdateParser($botId);
$dispatcher = new ChatDispatcher($chat, $logger, callbacks: $presses, memberships: new \OpenCCK\App\Service\MembershipRouter([$groupGate, $channelRegistry]), posts: $channelRegistry);
$deduplicator = new Deduplicator();

$routes = [];
$webhookSecret = $telegram->webhookSecret();
if ($webhookSecret !== null) {
    $routes[] = ['POST', '/telegram/webhook', new TelegramWebhookHandler(new WebhookProcessor($webhookSecret, $parser, $dispatcher, $deduplicator, $logger))];
}

if ($a2aServer !== null) {
    $routes[] = ['GET', '/.well-known/agent-card.json', new \OpenCCK\Infrastructure\API\Handler\A2aHandler($a2aServer)];
    if ($a2aServer->transport === 'http') {
        $routes[] = ['POST', '/a2a', new \OpenCCK\Infrastructure\API\Handler\A2aHandler($a2aServer)];
    } else {
        $a2aSocket = new \OpenCCK\Infrastructure\API\Handler\A2aSocketHandler($a2aServer);
        $routes[] = ['GET', '/a2a/ws', static fn(\Amp\Http\Server\HttpServer $http): \Amp\Websocket\Server\Websocket => new \Amp\Websocket\Server\Websocket($http, $logger, $a2aSocket, $a2aSocket)];
    }
}

// spec: BRIEF.md#планировщик-и-проекты — a signed event starts a job by event
$routes[] = ['POST', '/hooks/{id:\d+}', new JobHookHandler(new HookReceiver($jobRepository, $jobScheduler, $vault, $principals, $logger))];

// Prometheus: GET /metrics behind METRICS_TOKEN; without the token the endpoint does not exist.
$routes[] = ['GET', '/metrics', new MetricsHandler(new MetricsText(new PostgresMetricsRepository($db)), $traceSettings->metricsToken())];

$health = new HealthService([new DatabaseHealthCheck($db)], 2.0, $logger);

(new App($logger))
    ->addHandler(fn(App $app): Server => new Server($app->getLogger(), $config->httpHost, $config->httpPort, $health, $version, $routes))
    // Before Telegram: modules stop in reverse order, so intake stops first, then runs pause.
    ->addHandler(fn(App $app): AgentModule => new AgentModule($scheduler, $app->getLogger(), $approvals, background: $background, deliveries: new \OpenCCK\App\Agent\DeliveryRecovery($deliveryRepository, $channel, $app->getLogger())))
    ->addHandler(fn(App $app): ModelCatalogModule => new ModelCatalogModule($catalogSources, $modelCatalog, $app->getLogger()))
    ->addHandler(fn(App $app): TraceRetentionModule => new TraceRetentionModule($traces, $traceSettings->retentionDays, $app->getLogger()))
    ->addHandler(fn(App $app): MemoryConsolidationModule => new MemoryConsolidationModule($consolidation, $memorySettings->checkHour, $app->getLogger()))
    ->addHandler(fn(App $app): SchedulerModule => new SchedulerModule($jobScheduler, $jobRepository, $app->getLogger(), $dailyDigest))
    ->addHandler(fn(App $app): \OpenCCK\Infrastructure\Module\PeerModule => new \OpenCCK\Infrastructure\Module\PeerModule($peerNetwork, $a2aServer, $app->getLogger()))
    ->addHandler(fn(App $app): TelegramModule => new TelegramModule(
        $telegramApi,
        $telegram,
        new LongPolling($telegramApi, $parser, $dispatcher, $deduplicator, $app->getLogger()),
        $app->getLogger(),
    ))
    ->run($restarter);

$db->close();
