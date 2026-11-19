<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mercurius\Llm\LlmException;
use Mercurius\Messenger\ChatDispatcher;
use Mercurius\Messenger\Deduplicator;
use Mercurius\Messenger\OutgoingMessage;
use Mercurius\Messenger\Telegram\LongPolling;
use Mercurius\Messenger\Telegram\TelegramApi;
use Mercurius\Messenger\Telegram\TelegramChannel;
use Mercurius\Messenger\Telegram\UpdateParser;
use Mercurius\Messenger\Telegram\WebhookProcessor;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\CallbackRouter;
use OpenCCK\App\Agent\Context\ContextCompactor;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\Delegation\BackgroundDelegations;
use OpenCCK\App\Agent\Delegation\DelegateTaskTool;
use OpenCCK\App\Agent\Delegation\WorkerReportTool;
use OpenCCK\App\Agent\Delegation\WorkerRunner;
use OpenCCK\App\Agent\HostKeyCards;
use OpenCCK\App\Agent\MemoryCards;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Model\ModelSelector;
use OpenCCK\App\Agent\Model\RequestClassifier;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\SpendGuard;
use OpenCCK\App\Agent\Tool\Agents\SetParticipantPolicyTool;
use OpenCCK\App\Agent\Tool\Files\FileWriteTool;
use OpenCCK\App\Agent\Tool\Files\ListFilesTool;
use OpenCCK\App\Agent\Tool\Files\ReadFileTool;
use OpenCCK\App\Agent\Tool\Files\WriteFileTool;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Memory\ExperienceSearchTool;
use OpenCCK\App\Agent\Tool\Memory\HistorySearchTool;
use OpenCCK\App\Agent\Tool\Memory\MemoryProposeTool;
use OpenCCK\App\Agent\Tool\Memory\MemorySearchTool;
use OpenCCK\App\Agent\Tool\Projects\JobCreateTool;
use OpenCCK\App\Agent\Tool\Projects\JobManageTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectTool;
use OpenCCK\App\Agent\Tool\Sandbox\RunCommandTool;
use OpenCCK\App\Agent\Tool\Self\OpenRouterByokTool;
use OpenCCK\App\Agent\Tool\Self\SetModelRoleTool;
use OpenCCK\App\Agent\Tool\Servers\ServerAddTool;
use OpenCCK\App\Agent\Tool\Servers\ServersTool;
use OpenCCK\App\Agent\Tool\Servers\SqlQueryTool;
use OpenCCK\App\Agent\Tool\Servers\SshRunTool;
use OpenCCK\App\Agent\Tool\Skills\SkillViewTool;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\Web\BrowserTool;
use OpenCCK\App\Agent\Tool\Web\HttpRequestTool;
use OpenCCK\App\Agent\Tool\Web\TcpProbeTool;
use OpenCCK\App\Agent\Tool\Web\WebReadTool;
use OpenCCK\App\Agent\Tool\Web\WebSearchTool;
use OpenCCK\App\Agent\Trace\Tracer;
use OpenCCK\App\Job\DailyDigest;
use OpenCCK\App\Job\HookReceiver;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Mcp\McpManager;
use OpenCCK\App\Server\ServerConsole;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\HealthService;
use OpenCCK\App\Service\JobCommands;
use OpenCCK\App\Service\McpCommands;
use OpenCCK\App\Service\MemoryCommands;
use OpenCCK\App\Service\MetricsText;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\App\Service\ProjectCommands;
use OpenCCK\App\Service\ServerCommands;
use OpenCCK\App\Service\SkillCommands;
use OpenCCK\App\Service\TraceReports;
use OpenCCK\App\Service\VisitorService;
use OpenCCK\App\Vault\Vault;
use OpenCCK\App\Web\TextExtractor;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\API\Handler\JobHookHandler;
use OpenCCK\Infrastructure\API\Handler\MetricsHandler;
use OpenCCK\Infrastructure\API\Handler\TelegramWebhookHandler;
use OpenCCK\Infrastructure\API\Server;
use OpenCCK\Infrastructure\Browser\BrowserClient;
use OpenCCK\Infrastructure\Config\AgentSettings;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Config\MemorySettings;
use OpenCCK\Infrastructure\Config\SandboxSettings;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use OpenCCK\Infrastructure\Config\TraceSettings;
use OpenCCK\Infrastructure\Config\VaultSettings;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\DatabaseHealthCheck;
use OpenCCK\Infrastructure\Database\Migrator;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Llm\ProviderClients;
use OpenCCK\Infrastructure\Llm\ProviderRouter;
use OpenCCK\Infrastructure\Logger\LoggerFactory;
use OpenCCK\Infrastructure\Mcp\HttpMcpClient;
use OpenCCK\Infrastructure\Module\AgentModule;
use OpenCCK\Infrastructure\Module\MemoryConsolidationModule;
use OpenCCK\Infrastructure\Module\ModelCatalogModule;
use OpenCCK\Infrastructure\Module\SchedulerModule;
use OpenCCK\Infrastructure\Module\TelegramModule;
use OpenCCK\Infrastructure\Module\TraceRetentionModule;
use OpenCCK\Infrastructure\Repository\PostgresApprovalRepository;
use OpenCCK\Infrastructure\Repository\PostgresCommandAuditRepository;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresDelegationRepository;
use OpenCCK\Infrastructure\Repository\PostgresDigestJournal;
use OpenCCK\Infrastructure\Repository\PostgresEpisodeRepository;
use OpenCCK\Infrastructure\Repository\PostgresExperienceRepository;
use OpenCCK\Infrastructure\Repository\PostgresJobRepository;
use OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository;
use OpenCCK\Infrastructure\Repository\PostgresMcpServerRepository;
use OpenCCK\Infrastructure\Repository\PostgresMetricsRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelReliabilityRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelRoleRepository;
use OpenCCK\Infrastructure\Repository\PostgresPolicyRepository;
use OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Infrastructure\Repository\PostgresRoutingDecisionRepository;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Infrastructure\Repository\PostgresSecretRepository;
use OpenCCK\Infrastructure\Repository\PostgresServerRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceReportRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceRepository;
use OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository;
use OpenCCK\Infrastructure\Sandbox\HttpSandboxClient;
use OpenCCK\Infrastructure\Ssh\AmpSshShell;
use OpenCCK\Memory\App\Consolidation;
use OpenCCK\Memory\App\MemoryContext;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\App\Recall;
use OpenCCK\Memory\Domain\ContentScreen;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\TextAnalyzer;
use OpenCCK\Memory\Infrastructure\PostgresConsolidationJournal;
use OpenCCK\Memory\Infrastructure\PostgresMemoryStore;
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
// spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the secrets of the settings are masked by their values everywhere
foreach ($_ENV as $name => $value) {
    if (is_string($value) && preg_match('~(TOKEN|_KEY|SECRET|PASSWORD|_HASH|_URI)$~', $name) === 1) {
        SecretMasker::remember($value);
    }
}
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
$principals = new PostgresPrincipalRepository($db, TelegramChannel::NAME);
// spec: BRIEF.md#несколько-пользователей — TELEGRAM_ADMIN_ID is the first owner; ownership may pass on, the owner is asked here
$ownerDirectory = new \OpenCCK\App\Service\OwnerDirectory($principals, TelegramChannel::NAME, $telegram->ownerId);
// spec: BRIEF.md#несколько-пользователей — the owner reads every secret but a former owner's
$vault = new Vault(new PostgresSecretRepository($db), $vaultSettings->key(), hidden: $ownerDirectory->isFormerOwner(...));
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
        \OpenCCK\Infrastructure\Config\MediaSettings::fromEnv($env);
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
    static fn(): ?Principal => $ownerDirectory->principal(),
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

