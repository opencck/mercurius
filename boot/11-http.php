<?php

declare(strict_types=1);

/**
 * HTTP routes: the Telegram webhook, A2A, the WebSocket API, job hooks, metrics, health.
 *
 * A part of the start of the agent: index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\Telegram\WebhookProcessor;
use OpenCCK\App\Job\HookReceiver;
use OpenCCK\App\Service\ChatService;
use OpenCCK\App\Service\HealthService;
use OpenCCK\App\Service\MetricsText;
use OpenCCK\Infrastructure\API\Handler\JobHookHandler;
use OpenCCK\Infrastructure\API\Handler\MetricsHandler;
use OpenCCK\Infrastructure\API\Handler\TelegramWebhookHandler;
use OpenCCK\Infrastructure\Database\DatabaseHealthCheck;
use OpenCCK\Infrastructure\Repository\PostgresMetricsRepository;

/**
 * Made by the parts before this one:
 *
 * @var 262144|28000000 $a2aFrameMax
 * @var Amp\Websocket\Parser\Rfc6455ParserFactory $a2aParser
 * @var OpenCCK\App\Peer\A2aServer|null $a2aServer
 * @var OpenCCK\App\Api\ApiKeys $apiKeys
 * @var OpenCCK\Infrastructure\Config\Config $config
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var Mercurius\Messenger\Deduplicator $deduplicator
 * @var Mercurius\Messenger\ChatDispatcher $dispatcher
 * @var OpenCCK\Infrastructure\Repository\PostgresJobRepository $jobRepository
 * @var OpenCCK\App\Job\JobScheduler $jobScheduler
 * @var Monolog\Logger $logger
 * @var Mercurius\Messenger\Telegram\UpdateParser $parser
 * @var OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository $principals
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\Infrastructure\Services\ServiceModules $serviceModules
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\Infrastructure\Config\TraceSettings $traceSettings
 * @var OpenCCK\App\Vault\Vault $vault
 * @var non-empty-string $version
 * @var Mercurius\Messenger\Websocket\WebsocketChannel $wsChannel
 */

$routes = [];
$webhookSecret = $telegram->webhookSecret();
if ($webhookSecret !== null) {
    $routes[] = ['POST', '/telegram/webhook', new TelegramWebhookHandler(new WebhookProcessor($webhookSecret, $parser, $dispatcher, $deduplicator, $logger))];
}

if ($a2aServer !== null) {
    $routes[] = ['GET', '/.well-known/agent-card.json', new \OpenCCK\Infrastructure\API\Handler\A2aHandler($a2aServer)];
    // spec: brief/internet.md#между-агентами — the entrance for files is there with either transport
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

// spec: brief/agent-loop.md#агентский-цикл-и-харнесс — the prompt holds one line, the details come from self_info by topic
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

// spec: brief/operations.md#второй-канал-websocket-api — participants with a key talk to the agent over WebSocket
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

// spec: brief/scheduler.md#планировщик-и-проекты-1 — a signed event starts a job by event
$routes[] = ['POST', '/hooks/{id:\d+}', new JobHookHandler(new HookReceiver($jobRepository, $jobScheduler, $vault, $principals, $logger))];

// Prometheus: GET /metrics behind METRICS_TOKEN; without the token the endpoint does not exist.
$routes[] = ['GET', '/metrics', new MetricsHandler(new MetricsText(new PostgresMetricsRepository($db)), $traceSettings->metricsToken())];

$health = new HealthService([new DatabaseHealthCheck($db)], 2.0, $logger);
