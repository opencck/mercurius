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
use OpenCCK\App\Service\AccessService;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\HealthService;
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
use OpenCCK\Infrastructure\Module\TelegramModule;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository;
use OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository;

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

$chat = new ChatService(
    new AccessService(new PostgresPrincipalRepository($db), TelegramChannel::NAME, $telegram->ownerId),
    new PostgresConversationRepository($db),
    new PostgresLlmCallRepository($db),
    $llm,
    $channel,
    $llmSettings->model,
    $llmSettings->reasoningEffort === 'none' ? null : $llmSettings->reasoningEffort,
    $llmSettings->maxTokens,
    $llmSettings->historyLimit,
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
    ->addHandler(fn(App $app): TelegramModule => new TelegramModule(
        $telegramApi,
        $telegram,
        new LongPolling($telegramApi, $parser, $dispatcher, $deduplicator, $app->getLogger()),
        $app->getLogger(),
    ))
    ->run();

$db->close();