// Models: one client per provider with a key (its profile says which), chosen by the model's prefix; all go through VLESS when VLESS_URI is set.
// spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai
$modelHttp = HttpClients::create($llmSettings->routeUri(), $logger);
$onRetry = static fn(LlmException $e, int $attempt, float $delay): null => $logger->warning(
    'Retrying model call',
    ['kind' => $e->kind->value, 'attempt' => $attempt, 'delay' => $delay],
);
$providerClients = ProviderClients::build($llmSettings, $modelHttp, $config->appUrl, $onRetry);
$clients = $providerClients->clients;
$catalogSources = $providerClients->catalogs;
$toolReliableSources = $providerClients->toolReliable;
$openRouterKey = $llmSettings->openRouterKey();
$openAiKey = $llmSettings->openAiKey();
// Model catalog per provider, model roles, the choice of a conversation's model.
$modelCatalog = new PostgresModelCatalogRepository($db);
$llm = new ProviderRouter($clients, $modelCatalog);
$routingDecisions = new PostgresRoutingDecisionRepository($db);
// spec: BRIEF.md#модели — a change of the model of a role reaches the open conversations of that role
$modelRoles = new ModelRoles(new PostgresModelRoleRepository($db), $modelCatalog, $llmSettings->providers(), new \OpenCCK\App\Agent\Model\RoleConversations(new PostgresConversationRepository($db), $routingDecisions, $logger));
$modelRoles->seed();
$llmCalls = new PostgresLlmCallRepository($db);
$modelRouter = new ModelRouter(
    new RequestClassifier($llm, static fn(): string => (string) $modelRoles->model(ModelRole::CHEAP), $logger, calls: $llmCalls),
    $modelRoles,
    $modelCatalog,
    $routingDecisions,
    $llmSettings->contextWindow,
    $logger,
);
$logger->info('Model route', ['route' => $llmSettings->routeUri() === null ? 'direct' : 'vless', 'providers' => $llmSettings->providers(), 'model' => $llmSettings->model]);

// Channel: Telegram — directly, through a proxy or VLESS (TELEGRAM_PROXY); the cloud Bot API or a local server.
// spec: BRIEF.md#telegram-прокси-и-локальный-сервер-bot-api-решение-владельца-2026-11-17
$telegramHttp = HttpClients::create($telegram->route(), $logger);
$telegramFiles = rtrim($telegram->filesRoot, '/') . '/';
// a local server keeps files on its disk; they are read only inside its folder, up to the limit
$telegramLocalFile = static function (string $path, int $maxBytes) use ($telegramFiles): ?string {
    if (!str_starts_with($path, $telegramFiles) || str_contains($path, '..')) {
        return null;
    }
    try {
        return \Amp\File\getSize($path) > $maxBytes ? null : \Amp\File\read($path);
    } catch (\Amp\File\FilesystemException) {
        return null;
    }
};
$telegramApi = new TelegramApi($telegram->token(), $telegramHttp, $telegram->apiUrl ?? TelegramApi::DEFAULT_BASE_URL, localFile: $telegramLocalFile);
// Once, when moving to a local server, the bot leaves the cloud Bot API (logOut): updates then go to the local one.
// Back to the cloud — the marker is removed; Telegram lets a bot back only 10 minutes after logOut.
$cloudLoggedOut = PATH_ROOT . '/storage/telegram-cloud-logged-out';
if ($telegram->apiUrl !== null && !is_file($cloudLoggedOut)) {
    try {
        (new TelegramApi($telegram->token(), $telegramHttp))->call('logOut');
        $logger->notice('The bot left the cloud Bot API for the local server');
    } catch (\Mercurius\Messenger\Telegram\TelegramException $e) {
        $logger->notice('Cloud logOut', ['result' => mb_substr($e->getMessage(), 0, 200)]);
    }
    @file_put_contents($cloudLoggedOut, date('c'));
} elseif ($telegram->apiUrl === null && is_file($cloudLoggedOut)) {
    @unlink($cloudLoggedOut);
}
$logger->info('Telegram route', ['proxy' => $telegram->route() === null ? 'direct' : (str_starts_with($telegram->route(), 'vless') ? 'vless' : 'proxy'), 'api' => $telegram->apiUrl ?? 'cloud']);
// spec: BRIEF.md#второй-канал-websocket-api — chats ws:… go to the WebSocket API, the rest to Telegram
$wsChannel = new \Mercurius\Messenger\Websocket\WebsocketChannel();
$channel = new \Mercurius\Messenger\RoutingChannel(new TelegramChannel($telegramApi, logger: $logger), [\Mercurius\Messenger\Websocket\WebsocketChannel::PREFIX => $wsChannel]);

