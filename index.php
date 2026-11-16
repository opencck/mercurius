<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Mercurius\Llm\LlmException;
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
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\Tool\AddressGuard;
use OpenCCK\App\Agent\Tool\HttpRequestTool;
use OpenCCK\App\Agent\Tool\SetParticipantPolicyTool;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\HealthService;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\API\Handler\TelegramWebhookHandler;
use OpenCCK\Infrastructure\API\Server;
use OpenCCK\Infrastructure\Config\AgentSettings;
use OpenCCK\Infrastructure\Config\Config;
use OpenCCK\Infrastructure\Config\ConfigException;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Config\TelegramSettings;
use OpenCCK\Infrastructure\Database\Database;
use OpenCCK\Infrastructure\Database\DatabaseHealthCheck;
use OpenCCK\Infrastructure\Database\Migrator;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Logger\LoggerFactory;
use OpenCCK\Infrastructure\Module\AgentModule;
use OpenCCK\Infrastructure\Module\TelegramModule;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository;
use OpenCCK\Infrastructure\Repository\PostgresPolicyRepository;
use OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository;
use Revolt\EventLoop;

require __DIR__ . '/vendor/autoload.php';

const PATH_ROOT = __DIR__;

Dotenv::createImmutable(PATH_ROOT)->safeLoad();

try {
    $config = Config::fromEnv($_ENV);
    $telegram = TelegramSettings::fromEnv($_ENV, $config->appUrl);
    $llmSettings = LlmSettings::fromEnv($_ENV);
    $agentSettings = AgentSettings::fromEnv($_ENV);
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

// Model: OpenRouter, through VLESS when VLESS_URI is set.
$llm = new RetryingClient(
    new OpenRouterClient(HttpClients::create($llmSettings->routeUri(), $logger), $llmSettings->apiKey(), appName: 'Mercurius', appUrl: $config->appUrl),
    onRetry: static fn(LlmException $e, int $attempt, float $delay) => $logger->warning(
        'Retrying model call',
        ['kind' => $e->kind->value, 'attempt' => $attempt, 'delay' => $delay],
    ),
);
$logger->info('Model route', ['route' => $llmSettings->routeUri() === null ? 'direct' : 'vless', 'model' => $llmSettings->model]);

// Channel: Telegram.
$telegramApi = new TelegramApi($telegram->token(), HttpClients::create());
$channel = new TelegramChannel($telegramApi, logger: $logger);

// Agent: runs, tools, scheduler.
$principals = new PostgresPrincipalRepository($db);
$conversations = new PostgresConversationRepository($db);
$runs = new PostgresRunRepository($db);
$transcript = new PostgresTranscriptRepository($db);
$policies = new PolicyService(new PostgresPolicyRepository($db), $agentSettings->ownerLimits());
$addressGuard = new AddressGuard();
$tools = new ToolRegistry();
$tools->register(new HttpRequestTool(HttpClients::guarded($addressGuard), $addressGuard));
$tools->register(new SetParticipantPolicyTool($principals, $policies));
$loop = new AgentLoop(
    $llm,
    $runs,
    $transcript,
    $conversations,
    new PostgresLlmCallRepository($db),
    $tools,
    new ToolExecutor(logger: $logger),
    $llmSettings->maxTokens,
    $llmSettings->reasoningEffort === 'none' ? null : $llmSettings->reasoningEffort,
    $llmSettings->historyLimit,
    $logger,
);
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
);
$chat = new ChatService(
    new AccessService($principals, TelegramChannel::NAME, $telegram->ownerId),
    $conversations,
    $scheduler,
    $channel,
    $logger,
);

$botId = (int) explode(':', $telegram->token(), 2)[0];
$parser = new UpdateParser($botId);
$dispatcher = new ChatDispatcher($chat, $logger);
$deduplicator = new Deduplicator();

$routes = [];
$webhookSecret = $telegram->webhookSecret();
if ($webhookSecret !== null) {
    $routes[] = ['POST', '/telegram/webhook', new TelegramWebhookHandler(new WebhookProcessor($webhookSecret, $parser, $dispatcher, $deduplicator, $logger))];
}

$health = new HealthService([new DatabaseHealthCheck($db)], 2.0, $logger);

(new App($logger))
    ->addHandler(fn(App $app): Server => new Server($app->getLogger(), $config->httpHost, $config->httpPort, $health, $version, $routes))
    // Before Telegram: modules stop in reverse order, so intake stops first, then runs pause.
    ->addHandler(fn(App $app): AgentModule => new AgentModule($scheduler, $app->getLogger()))
    ->addHandler(fn(App $app): TelegramModule => new TelegramModule(
        $telegramApi,
        $telegram,
        new LongPolling($telegramApi, $parser, $dispatcher, $deduplicator, $app->getLogger()),
        $app->getLogger(),
    ))
    ->run();

$db->close();
