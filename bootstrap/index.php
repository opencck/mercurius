<?php

declare(strict_types=1);

/**
 * Mercurius — an autonomous personal agent: the entry point of its process.
 *
 * The start goes in parts, one file each in bootstrap/, in this order. They share one scope: a part reads what the parts
 * before it made (each names them in its header) and leaves what it makes to the parts after it. Nothing waits for a
 * request here: the parts build the services, and the last step below runs them until the process is asked to stop.
 *
 *   01  environment            .env, the start checks, Telegram and the vault key, the log, the version
 *   02  database               an import the owner confirmed, the database and its migrations, participants, the vault,
 *                              settings from the dialog over .env, the agent's language
 *   03  models                 provider clients, the catalog of models, model roles, the model of a conversation
 *   04  channel                Telegram and the WebSocket API behind one channel
 *   05  agent                  runs, tools, the sandbox, tracing, approvals, media models, the browser, the agent loop
 *   06  delegation and memory  workers, the run scheduler, memory and its nightly consolidation
 *   07  servers, skills, MCP   SSH with the command policy, skills, MCP servers, the built-in mail
 *   08  jobs and projects      jobs by schedule and by event, self-improvement, groups, forum topics, channels
 *   09  peers                  the agent network over A2A: keys, client and server, connections, unknown agents
 *   10  chat                   visitors and groups, chat commands, the evaluation gate, export and import
 *   11  http                   routes: the Telegram webhook, A2A, the WebSocket API, job hooks, metrics, health
 *
 * spec: brief/architecture.md#устройство-приложения-на-opencck
 */

use Mercurius\Messenger\Telegram\LongPolling;
use OpenCCK\Infrastructure\API\App;
use OpenCCK\Infrastructure\API\Server;
use OpenCCK\Infrastructure\Module\AgentModule;
use OpenCCK\Infrastructure\Module\MemoryConsolidationModule;
use OpenCCK\Infrastructure\Module\ModelCatalogModule;
use OpenCCK\Infrastructure\Module\SchedulerModule;
use OpenCCK\Infrastructure\Module\TelegramModule;
use OpenCCK\Infrastructure\Module\TraceRetentionModule;

require dirname(__DIR__) . '/vendor/autoload.php';

/** The folder of the application: storage/, migrations/, skills/ and .env are found from here. */
const PATH_ROOT = __DIR__ . '/..';