// Agent: runs, tools, scheduler.
$conversations = new PostgresConversationRepository($db);
$runs = new PostgresRunRepository($db);
$runMessages = new \OpenCCK\Infrastructure\Repository\PostgresRunMessageRepository($db);
$deliveryRepository = new \OpenCCK\Infrastructure\Repository\PostgresDeliveryRepository($db);
$transcript = new PostgresTranscriptRepository($db);
// spec: BRIEF.md#свои-роли-решение-владельца-2026-11-19 — the owner's list of roles: titles, order, powers, templates
$roleCatalog = new \OpenCCK\App\Service\RoleCatalog(new \OpenCCK\Infrastructure\Repository\PostgresRoleRepository($db));
$roleCatalog->load();
$policies = new PolicyService(new PostgresPolicyRepository($db), $agentSettings->ownerLimits(), $sandboxSettings->ownerLimits(), $roleCatalog->template(...));
$addressGuard = new AddressGuard();
$tools = new ToolRegistry();
// spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — module services from .env; a tool of a module only while its service is up,
// checked at start and once a minute
$serviceModules = new \OpenCCK\Infrastructure\Services\ServiceModules(HttpClients::create(), $logger);
$serviceModules->add(\OpenCCK\Infrastructure\Media\VideoService::MODULE, \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MEDIA_VIDEO_URL') ?? 'http://media-video:8091', \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MEDIA_VIDEO_TOKEN'));
$serviceModules->add(\OpenCCK\Infrastructure\Media\DocsService::MODULE, \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MEDIA_DOCS_URL') ?? 'http://media-docs:8092', \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MEDIA_DOCS_TOKEN'));
$docsService = new \OpenCCK\Infrastructure\Media\DocsService(HttpClients::create(), $serviceModules);
$tools->withServices($serviceModules->up(...));
\Revolt\EventLoop::unreference(\Revolt\EventLoop::defer(static function () use ($serviceModules): void {
    $serviceModules->check();
}));
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(60, static function () use ($serviceModules): void {
    $serviceModules->check();
}));
// spec: BRIEF.md#подтверждения-и-запреты — a host the owner allowed is dialled through a guard that lets it alone in
$guardedClient = static fn(AddressGuard $guard): \Amp\Http\Client\HttpClient => HttpClients::guarded($guard);
// spec: BRIEF.md#решения-по-доработкам-2026-11-18 — cookies of http_request live with the project of the conversation,
//       else with the participant, like browser profiles
$httpCookieProjects = new PostgresProjectRepository($db);
$httpCookieScope = static function (\OpenCCK\App\Agent\Tool\ToolContext $context) use ($conversations, $httpCookieProjects): string {
    $meta = $conversations->meta($context->conversationId);
    $project = $meta === null ? null : $httpCookieProjects->active($meta->channel, $meta->chatId, $meta->threadId);
    return $project === null ? 'p' . $context->principal->id : 'project:' . $project->id;
};
$tools->register(new HttpRequestTool(HttpClients::guarded($addressGuard), $addressGuard, $vault, $guardedClient, new \OpenCCK\Infrastructure\Repository\PostgresHttpCookieRepository($db), $httpCookieScope));
// spec: BRIEF.md#интернет-и-браузер — pages as text; search by the model's web search on OpenRouter
$tools->register(new WebReadTool(HttpClients::guarded($addressGuard), $addressGuard, new TextExtractor(), $guardedClient));
// spec: BRIEF.md#решения-владельца-2026-11-17 — search providers in the order of SEARCH_PROVIDERS
[$webSearch, $searchSkipped, $searchEach] = \OpenCCK\Infrastructure\Config\SearchSettings::build($_ENV, $modelHttp, $llmSettings->openRouterKey());
if ($searchSkipped !== []) {
    $logger->warning('Search providers without settings are skipped', ['providers' => $searchSkipped]);
}
if ($webSearch !== null) {
    $webSearchTool = new WebSearchTool($webSearch, $searchEach, array_map('\Mercurius\I18n\t', \OpenCCK\Infrastructure\Config\SearchSettings::ABOUT));
    $webSearchTool->useAccounting($llmCalls, $runs);
    $tools->register($webSearchTool);
}
// spec: BRIEF.md#решения-по-доработкам-2026-11-18 — rights are given only in the owner's private chat: the owner's own
//      chat in Telegram or over the WebSocket API, not a group, a channel, a job or a neighbour's task
$ownerPrivateChat = static function (int $conversationId) use ($conversations): bool {
    $meta = $conversations->meta($conversationId);
    return $meta !== null && in_array($meta->channel, [TelegramChannel::NAME, \Mercurius\Messenger\Websocket\WebsocketChannel::NAME], true) && !str_starts_with($meta->chatId, '-');
};
$tools->register(new SetParticipantPolicyTool($principals, $policies, $ownerPrivateChat));
// spec: BRIEF.md#участник-просит-инструмент-решение-владельца-2026-11-17 — a participant without a tool asks the owner for it
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\RequestToolTool($tools, $policies));
// spec: BRIEF.md#входящие-вложения — the owner's media models and principles, each participant's own way with files
$mediaSettings = \OpenCCK\Infrastructure\Config\MediaSettings::fromEnv($_ENV);
$mediaPrefs = new \OpenCCK\App\Agent\Media\MediaPrefs(new \OpenCCK\Infrastructure\Repository\PostgresMediaPrefRepository($db), $mediaSettings);
$tools->register(new \OpenCCK\App\Agent\Tool\Files\MediaPrefsTool($mediaPrefs));
/** @var \OpenCCK\Infrastructure\Repository\PostgresPeerRepository|null $peerRepository set below */
$peerRepository = null;
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\ParticipantsTool($principals, $policies, static function () use (&$peerRepository): array {
    return $peerRepository?->keys() ?? [];
}, $ownerPrivateChat));
$tools->register(new SetModelRoleTool($modelRoles));
// Sandbox: commands and files of the agent; off without SANDBOX_TOKEN.
$sandboxToken = $sandboxSettings->token();
$sandbox = $sandboxToken === null ? null : new HttpSandboxClient(HttpClients::create(), $sandboxSettings->url, $sandboxToken);
/** @var \OpenCCK\App\Peer\A2aServer|null $a2aServer set below: the client asks it who answers a neighbour, send_file answers a task */
$a2aServer = null;
$checkpoints = $sandbox === null ? null : new \OpenCCK\App\Agent\Sandbox\Checkpoints($sandbox, $logger);
if ($sandbox !== null && $checkpoints !== null) {
    $tools->register(new \OpenCCK\App\Agent\Tool\Files\CheckpointTool($sandbox, $checkpoints));
    $tools->register(new ReadFileTool($sandbox));
    $tools->register(new ListFilesTool($sandbox));
    $tools->register(new \OpenCCK\App\Agent\Tool\Files\SearchFilesTool($sandbox));
    $tools->register(new WriteFileTool($sandbox));
    $tools->register(new RunCommandTool($sandbox, $agentSettings->toolTimeout));
    // spec: BRIEF.md#планировщик-и-проекты — git with a token of the vault in the environment of one command
    $tools->register(new \OpenCCK\App\Agent\Tool\Sandbox\GitTool($sandbox, $sandbox, $vault, $agentSettings->toolTimeout));
    // spec: BRIEF.md#песочница — a file the agent made goes to the chat it was asked in
    // spec: BRIEF.md#между-агентами — in a task of another agent a file goes with the answer
    $tools->register(new \OpenCCK\App\Agent\Tool\Messaging\SendFileTool($sandbox, $channel, $conversations, new \OpenCCK\App\Agent\Tool\Messaging\Recipients($principals, TelegramChannel::NAME), static function (int $runId, string $name, string $bytes) use (&$a2aServer): bool {
        return $a2aServer instanceof \OpenCCK\App\Peer\A2aServer && $a2aServer->answerFile($runId, $name, $bytes);
    }));
}
// spec: BRIEF.md#личные-сообщения-участникам — a location, a contact, a poll, an article: what the messenger carries
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\SendContentTool($channel, $conversations, new \OpenCCK\App\Agent\Tool\Messaging\Recipients($principals, TelegramChannel::NAME)));
$logger->info('Sandbox', ['enabled' => $sandbox !== null]);
// Tracing: steps of every run, masked payloads.
$traces = new PostgresTraceRepository($db);
$tracer = new Tracer($traces, $modelCatalog, $logger);
// Approvals: cards in the owner's private chat (in Telegram its id is the owner's user id).
$approvalStore = new PostgresApprovalRepository($db);
$approvals = new ApprovalService($approvalStore, $principals, $channel, $ownerDirectory, $agentSettings->approvalTimeout, $logger, $tracer);
// spec: BRIEF.md#входящие-вложения — vision, speech and video models through the models' HTTP client (VLESS when set);
// an own endpoint's key is the vault secret media-endpoint-<name>
$mediaModels = new \OpenCCK\Infrastructure\Media\MediaModels(
    $modelHttp,
    $openRouterKey,
    $openAiKey,
    $mediaSettings,
    static function (string $name) use ($vault, $ownerDirectory): ?string {
        $owner = $ownerDirectory->principal();
        return $owner === null ? null : $vault->reveal('media-endpoint-' . $name, $owner);
    },
    $llmCalls,
    $logger,
    // the other providers (anthropic, bedrock, codex) look through the text models' clients
    $clients,
);
// spec: BRIEF.md#интернет-и-браузер — the browser module (profile browser), offered while its service answers
$browserToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_TOKEN');
$browserUrl = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_URL') ?? 'http://browser:3000';
if ($serviceModules->add(BrowserTool::MODULE, $browserUrl, $browserToken) && $browserToken !== null) {
    $browserClient = new BrowserClient(HttpClients::create(), $browserUrl, $browserToken);
    // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — profiles of the old flat layout go into the owner's set, logins stay
    $browserOwner = $principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null);
    \Amp\async(static function () use ($browserClient, $browserOwner, $logger): void {
        try {
            $moved = $browserClient->call('adopt', ['owner' => 'p' . $browserOwner->id], new \Amp\TimeoutCancellation(60));
            $logger->info('Browser profiles adopted', ['moved' => $moved['moved'] ?? []]);
        } catch (\Throwable $e) {
            $logger->warning('Browser profiles were not adopted', ['error' => $e::class]);
        }
    })->ignore();
    $tools->register(new BrowserTool(
        $browserClient,
        $channel,
        $ownerDirectory,
        $addressGuard,
        // a screenshot goes to the chat it was asked in and is kept in the sandbox, to be sent on
        $conversations,
        $sandbox,
        // and the agent looks at it with a vision model of the owner's settings or the participant's choice
        $mediaModels,
        $mediaPrefs,
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — each participant has profiles of their own and of their project
        static function (int $conversationId) use ($conversations, $httpCookieProjects): ?int {
            $meta = $conversations->meta($conversationId);
            return $meta === null ? null : $httpCookieProjects->active($meta->channel, $meta->chatId, $meta->threadId)?->id;
        },
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — browser confirmations are off unless the owner turns them on
        \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_CONFIRM') === '1',
    ));
}
if ($sandbox !== null) {
    $tools->register(new \OpenCCK\App\Agent\Tool\Files\MediaTool($sandbox, $mediaModels, $mediaPrefs, new \OpenCCK\Infrastructure\Media\VideoService(HttpClients::create(), $serviceModules), $docsService));
    // spec: BRIEF.md#категории-моделей — the image category: offered only while it has a model
    if ($mediaSettings->models('image') !== []) {
        $tools->register(new \OpenCCK\App\Agent\Tool\Files\ImageGenerateTool($sandbox, $mediaModels, $mediaSettings));
    }
    // spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — new docx, xlsx, pptx by the media-docs module, while it runs
    $tools->register(new \OpenCCK\App\Agent\Tool\Files\DocumentsTool($sandbox, $docsService));
    // files that came in are kept MEDIA_INBOX_DAYS: once a day the older days' folders of inbox/ go
    $inboxCleaner = new \OpenCCK\App\Agent\Media\InboxCleaner($principals, $policies, $sandbox, $mediaSettings, $logger);
    \Revolt\EventLoop::unreference(\Revolt\EventLoop::delay(600, static function () use ($inboxCleaner): void {
        $inboxCleaner->run();
        \Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(86400, static function (string $id) use ($inboxCleaner): void {
            $inboxCleaner->run();
        }));
    }));
}
$loop = new AgentLoop(
    $llm,
    $runs,
    $transcript,
    $conversations,
    $llmCalls,
    $tools,
    // spec: BRIEF.md#планировщик-и-проекты — a snapshot of the working folder before the first change of each run
    new ToolExecutor(logger: $logger, sandbox: $sandbox, checkpoints: $checkpoints),
    $llmSettings->maxTokens,
    $llmSettings->reasoningEffort === 'none' ? null : $llmSettings->reasoningEffort,
    $llmSettings->historyLimit,
    $logger,
    approvals: $approvalStore,
    gate: $approvals,
    // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — tool results are bounded by the window of the model
    budget: \OpenCCK\App\Agent\Tool\ResultBudget::forWindow($llmSettings->contextWindow),
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
// spec: BRIEF.md#агентский-цикл-и-харнесс — models that answer in prose are told to act through tools
$loop->useToolUseGuidance(new \OpenCCK\App\Agent\ToolUseGuidance(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'AGENT_TOOL_USE_GUIDANCE') ?? 'auto'));
// spec: BRIEF.md#скиллы-и-mcp — over 5% of the window the tools of MCP servers go behind tool_search
$tools->register(new \OpenCCK\App\Agent\Tool\ToolSearchTool(\OpenCCK\App\Agent\Tool\ToolSearchTool::SEARCH, $tools));
$tools->register(new \OpenCCK\App\Agent\Tool\ToolSearchTool(\OpenCCK\App\Agent\Tool\ToolSearchTool::DESCRIBE, $tools));
$loop->useToolSearch(new \OpenCCK\App\Agent\Tool\ToolSearch($modelRouter->window(...)));
// Delegation: workers run through the same loop, so the tool is registered once the loop exists.
$workers = new WorkerRunner($loop, $runs, $transcript, $conversations, $tools, $modelRoles, $agentSettings->workerMaxTurns, $agentSettings->workerTokenBudget, $logger, new ModelSelector($modelCatalog, $modelRoles, $routingDecisions, $logger, new PostgresModelReliabilityRepository($db)), $agentSettings->maxWorkersPerRun);
// spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a worker gets only tools of the white list
$workers->allowOnly(explode(',', \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'AGENT_WORKER_TOOLS') ?? implode(',', WorkerRunner::ALLOWED)));
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
// spec: BRIEF.md#анализ-текста — facts written before a new rule of the analyzer get its keys; in the background
\Amp\async(static function () use ($memoryStore, $textAnalyzer, $logger): void {
    try {
        $rekeyed = $memoryStore->rekey($textAnalyzer->keys(...));
        if ($rekeyed > 0) {
            $logger->info('Memory facts keyed again', ['facts' => $rekeyed]);
        }
    } catch (Throwable $e) {
        $logger->warning('Memory facts were not keyed again', ['error' => $e::class]);
    }
})->ignore();
$projectRepository = new PostgresProjectRepository($db);
// spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — the facts of the active project of a chat, for
// those who may see the project
$audience = new ConversationAudience($conversations, $ownerDirectory->formerOwners(...), static function (\OpenCCK\Domain\Entity\Principal $principal, string $channel, string $chatId, string $threadId) use ($projectRepository): ?int {
    if (!$principal->role->may(\OpenCCK\Domain\Entity\Power::Projects)) {
        return null;
    }
    $project = $projectRepository->active($channel, $chatId, $threadId);
    return $project !== null && $project->visibleTo($principal) ? $project->id : null;
});
$memoryContext = new MemoryContext($memoryStore, $recall);
$scheduler->useMemory($memoryContext, $audience);
// spec: BRIEF.md#всегда-в-контексте — the participant's MEMORY.md, whole, with a limit; no sandbox — no file
if ($sandbox !== null) {
    $scheduler->useMemoryFile(static function (\OpenCCK\Domain\Entity\Principal $principal) use ($sandbox, $policies): string {
        $limits = $policies->forPrincipal($principal)->sandbox;
        if ($limits === null) {
            return '';
        }
        try {
            return $sandbox->fetch($principal->id, $limits, 'MEMORY.md', 400_000, new \Amp\TimeoutCancellation(5));
        } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure) {
            return ''; // no file yet
        }
    }, $memorySettings->fileLimit);
}
if (!$memorySettings->hasSecret()) {
    $logger->warning('MEMORY_SECRET is not set: the key of forgotten texts comes from the bot token');
}
$proposals = new Proposals(
    $memoryStore,
    $textAnalyzer,
    new ContentScreen(static fn(string $text): bool => SecretMasker::mask($text) !== $text),
    $memorySettings->key($telegram->token()),
);
$memoryCards = new MemoryCards($proposals, $memoryStore, $channel, $ownerDirectory, $principals, $logger);
$proposals->onForget($memoryCards->forgotten(...));
// spec: BRIEF.md#консолидация-dreaming — the card says on how many days the person said it, by their own messages
$corroboration = new \OpenCCK\Memory\App\Corroboration($textAnalyzer);
$memoryCards->useCorroboration(static function (int $principalId, string $text) use ($corroboration, $transcript): int {
    $zone = new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'OWNER_TIMEZONE') ?? '+03:00');
    $since = time() - \OpenCCK\Memory\App\Corroboration::WINDOW_DAYS * 86400;
    return $corroboration->days($text, $transcript->spokenBy($principalId, $since, \OpenCCK\Memory\App\Corroboration::LINES), $zone);
});
// spec: BRIEF.md#память-слои — every N turns a cheap model proposes facts through the owner's card
$factNudgeTurns = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MEMORY_NUDGE_TURNS');
$scheduler->useAfterRun((new \OpenCCK\App\Agent\FactNudge(
    $llm,
    static fn(): string => (string) $modelRoles->model(ModelRole::CHEAP),
    $transcript,
    $conversations,
    $proposals,
    $memoryCards,
    $audience,
    $logger,
    is_numeric($factNudgeTurns) ? max(0, min(1000, (int) $factNudgeTurns)) : \OpenCCK\App\Agent\FactNudge::EVERY,
))->afterRun(...));
// spec: BRIEF.md#память-слои — a forgotten fact also leaves the transcripts it was recalled into
$experience = new PostgresExperienceRepository($db);
$workers->useExperience($experience);
// spec: BRIEF.md#трассировка-и-метрики — a worker is a step of its orchestrator's trace
$workers->useEvents($tracer);
// spec: BRIEF.md#права-воркера-задаёт-оркестратор — the conversation's context only when the orchestrator asks
$workers->useContext($scheduler->workerContext(...));
// spec: BRIEF.md#часть-1--синхронное-делегирование — reports share what is left of the orchestrator's window
$workers->useWindow(static function (string $model) use ($modelCatalog, $llmSettings): int {
    try {
        return $modelCatalog->find(ModelRef::parse($model))->contextLength ?? $llmSettings->contextWindow;
    } catch (\InvalidArgumentException) {
        return $llmSettings->contextWindow;
    }
});
$consolidationJournal = new PostgresConsolidationJournal($db);
$proposals->onForget(static function (Fact $fact) use ($transcript, $experience, $traces, $consolidationJournal): void {
    $line = sprintf('[#%d] %s', $fact->id, $fact->text);
    $transcript->redact($line, sprintf('[#%d] %s', $fact->id, \Mercurius\I18n\t('memory.forgotten')));
    $traces->redactPayloads($line, sprintf('[#%d] %s', $fact->id, \Mercurius\I18n\t('memory.forgotten')));
    $experience->redact($fact->text, \Mercurius\I18n\t('memory.forgotten'));
    // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — and the copies of it: arguments of calls and nightly reports
    $transcript->redactCalls($fact->text, \Mercurius\I18n\t('memory.forgotten'));
    $consolidationJournal->redact($fact->text, \Mercurius\I18n\t('memory.forgotten'));
});
// spec: BRIEF.md#консолидация-dreaming — the nightly check: code only, the owner decides
$consolidation = new Consolidation(
    $memoryStore,
    $proposals,
    $consolidationJournal,
    $memoryCards->show(...),
    static function (string $text) use ($channel, $ownerDirectory): bool {
        return $channel->send(new OutgoingMessage($ownerDirectory->chatId(), $text))->success;
    },
);
// spec: BRIEF.md#фаза-7-по-частям — the vault, the registry of servers, SSH with a command policy and a journal
if (!$vault->enabled()) {
    $logger->warning('SECRETS_KEY is not set: the vault and SSH are off');
}
$serverRepository = new PostgresServerRepository($db);
$commandAudit = new PostgresCommandAuditRepository($db);
$hostKeyCards = new HostKeyCards($serverRepository, $channel, $ownerDirectory);
// spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the owner may take off any rule of what is never done
$sshNeverOff = array_values(array_filter(array_map('trim', explode(',', \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'SSH_NEVER_OFF') ?? '')), static fn(string $r): bool => $r !== ''));
$serverConsole = new ServerConsole(
    $serverRepository,
    $vault,
    new SshCommandClassifier($sshNeverOff),
    $commandAudit,
    new AmpSshShell(null, $logger),
    $hostKeyCards->ask(...),
    $logger,
    $llmSettings->routeUri(),
    $vaultSettings->confirmGroups,
);
$tools->register(new ServersTool($serverRepository));
$tools->register(new ServerAddTool($serverRepository, $vault));
$tools->register(new \OpenCCK\App\Agent\Tool\Servers\ServerEditTool($serverRepository, $vault));
$tools->register(new \OpenCCK\App\Agent\Tool\Servers\ServerRemoveTool($serverRepository));
$tools->register(new SshRunTool($serverConsole));
$tools->register(new SqlQueryTool($serverConsole, $vault));
$tools->register(new FileWriteTool($serverConsole, new SshCommandClassifier($sshNeverOff)));
$tools->register(new TcpProbeTool($addressGuard));
$tools->register(new OpenRouterByokTool($modelHttp, $vault));

