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
use OpenCCK\App\Agent\Delegation\WorkerRunner;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelSelector;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Model\RequestClassifier;
use OpenCCK\App\Agent\RunScheduler;
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
use OpenCCK\Infrastructure\Module\TraceRetentionModule;
use OpenCCK\Infrastructure\Module\TelegramModule;
use OpenCCK\Infrastructure\Repository\PostgresApprovalRepository;
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
use OpenCCK\Infrastructure\Sandbox\HttpSandboxClient;
use Revolt\EventLoop;

require __DIR__ . '/vendor/autoload.php';

const PATH_ROOT = __DIR__;

Dotenv::createImmutable(PATH_ROOT)->safeLoad();

try {
    $config = Config::fromEnv($_ENV);
    $telegram = TelegramSettings::fromEnv($_ENV, $config->appUrl);
    $llmSettings = LlmSettings::fromEnv($_ENV);
    $agentSettings = AgentSettings::fromEnv($_ENV);
    $sandboxSettings = SandboxSettings::fromEnv($_ENV);
    $traceSettings = TraceSettings::fromEnv($_ENV);
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
$principals = new PostgresPrincipalRepository($db);
$conversations = new PostgresConversationRepository($db);
$runs = new PostgresRunRepository($db);
$transcript = new PostgresTranscriptRepository($db);
$policies = new PolicyService(new PostgresPolicyRepository($db), $agentSettings->ownerLimits(), $sandboxSettings->ownerLimits());
$addressGuard = new AddressGuard();
$tools = new ToolRegistry();
$tools->register(new HttpRequestTool(HttpClients::guarded($addressGuard), $addressGuard));
$tools->register(new SetParticipantPolicyTool($principals, $policies));
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
);
// Delegation: workers run through the same loop, so the tool is registered once the loop exists.
$workers = new WorkerRunner($loop, $runs, $transcript, $conversations, $tools, $modelRoles, $agentSettings->workerMaxTurns, $agentSettings->workerTokenBudget, $logger, new ModelSelector($modelCatalog, $modelRoles, $routingDecisions, $logger, new PostgresModelReliabilityRepository($db)));
$background = new BackgroundDelegations($workers, new PostgresDelegationRepository($db), $runs, $principals, $policies, $logger);
$tools->register(new DelegateTaskTool($workers, $background));
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
);
$scheduler->useApprovals($approvals);
$scheduler->useBackground($background);
$background->useScheduler($scheduler);
$approvals->onDecision($scheduler->resumeRun(...));
$visitors = new VisitorService($principals, $channel, $telegram->ownerId, $logger);
$chat = new ChatService(
    new AccessService($principals, TelegramChannel::NAME, $telegram->ownerId, $visitors),
    $conversations,
    $scheduler,
    $channel,
    $approvals,
    new TraceReports(new PostgresTraceReportRepository($db), $traces),
    $logger,
);
$presses = new CallbackRouter($channel, $telegram->ownerId, ['ap' => $approvals, 'pg' => $approvals, 'pv' => $visitors]);

$botId = (int) explode(':', $telegram->token(), 2)[0];
$parser = new UpdateParser($botId);
$dispatcher = new ChatDispatcher($chat, $logger, callbacks: $presses);
$deduplicator = new Deduplicator();

$routes = [];
$webhookSecret = $telegram->webhookSecret();
if ($webhookSecret !== null) {
    $routes[] = ['POST', '/telegram/webhook', new TelegramWebhookHandler(new WebhookProcessor($webhookSecret, $parser, $dispatcher, $deduplicator, $logger))];
}

// Prometheus: GET /metrics behind METRICS_TOKEN; without the token the endpoint does not exist.
$routes[] = ['GET', '/metrics', new MetricsHandler(new MetricsText(new PostgresMetricsRepository($db)), $traceSettings->metricsToken())];

$health = new HealthService([new DatabaseHealthCheck($db)], 2.0, $logger);

(new App($logger))
    ->addHandler(fn(App $app): Server => new Server($app->getLogger(), $config->httpHost, $config->httpPort, $health, $version, $routes))
    // Before Telegram: modules stop in reverse order, so intake stops first, then runs pause.
    ->addHandler(fn(App $app): AgentModule => new AgentModule($scheduler, $app->getLogger(), $approvals, background: $background))
    ->addHandler(fn(App $app): ModelCatalogModule => new ModelCatalogModule($catalogSources, $modelCatalog, $app->getLogger()))
    ->addHandler(fn(App $app): TraceRetentionModule => new TraceRetentionModule($traces, $traceSettings->retentionDays, $app->getLogger()))
    ->addHandler(fn(App $app): TelegramModule => new TelegramModule(
        $telegramApi,
        $telegram,
        new LongPolling($telegramApi, $parser, $dispatcher, $deduplicator, $app->getLogger()),
        $app->getLogger(),
    ))
    ->run();

$db->close();