// 01 — .env, the start checks, Telegram and the vault key, the log, the version
require __DIR__ . '/01-environment.php';
// 02 — an import the owner confirmed, the database and its migrations, participants, the vault, settings, language
require __DIR__ . '/02-database.php';
// 03 — provider clients, the catalog of models, model roles, the model of a conversation
require __DIR__ . '/03-models.php';
// 04 — Telegram and the WebSocket API behind one channel
require __DIR__ . '/04-channel.php';
// 05 — runs, tools, the sandbox, tracing, approvals, media models, the browser, the agent loop
require __DIR__ . '/05-agent.php';
// 06 — workers, the run scheduler, memory and its nightly consolidation
require __DIR__ . '/06-delegation-and-memory.php';
// 07 — SSH with the command policy, skills, MCP servers, the built-in mail
require __DIR__ . '/07-servers-skills-mcp.php';
// 08 — jobs by schedule and by event, self-improvement, groups, forum topics, channels
require __DIR__ . '/08-jobs-and-projects.php';
// 09 — the agent network over A2A
require __DIR__ . '/09-peers.php';
// 10 — visitors and groups, chat commands, the evaluation gate, export and import, Telegram updates
require __DIR__ . '/10-chat.php';
// 11 — HTTP routes and health
require __DIR__ . '/11-http.php';

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\App\Peer\A2aServer|null $a2aServer
 * @var OpenCCK\App\Agent\ApprovalService $approvals
 * @var OpenCCK\App\Agent\Delegation\BackgroundDelegations $background
 * @var array<string, Closure(): list<Mercurius\Llm\ModelCatalog\ModelInfo>> $catalogSources
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var OpenCCK\Infrastructure\Config\Config $config
 * @var Mercurius\Memory\App\Consolidation $consolidation
 * @var Mercurius\Memory\Infrastructure\PostgresConsolidationJournal $consolidationJournal
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var Mercurius\Messenger\Deduplicator $deduplicator
 * @var OpenCCK\Infrastructure\Repository\PostgresDeliveryRepository $deliveryRepository
 * @var Mercurius\Messenger\ChatDispatcher $dispatcher
 * @var OpenCCK\App\Service\GroupGate $groupGate
 * @var OpenCCK\App\Service\HealthService $health
 * @var OpenCCK\Infrastructure\Repository\PostgresJobRepository $jobRepository
 * @var OpenCCK\App\Job\JobScheduler $jobScheduler
 * @var Monolog\Logger $logger
 * @var OpenCCK\Infrastructure\Config\MemorySettings $memorySettings
 * @var OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository $modelCatalog
 * @var Mercurius\Messenger\Telegram\UpdateParser $parser
 * @var OpenCCK\App\Peer\PeerNetwork $peerNetwork
 * @var OpenCCK\App\Settings\Restarter $restarter
 * @var list<array{non-empty-string, non-empty-string, Amp\Http\Server\RequestHandler|Closure(Amp\Http\Server\HttpServer): Amp\Http\Server\RequestHandler}> $routes
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var Mercurius\Messenger\Telegram\TelegramApi $telegramApi
 * @var array<string, Closure(): list<string>> $toolReliableSources
 * @var OpenCCK\Infrastructure\Config\TraceSettings $traceSettings
 * @var OpenCCK\Infrastructure\Repository\PostgresTraceRepository $traces
 * @var Mercurius\Memory\App\LossGuard $lossGuard
 * @var Closure(): bool $sleeps
 * @var non-empty-string $version
 */

// The services run until the process is asked to stop (SIGTERM, or /restart); modules stop in reverse order.
(new App($logger))
    ->addHandler(fn(App $app): Server => new Server($app->getLogger(), $config->httpHost, $config->httpPort, $health, $version, $routes))
    // Before Telegram: modules stop in reverse order, so intake stops first, then runs pause.
    ->addHandler(fn(App $app): AgentModule => new AgentModule($scheduler, $app->getLogger(), $approvals, background: $background, deliveries: new \OpenCCK\App\Agent\DeliveryRecovery($deliveryRepository, $channel, $app->getLogger())))
    ->addHandler(fn(App $app): ModelCatalogModule => new ModelCatalogModule($catalogSources, $modelCatalog, $app->getLogger(), toolReliable: $toolReliableSources))
    ->addHandler(fn(App $app): TraceRetentionModule => new TraceRetentionModule($traces, $traceSettings->retentionDays, $app->getLogger()))
    ->addHandler(fn(App $app): MemoryConsolidationModule => new MemoryConsolidationModule($consolidation, $memorySettings->checkHour, $app->getLogger(), $consolidationJournal, $lossGuard, $sleeps))
    ->addHandler(fn(App $app): SchedulerModule => new SchedulerModule($jobScheduler, $jobRepository, $app->getLogger()))
    ->addHandler(fn(App $app): \OpenCCK\Infrastructure\Module\PeerModule => new \OpenCCK\Infrastructure\Module\PeerModule($peerNetwork, $a2aServer, $app->getLogger()))
    ->addHandler(fn(App $app): TelegramModule => new TelegramModule(
        $telegramApi,
        $telegram,
        new LongPolling($telegramApi, $parser, $dispatcher, $deduplicator, $app->getLogger()),
        $app->getLogger(),
        // Telegram out of reach at start: the bot's name comes with the connection, mentions in groups then work
        static function (array $me) use ($groupGate): void {
            if (isset($me['username']) && is_string($me['username'])) {
                $groupGate->knowBotName($me['username']);
            }
        },
    ))
    ->run($restarter);

$db->close();