// spec: BRIEF.md#скиллы-и-mcp — skills are files: built-in ones (submodules in skills/), the participant's folder and the
// active project's folder in the sandbox; the index is in the prompt, the texts on demand
$skillProjects = new PostgresProjectRepository($db);
$skillLibrary = new \OpenCCK\App\Skill\SkillLibrary(
    \OpenCCK\App\Skill\BuiltinSkills::load(PATH_ROOT . '/skills', $logger),
    $sandbox,
    static function (int $principalId) use ($principals, $policies): ?\OpenCCK\Domain\Agent\SandboxLimits {
        $principal = $principals->find($principalId);
        return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
    },
    static fn(): ?int => $ownerDirectory->principal()?->id,
    $logger,
);
$scheduler->useSkills($skillLibrary);
// spec: BRIEF.md#скиллы-и-mcp — skills of the old layout move to .mercurius/skills at start, the old folder kept
if ($sandbox !== null) {
    \Amp\async(static function () use ($sandbox, $principals, $policies, $skillProjects, $ownerDirectory, $logger): void {
        $limitsOf = static function (int $id) use ($principals, $policies): ?\OpenCCK\Domain\Agent\SandboxLimits {
            $principal = $principals->find($id);
            return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
        };
        try {
            $people = array_values(array_map(
                static fn(Principal $p): int => $p->id,
                array_filter($principals->all(), static fn(Principal $p): bool => $p->role->may(\OpenCCK\Domain\Entity\Power::Skills)),
            ));
            $owner = $ownerDirectory->principal()?->id;
            $projects = [];
            foreach ($skillProjects->all(1000) as $project) {
                $holder = $project->ownerId ?? $owner;
                if ($holder !== null) {
                    $projects[] = [$project->id, $holder];
                }
            }
            $moved = (new \OpenCCK\App\Skill\SkillFolders($sandbox, $limitsOf, null, $logger))->migrate($people, $projects);
            $logger->info('Skill folders checked', ['moved' => $moved]);
        } catch (Throwable $e) {
            $logger->warning('Skill folders were not checked', ['error' => $e::class]);
        }
    })->ignore();
}
// spec: BRIEF.md#скиллы-и-mcp — a project named by the agent (a private chat about it), else the conversation's active one
$skillProjectOf = static function (int $conversationId, ?string $name, \OpenCCK\Domain\Entity\Principal $who) use ($conversations, $skillProjects): ?\OpenCCK\Domain\Project\Project {
    if ($name !== null && trim($name) !== '') {
        return $skillProjects->byName(trim($name), \OpenCCK\Domain\Project\Project::scopeOf($who));
    }
    $meta = $conversations->meta($conversationId);
    return $meta === null ? null : $skillProjects->active($meta->channel, $meta->chatId, $meta->threadId);
};
$skillUsage = new \OpenCCK\Infrastructure\Repository\PostgresSkillUsageRepository($db);
$skillLibrary->useUsage($skillUsage);
$skillLibrary->useEdits(new \OpenCCK\Infrastructure\Repository\PostgresSkillEditRepository($db));
$tools->register(new SkillViewTool($skillLibrary, $skillProjectOf));
$tools->register(new \OpenCCK\App\Agent\Tool\Skills\SkillSaveTool($skillLibrary, $skillProjectOf));
// spec: BRIEF.md#скиллы-и-mcp — ordinary SKILL.md skills of other agents, through the SSRF guard
$skillHttp = HttpClients::guarded($addressGuard);
// spec: BRIEF.md#скиллы-и-mcp — no limit on a skill by default; the owner may cap its number of files
$skillMaxFiles = max(0, (int) (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'SKILL_MAX_FILES') ?? '0'));
$skillImporter = new \OpenCCK\App\Skill\SkillImporter([new \OpenCCK\App\Skill\GitHubSkillSource($skillHttp, $skillMaxFiles), new \OpenCCK\App\Skill\ArchiveSkillSource($skillHttp, $skillMaxFiles)], $skillMaxFiles);
$tools->register(new \OpenCCK\App\Agent\Tool\Skills\SkillImportTool($skillImporter, $skillLibrary, $skillProjectOf, $sandbox));
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
    static fn(): ?Principal => $ownerDirectory->principal(),
    static function (string $text) use ($channel, $ownerDirectory): void {
        $channel->send(new OutgoingMessage($ownerDirectory->chatId(), $text));
    },
    $logger,
    stdio: $stdioMcp !== null,
);
// spec: BRIEF.md#скиллы-и-mcp — the package of an npx/uvx server is looked up in OSV before /mcp on starts it
$mcpManager->useOsv(new \OpenCCK\App\Mcp\OsvCheck(HttpClients::create(), $logger));
if ($sandbox !== null) {
    $mcpManager->useSandbox($sandbox, new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'OWNER_TIMEZONE') ?? '+03:00'));
}
\Revolt\EventLoop::defer($mcpManager->connectAll(...));
// spec: BRIEF.md#скиллы-и-mcp — servers down at start are tried again, connected ones are listed again for changes
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(30, static function (string $id) use ($mcpManager): void {
    \Amp\async($mcpManager->tick(...))->ignore();
}));
$tools->register(new \OpenCCK\App\Agent\Tool\Skills\McpResourcesTool($mcpManager));
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
// spec: BRIEF.md#планировщик-и-проекты — a watched address is read through the guard of internal addresses, as text
$jobWatchHttp = HttpClients::guarded($addressGuard);
$jobWatchText = new TextExtractor();
// spec: BRIEF.md#планировщик-и-проекты — initiative messages: a daily cap of reports the owner did not ask for now
$proactivePerDay = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'INITIATIVE_PER_DAY');
$proactiveBudget = new \OpenCCK\App\Job\ProactiveBudget($proactivePerDay !== null && ctype_digit($proactivePerDay) ? (int) $proactivePerDay : 6, $ownerZone);
// spec: BRIEF.md#несколько-пользователей — a participant's own time zone and quiet hours for their jobs
$participantPrefs = new \OpenCCK\Infrastructure\Repository\PostgresParticipantPrefRepository($db);
$participantClock = new \OpenCCK\App\Job\ParticipantClock($participantPrefs, $ownerZone, $quietHours);
$tools->register(new \OpenCCK\App\Agent\Tool\Memory\MyPreferencesTool($participantPrefs, $participantClock));
$jobScheduler = new JobScheduler($jobRepository, $loop, $runs, $transcript, $conversations, $principals, $policies, $tools, $channel, $llmSettings->model, $ownerZone, $quietHours, $logger, tokenBudget: $jobTokens, budget: $proactiveBudget, clock: $participantClock, fetch: static function (string $url) use ($jobWatchHttp, $jobWatchText): string {
    $request = new \Amp\Http\Client\Request($url);
    $request->setTransferTimeout(30);
    $request->setBodySizeLimit(2_000_000);
    $response = $jobWatchHttp->request($request);
    $body = $response->getBody()->buffer(limit: 2_000_000);
    if ($response->getStatus() >= 400) {
        throw new \RuntimeException('HTTP ' . $response->getStatus());
    }
    return $jobWatchText->extract($body, $response->getHeader('content-type') ?? '', $url)[1];
});
$jobScheduler->useSkills($skillLibrary);
// spec: BRIEF.md#планировщик-и-проекты — a job marked cheap runs on the model of the cheap role
$jobScheduler->useModels(static fn(string $role): string => (string) $modelRoles->model($role));
// spec: BRIEF.md#контекст-по-месту-и-аудитории — the memory core only for a job that reports to its owner's private chat
$jobScheduler->useCore(static function (Principal $who, \OpenCCK\Domain\Job\Job $job) use ($scheduler, $principals): string {
    $private = $job->threadId === '' && $principals->externalId($who->id, $job->channel) === $job->chatId;
    return $private ? $scheduler->coreOf($who) : '';
});
$digestTime = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'DIGEST_TIME');
$digestTime = $digestTime !== null && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $digestTime) === 1 ? $digestTime : null;
$tools->register(new JobCreateTool($jobRepository, $conversations, $jobScheduler, new ContentScreen(static fn(string $text): bool => SecretMasker::mask($text) !== $text), $runs));
$tools->register(new JobManageTool($jobRepository, $jobScheduler));
// spec: BRIEF.md#планировщик-и-проекты — the agent's own initiative on the schedule of INITIATIVE_SCHEDULE
if (!(new \OpenCCK\App\Job\Initiative($jobRepository, $ownerZone))->sync($principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null), TelegramChannel::NAME, $ownerDirectory->chatId(), \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'INITIATIVE_SCHEDULE') ?? '')) {
    $logger->warning('INITIATIVE_SCHEDULE is not a schedule (daily:HH:MM, every:<minutes>, weekly:...)');
}

// spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту
$scheduler->useProjects(new ProjectContext($projectRepository));
// spec: BRIEF.md#самоулучшение-решено-2026-11-18 — the agent proposes, the owner accepts; notes come into new prompts
$improvementRepository = new \OpenCCK\Infrastructure\Repository\PostgresImprovementRepository($db);
$evalScores = new \OpenCCK\Infrastructure\Repository\PostgresEvalScoreRepository($db);
$promptNotes = new \OpenCCK\App\Improve\PromptNotes($improvementRepository);
$scheduler->useNotes($promptNotes);
$traceReview = new \OpenCCK\App\Agent\Tool\Self\TraceReviewTool(new \OpenCCK\Infrastructure\Repository\PostgresTraceReviewRepository($db));
// spec: BRIEF.md#скиллы-и-mcp — the review names the skills nobody opened for a month
$traceReview->useSkills($skillLibrary);
$tools->register($traceReview);
$tools->register(new ProjectTool($projectRepository, $conversations));
$groupRepository = new \OpenCCK\Infrastructure\Repository\PostgresGroupRepository($db);
$forumTopics = new \OpenCCK\App\Service\ForumTopics($channel, $groupRepository, $projectRepository, $logger);
$tools->register(new ProjectEditTool($projectRepository, $conversations, $ownerZone, $forumTopics));
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\ForumTopicTool($channel, $conversations));
$channelRepository = new \OpenCCK\Infrastructure\Repository\PostgresChannelRepository($db);
$channelRegistry = new \OpenCCK\App\Service\ChannelRegistry($channelRepository, $channel, $ownerDirectory, $logger);
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\ChannelPostTool($channel, $channelRepository));
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
$agentKeys = new \OpenCCK\App\Peer\AgentKeys($vault, static fn(): ?Principal => $ownerDirectory->principal(), $agentIdentity);
// native WebSocket pings from this side too: every 10 s; three unanswered — the connection is dead and is closed
// spec: BRIEF.md#между-агентами — files go ahead by POST /a2a/files (http, by default) or as bytes over a WebSocket (ws):
// then a message after the handshake may reach 28 MB
$a2aFileTransport = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'A2A_FILE_TRANSPORT') === 'ws' ? 'ws' : 'http';
$a2aFrameMax = $a2aFileTransport === 'ws' ? \OpenCCK\Infrastructure\Peer\WebsocketA2aChannel::FILES_MAX : \OpenCCK\Infrastructure\Peer\WebsocketA2aChannel::FRAME_MAX;
$a2aParser = new \Amp\Websocket\Parser\Rfc6455ParserFactory(messageSizeLimit: max($a2aFrameMax, 10_485_760));
$a2aFiles = new \OpenCCK\App\Peer\A2aFiles(new \OpenCCK\Infrastructure\Repository\PostgresA2aFileRepository($db));
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(600, static function () use ($a2aFiles): void {
    $a2aFiles->sweep(new \DateTimeImmutable());
}));
$a2aConnector = new \Amp\Websocket\Client\Rfc6455Connector(new \Amp\Websocket\Client\Rfc6455ConnectionFactory(heartbeatQueue: new \Amp\Websocket\PeriodicHeartbeatQueue(heartbeatPeriod: 10, queuedPingLimit: 3), parserFactory: $a2aParser));
$a2aConnect = static function (string $url, string $token) use ($a2aConnector, $a2aFrameMax): \OpenCCK\App\Peer\A2aChannel {
    if (preg_match('~^wss?://~', $url) !== 1) {
        throw new \RuntimeException('not a websocket address');
    }
    $handshake = (new \Amp\Websocket\Client\WebsocketHandshake($url, ['authorization' => 'Bearer ' . $token]))->withTcpConnectTimeout(10)->withTlsHandshakeTimeout(10);
    return new \OpenCCK\Infrastructure\Peer\WebsocketA2aChannel($a2aConnector->connect($handshake, new \Amp\TimeoutCancellation(20)), $a2aFrameMax);
};
// spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17 — live connections both ways, shared by the client and
// the server; requests a neighbour sends back on a connection this agent opened are answered by the server
$a2aLinks = new \OpenCCK\App\Peer\A2aLinks();
$a2aServeFor = static function (string $fingerprint, string $name, string $url) use (&$a2aServer): ?\Closure {
    return $a2aServer?->handlerFor($fingerprint, $name, $url);
};
$peerNetwork = new \OpenCCK\App\Peer\PeerNetwork(
    $peerRepository,
    $principals,
    $vault,
    new \OpenCCK\App\Peer\A2aClient(HttpClients::create(), $agentIdentity, $agentKeys, $a2aConnect, $a2aLinks, $a2aServeFor, $selfUrl ?? '', $a2aFileTransport),
    $conversations,
    $transcript,
    $channel,
    $selfUrl ?? 'local:' . $telegram->ownerId,
    $logger,
    $agentIdentity,
    static fn(): ?Principal => $ownerDirectory->principal(),
    $agentKeys,
    $a2aLinks,
    $sandbox,
    static fn(int $principalId): ?\OpenCCK\Domain\Agent\SandboxLimits => ($p = $principals->find($principalId)) === null ? null : $policies->forPrincipal($p)->sandbox,
);
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerDelegateTool($peerNetwork, $peerRepository, $conversations, $sandbox));
// spec: BRIEF.md#сеть-агентов — call one neighbour and wait, or give parts to several and merge (decision В9)
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerCallTool($peerNetwork, $peerRepository, $conversations));
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerOrchestrateTool($peerNetwork, $peerRepository, $conversations));
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerLinksTool($peerNetwork));
// spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17 — an opened connection is kept: idle ones are not closed,
// dropped ones are opened again (after a restart too); WebSocket pings keep a live one through NPM
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(30, static function (string $id) use ($peerNetwork): void {
    $peerNetwork->keepAlive();
}));
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\PeerKeysTool($peerNetwork, $agentKeys));
// spec: BRIEF.md#незнакомый-агент-стучится-решение-владельца-2026-11-17 — an unknown agent knocks, the owner decides
$agentRequests = new \OpenCCK\App\Peer\AgentRequests($peerRepository, $principals, $channel, $ownerDirectory, $logger, conversations: $conversations, transcript: $transcript);
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
    sandbox: $sandbox,
    requests: $agentRequests,
    files: $a2aFiles,
);
// spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the answer of a neighbour marks the run that reads it
$peerNetwork->onAnswered($scheduler->taint(...));
if ($a2aServer !== null) {
    $scheduler->useJobRuns($a2aServer);
    $peerNetwork->onFinished($a2aServer->chainAnswered(...));
}
$dailyDigest = new DailyDigest($projectRepository, $jobRepository, new PostgresDigestJournal($db), $jobScheduler, $channel, $ownerDirectory, $digestTime, $logger);
// spec: BRIEF.md#трассировка-и-метрики — the summary counts the memory proposals waiting for the owner
$dailyDigest->useMemory($memoryStore);
$dailyDigest->useAudit($commandAudit);
$tools->register(new MemorySearchTool($recall, $audience));
$tools->register(new \OpenCCK\App\Agent\Tool\Memory\MemoryFeedbackTool($memoryStore, $audience));
$tools->register(new MemoryProposeTool($proposals, $memoryCards, $audience));
$tools->register(new HistorySearchTool(new PostgresEpisodeRepository($db), $audience));
$tools->register(new ExperienceSearchTool($experience, $audience));
$background->useScheduler($scheduler);
$approvals->onDecision($scheduler->resumeRun(...));
$visitors = new VisitorService($principals, $channel, $ownerDirectory, $logger);
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
    $ownerDirectory,
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
// spec: BRIEF.md#решения-по-доработкам-2026-11-18 — a run in a group gets no more rights than the weakest one who spoke there
$scheduler->useGroupSpeakers($groupGate->speakers(...));
// spec: BRIEF.md#группа — other bots are heard in groups (as visitors, by mention), the guard ends their loops;
// participants name the bots and visitors not to hear
$groupGate->useBots(new \OpenCCK\App\Service\BotLoopGuard());
$groupIgnores = new \OpenCCK\Infrastructure\Repository\PostgresGroupIgnoreRepository($db);
$groupGate->useIgnores($groupIgnores);
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\GroupIgnoreTool($groupIgnores, $conversations, $groupRepository));
// spec: BRIEF.md#личные-сообщения-участникам — personal messages to participants, their answers come back to who asked
$messageRelays = new \OpenCCK\Infrastructure\Repository\PostgresMessageRelayRepository($db);
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\SendMessageTool($principals, $channel, $messageRelays, TelegramChannel::NAME, $sandbox, $peerNetwork));
// spec: BRIEF.md#второй-канал-websocket-api — keys of participants for the WebSocket API
$apiKeys = new \OpenCCK\App\Api\ApiKeys(new \OpenCCK\Infrastructure\Repository\PostgresApiKeyRepository($db), $principals);
$chat = new ChatService(
    new AccessService($principals, TelegramChannel::NAME, $telegram->ownerId, $visitors),
    $conversations,
    $scheduler,
    $channel,
    $approvals,
    new TraceReports(new PostgresTraceReportRepository($db), $traces, $peerRepository, static fn(int $runId): bool => $ownerDirectory->isFormerOwner($runs->find($runId)?->principalId)),
    $logger,
    new MemoryCommands($memoryStore, $proposals, $memoryContext, $consolidation),
    new ProjectCommands($projectRepository),
    new ServerCommands($vault, $serverRepository, $commandAudit),
    new SkillCommands($skillLibrary, static fn(string $channel, string $chatId, string $thread): ?\OpenCCK\Domain\Project\Project => $skillProjects->active($channel, $chatId, $thread), $skillImporter, $checkpoints),
    new McpCommands($mcpManager, $mcpServers),
    new JobCommands($jobRepository, $jobScheduler, $vault, $dailyDigest, $config->appUrl),
    // spec: BRIEF.md#решено-в-фазе-12 — /doctor checks the installation
    new \OpenCCK\App\Service\Doctor($_ENV, $db, PATH_ROOT . '/migrations', PATH_ROOT . '/storage', HttpClients::create(), $commandAudit),
    new \OpenCCK\App\Service\SettingsCommands($settingsService, $restarter),
    new \OpenCCK\App\Service\PeerCommands($peerNetwork, $peerRepository),
    $groupGate,
    explode(':', $telegram->token(), 2)[0],
    $runMessages,
    // spec: BRIEF.md#входящие-вложения — files of messages go into the sandbox and are described to the agent; the files of
    // an answer to the agent's question go to the one who asked
    new \OpenCCK\App\Service\RelayService($messageRelays, $principals, $conversations, $scheduler, $channel, $logger, $attachmentIntake = new \OpenCCK\App\Agent\Media\AttachmentIntake($channel, $sandbox, $mediaSettings, $ownerZone, logger: $logger, maxFile: $telegram->fileLimit, models: $mediaModels, prefs: $mediaPrefs, docs: $docsService), $policies),
    $attachmentIntake,
    $policies,
    new \OpenCCK\App\Service\ApiKeyCommands($apiKeys, $principals),
    new \OpenCCK\App\Service\PromptCommands($promptNotes, $evalScores),
);
// spec: BRIEF.md#трассировка-и-метрики — spend by topics, projects, participants and periods of the owner's days
$tools->register(new \OpenCCK\App\Agent\Tool\Self\SpendingTool(new TraceReports(new PostgresTraceReportRepository($db), $traces), $runs, $runMessages, $conversations, $principals, $ownerZone->getName()));
$tools->register(new \OpenCCK\App\Agent\Tool\Self\SettingsTool($settingsService, $restarter));
$apiOwner = $principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null);
$evalJudge = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'EVAL_JUDGE_MODEL') ?? '';
$evalRuns = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'EVAL_RUNS');
// spec: BRIEF.md#границы-самоулучшения — the judge may be of any provider: jev:<model> is the judging service Jev
$jevKey = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'JEV_API_KEY');
$jevJudge = str_starts_with($evalJudge, 'jev:') && $jevKey !== null
    ? new \OpenCCK\Infrastructure\Eval\JevJudge($modelHttp, $jevKey, substr($evalJudge, 4) === '' ? 'jev-latest' : substr($evalJudge, 4))
    : null;
$promptGate = new \OpenCCK\App\Improve\PromptGate(
    new \OpenCCK\App\Eval\EvalRunner($llm, $evalJudge === '' || $jevJudge !== null ? null : $llm, $evalJudge === '' || $jevJudge !== null ? null : $evalJudge, $runs, $transcript, $conversations, $llmCalls, $apiOwner, $jevJudge),
    \OpenCCK\App\Eval\EvalTask::load(PATH_ROOT . '/resources/eval/tasks.json'),
    $llmSettings->model,
    $evalRuns !== null && ctype_digit($evalRuns) ? (int) $evalRuns : 3,
    $evalJudge !== '',
);
// spec: BRIEF.md#самоулучшение-решено-2026-11-18 — scores of the set by category for each version of the notes
$promptGate->useScores($evalScores);
$improvements = new \OpenCCK\App\Improve\Improvements(
    $improvementRepository,
    $promptNotes,
    $channel,
    $ownerDirectory,
    // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the owner may switch off the check of a prompt change by the set
    \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'IMPROVE_GATE') === '0'
        ? static fn(string $current, string $candidate): \OpenCCK\App\Improve\GateVerdict => new \OpenCCK\App\Improve\GateVerdict(true, \Mercurius\I18n\t('improve.gate_off'))
        : $promptGate(...),
    static fn(string $key, string $value): string => $settingsService->set($apiOwner, $key, $value),
    $logger,
    background: true,
    // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — self-modification may be allowed for good, kept as a grant
    granted: static fn(string $key): bool => $approvalStore->hasGrant($apiOwner->id, $key),
    grant: static function (string $key) use ($approvalStore, $apiOwner): void {
        $approvalStore->addGrant($apiOwner->id, $key);
    },
);
// spec: BRIEF.md#самоулучшение-решено-2026-11-18 — a proposal a restart left in checking goes back to the owner
\Revolt\EventLoop::defer(static function () use ($improvements, $logger): void {
    try {
        $improvements->recover();
    } catch (\Throwable $e) {
        $logger->warning('Proposals left in checking were not recovered', ['error' => $e::class]);
    }
});
$tools->register(new \OpenCCK\App\Agent\Tool\Self\ImprovementProposeTool($improvements));
$chat->useImprovements($improvements);
// spec: BRIEF.md#самоулучшение-решено-2026-11-18 — /models: the roles, their history and a rollback
$chat->useModelRoles($modelRoles);
// spec: BRIEF.md#несколько-пользователей — the owner passes ownership on by /owner and a card
$ownership = new \OpenCCK\App\Service\OwnershipTransfer($principals, $channel, $ownerDirectory, $logger);
$chat->useOwnership($ownership);
$chat->useRoles($roleCatalog);
// spec: BRIEF.md#свои-роли-решение-владельца-2026-11-19 — a holder of reports does not see the private chats of others
$chat->useRunVisibility(static function (int $runId, Principal $viewer) use ($runs, $conversations): bool {
    $run = $runs->find($runId);
    $meta = $run === null ? null : $conversations->meta($run->conversationId);
    return $run !== null && $meta !== null && ($run->principalId === $viewer->id || str_starts_with($meta->chatId, '-'));
});
if (!(new \OpenCCK\App\Job\Initiative($jobRepository, $ownerZone, title: 'self-review', promptKey: 'job.self_review.prompt'))->sync($apiOwner, TelegramChannel::NAME, $ownerDirectory->chatId(), \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'SELF_REVIEW_SCHEDULE') ?? '')) {
    $logger->warning('SELF_REVIEW_SCHEDULE is not a schedule (daily:HH:MM, every:<minutes>, weekly:...)');
}
$presses = new CallbackRouter($channel, $ownerDirectory, [\OpenCCK\App\Improve\Improvements::PREFIX => $improvements, 'ap' => $approvals, 'pg' => $approvals, 'pv' => $visitors, \OpenCCK\App\Peer\AgentRequests::PREFIX => $agentRequests, 'gr' => $groupGate, MemoryCards::PREFIX => $memoryCards, MemoryCards::FORGET => $memoryCards, HostKeyCards::PREFIX => $hostKeyCards, \OpenCCK\App\Service\OwnershipTransfer::PREFIX => $ownership], ['p' . $apiOwner->id], [MemoryCards::PREFIX, 'pv']);

$botId = (int) explode(':', $telegram->token(), 2)[0];
$parser = new UpdateParser($botId, allowBots: true);
// spec: BRIEF.md#канал-необязательные-возможности-и-разрезанные-тексты — a text Telegram split at 4096 characters is glued back (2 s, as hermes)
$dispatcher = new ChatDispatcher($chat, $logger, callbacks: $presses, memberships: new \OpenCCK\App\Service\MembershipRouter([$groupGate, $channelRegistry]), posts: $channelRegistry, splitDelay: 2.0);
// spec: BRIEF.md#канал-необязательные-возможности-и-разрезанные-тексты — updates taken are kept a day in the database:
// a redelivery after a restart is not run twice
$seenUpdates = new \OpenCCK\Infrastructure\Repository\PostgresSeenUpdates($db);
$deduplicator = new Deduplicator(firstSeen: $seenUpdates->fresh(...));
\Revolt\EventLoop::unreference(\Revolt\EventLoop::repeat(3600, static function () use ($seenUpdates): void {
    try {
        $seenUpdates->prune();
    } catch (\Throwable) {
    }
}));

$routes = [];
$webhookSecret = $telegram->webhookSecret();
if ($webhookSecret !== null) {
    $routes[] = ['POST', '/telegram/webhook', new TelegramWebhookHandler(new WebhookProcessor($webhookSecret, $parser, $dispatcher, $deduplicator, $logger))];
}

if ($a2aServer !== null) {
    $routes[] = ['GET', '/.well-known/agent-card.json', new \OpenCCK\Infrastructure\API\Handler\A2aHandler($a2aServer)];
    // spec: BRIEF.md#между-агентами — the entrance for files is there with either transport
    $routes[] = ['POST', '/a2a/files', new \OpenCCK\Infrastructure\API\Handler\A2aFilesHandler($a2aServer)];
    $routes[] = ['GET', '/a2a/files/{id:f-[0-9a-f]{24}}', new \OpenCCK\Infrastructure\API\Handler\A2aFilesHandler($a2aServer)];
    if ($a2aServer->transport === 'http') {
        $routes[] = ['POST', '/a2a', new \OpenCCK\Infrastructure\API\Handler\A2aHandler($a2aServer)];
    } else {
        $a2aSocket = new \OpenCCK\Infrastructure\API\Handler\A2aSocketHandler($a2aServer, frameMax: $a2aFrameMax);
        // the server pings every client every 10 s (three unanswered — closed): a live connection survives the proxy timeout
        $routes[] = ['GET', '/a2a/ws', static fn(\Amp\Http\Server\HttpServer $http): \Amp\Websocket\Server\Websocket => new \Amp\Websocket\Server\Websocket(
            $http,
            $logger,
            $a2aSocket,
            $a2aSocket,
            clientFactory: new \Amp\Websocket\Server\Rfc6455ClientFactory(heartbeatQueue: new \Amp\Websocket\PeriodicHeartbeatQueue(heartbeatPeriod: 10, queuedPingLimit: 3), parserFactory: $a2aParser),
        )];
    }
}

// spec: BRIEF.md#знания-агента-о-себе — the prompt holds one line, the details come from self_info by topic
if ($a2aServer !== null) {
    $selfBase = rtrim($a2aServer->self, '/');
    $selfHost = parse_url($selfBase, PHP_URL_HOST);
    $networkText = sprintf(\Mercurius\I18n\t('agent.prompt_builder.instance_a2a'), $selfBase, $a2aServer->transport === 'http' ? 'POST ' . $selfBase . '/a2a' : 'wss://' . (is_string($selfHost) ? $selfHost : '') . '/a2a/ws');
} else {
    $networkText = \Mercurius\I18n\t('agent.prompt_builder.instance_no_a2a');
}
$scheduler->useInstance(\Mercurius\I18n\t('agent.prompt_builder.self_info'));
$tools->register(new \OpenCCK\App\Agent\Tool\Self\SelfInfoTool([
    'network' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => $networkText,
    'channels' => static function (\OpenCCK\App\Agent\Tool\ToolContext $c) use ($config): string {
        $host = $config->appUrl === null ? null : parse_url($config->appUrl, PHP_URL_HOST);
        return sprintf(\Mercurius\I18n\t('self.channels'), is_string($host) ? 'wss://' . $host . '/api/ws' : '—');
    },
    'commands' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t(ChatService::GREETING),
    'tools' => static function (\OpenCCK\App\Agent\Tool\ToolContext $c) use ($tools): string {
        if ($c->policy === null) {
            return '—';
        }
        $lines = [];
        foreach ($tools->definitions($c->policy) as $definition) {
            $first = preg_split('~(?<=[.!?])\s~u', $definition->description, 2)[0] ?? $definition->description;
            $lines[] = '- ' . $definition->name . ': ' . mb_substr($first, 0, 160);
        }
        return \Mercurius\I18n\t('self.tools') . "\n" . implode("\n", $lines);
    },
    'modules' => static function (\OpenCCK\App\Agent\Tool\ToolContext $c) use ($serviceModules): string {
        $lines = [];
        foreach ($serviceModules->states() as $name => $up) {
            $lines[] = '- ' . $name . ': ' . ($up ? \Mercurius\I18n\t('self.modules.up') : \Mercurius\I18n\t('self.modules.down'));
        }
        return \Mercurius\I18n\t('self.modules') . "\n" . ($lines === [] ? '—' : implode("\n", $lines));
    },
    'limits' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.limits') . "\n" . ($c->policy === null ? '—' : json_encode($c->policy->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    'skills' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.skills'),
    'self_improvement' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.self_improvement'),
    'version' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => sprintf(\Mercurius\I18n\t('self.version'), $version, \OpenCCK\App\Service\SystemPrompt::VERSION),
]));

// spec: BRIEF.md#второй-канал-websocket-api — participants with a key talk to the agent over WebSocket
$apiSocket = new \OpenCCK\Infrastructure\API\Handler\ApiSocketHandler(new \OpenCCK\App\Api\ApiGateway($wsChannel, $apiKeys, static fn(\Mercurius\Messenger\IncomingMessage $m): bool => $dispatcher->dispatch($m), static function (\Mercurius\Messenger\IncomingCallback $c) use ($dispatcher): void {
    $dispatcher->dispatchCallback($c);
}));
$apiParser = new \Amp\Websocket\Parser\Rfc6455ParserFactory(messageSizeLimit: 30_000_000, frameSizeLimit: 30_000_000);
$routes[] = ['GET', '/api/ws', static fn(\Amp\Http\Server\HttpServer $http): \Amp\Websocket\Server\Websocket => new \Amp\Websocket\Server\Websocket(
    $http,
    $logger,
    $apiSocket,
    $apiSocket,
    clientFactory: new \Amp\Websocket\Server\Rfc6455ClientFactory(heartbeatQueue: new \Amp\Websocket\PeriodicHeartbeatQueue(heartbeatPeriod: 10, queuedPingLimit: 3), parserFactory: $apiParser),
)];

// spec: BRIEF.md#планировщик-и-проекты — a signed event starts a job by event
$routes[] = ['POST', '/hooks/{id:\d+}', new JobHookHandler(new HookReceiver($jobRepository, $jobScheduler, $vault, $principals, $logger))];

// Prometheus: GET /metrics behind METRICS_TOKEN; without the token the endpoint does not exist.
$routes[] = ['GET', '/metrics', new MetricsHandler(new MetricsText(new PostgresMetricsRepository($db)), $traceSettings->metricsToken())];

$health = new HealthService([new DatabaseHealthCheck($db)], 2.0, $logger);

(new App($logger))
    ->addHandler(fn(App $app): Server => new Server($app->getLogger(), $config->httpHost, $config->httpPort, $health, $version, $routes))
    // Before Telegram: modules stop in reverse order, so intake stops first, then runs pause.
    ->addHandler(fn(App $app): AgentModule => new AgentModule($scheduler, $app->getLogger(), $approvals, background: $background, deliveries: new \OpenCCK\App\Agent\DeliveryRecovery($deliveryRepository, $channel, $app->getLogger())))
    ->addHandler(fn(App $app): ModelCatalogModule => new ModelCatalogModule($catalogSources, $modelCatalog, $app->getLogger(), toolReliable: $toolReliableSources))
    ->addHandler(fn(App $app): TraceRetentionModule => new TraceRetentionModule($traces, $traceSettings->retentionDays, $app->getLogger()))
    ->addHandler(fn(App $app): MemoryConsolidationModule => new MemoryConsolidationModule($consolidation, $memorySettings->checkHour, $app->getLogger(), $consolidationJournal))
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
