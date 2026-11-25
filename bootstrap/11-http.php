<?php

declare(strict_types=1);

/**
 * HTTP routes: the Telegram webhook, A2A, the WebSocket API, job hooks, metrics, health.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
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
 * @var OpenCCK\Infrastructure\Repository\PostgresMcpServerRepository $mcpServers
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
 * @var OpenCCK\Infrastructure\Repository\PostgresMemberRepository $principals
 * @var OpenCCK\App\Agent\Tool\Messaging\Recipients $recipients
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\Infrastructure\Services\ServiceModules $serviceModules
 * @var OpenCCK\Infrastructure\Config\MediaSettings $mediaSettings
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\App\Mcp\McpManager $mcpManager
 * @var Mercurius\Memory\App\Aliases $memoryAliases
 * @var Closure(): list<string> $sleepModels
 * @var Closure(): list<string> $sleepAuto
 * @var OpenCCK\App\Settings\SettingsService $settingsService
 * @var OpenCCK\App\Agent\Model\ModelLists $modelLists
 * @var OpenCCK\App\Agent\Model\ModelRoles $modelRoles
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

// spec: brief/agent-loop.md#знания-агента-о-себе — the prompt holds one line, the details come from self_info by topic
if ($a2aServer !== null) {
    $selfBase = rtrim($a2aServer->self, '/');
    $selfHost = parse_url($selfBase, PHP_URL_HOST);
    $networkText = sprintf(\Mercurius\I18n\t('agent.prompt_builder.instance_a2a'), $selfBase, $a2aServer->transport === 'http' ? 'POST ' . $selfBase . '/a2a' : 'wss://' . (is_string($selfHost) ? $selfHost : '') . '/a2a/ws');
} else {
    $networkText = \Mercurius\I18n\t('agent.prompt_builder.instance_no_a2a');
}
$scheduler->useInstance(\Mercurius\I18n\t('agent.prompt_builder.self_info'));
$tools->register(new \OpenCCK\App\Agent\Tool\Self\SelfInfoTool([
    // spec: brief/network.md#события-проекта-для-агентов — the agents of a project learn what is said in its chats
    // spec: brief/network.md#потоки-мест-для-агентов — and the streams of places the owner gives to agents
    'network' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => $networkText . "\n\n" . \Mercurius\I18n\t('self.project_events') . \Mercurius\I18n\t('self.streams'),
    'channels' => static function (\OpenCCK\App\Agent\Tool\ToolContext $c) use ($config, $recipients): string {
        $host = $config->appUrl === null ? null : parse_url($config->appUrl, PHP_URL_HOST);
        $places = $recipients->places();
        return sprintf(\Mercurius\I18n\t('self.channels'), is_string($host) ? 'wss://' . $host . '/api/ws' : '—')
            // spec: brief/channels.md#сообщение-в-группу-или-тему-по-просьбе — the places are counted at the call
            . "\n\n" . ($places === [] ? \Mercurius\I18n\t('self.channels.no_places') : sprintf(\Mercurius\I18n\t('self.channels.places'), implode('; ', $places)));
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
    'modules' => static function (\OpenCCK\App\Agent\Tool\ToolContext $c) use ($serviceModules, $tools, $sleepModels, $settingsService, $sleepAuto, $mediaSettings): string {
        $states = $serviceModules->states();
        // the mail module is an MCP server: it is set up when its token is, and up while its tools are registered
        if (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MAIL_MCP_TOKEN') !== null) {
            $states['mail'] = $tools->get('mcp_mail_mail_list') !== null;
        }
        $speech = $mediaSettings->localSpeech();
        return \Mercurius\I18n\t('self.modules') . "\n" . implode("\n", \OpenCCK\App\Service\ModuleReport::lines($states))
            // spec: brief/internet.md#модуль-whisper-без-настройки — what the running module gives, said at the call
            . ($speech === null ? '' : "\n" . sprintf(\Mercurius\I18n\t('self.modules.whisper'), $speech, implode(', ', $mediaSettings->models('speech'))))
            // spec: brief/memory.md#страж-потерь — sleep runs while a model is listed for it; the guard always
            . "\n" . ($sleepModels() === [] ? \Mercurius\I18n\t('self.modules.sleep_off') : sprintf(\Mercurius\I18n\t('self.modules.sleep_on'), implode(', ', $sleepModels()))
                . ' ' . \Mercurius\I18n\t($settingsService->current('MEMORY_SLEEP_TRANSLATE') === '1' ? 'self.modules.sleep_translate_on' : 'self.modules.sleep_translate_off')
                . ' ' . ($sleepAuto() === [] ? \Mercurius\I18n\t('self.modules.sleep_auto_none') : sprintf(\Mercurius\I18n\t('self.modules.sleep_auto'), implode(', ', $sleepAuto()))));
    },
    // spec: brief/participants.md#свои-роли — the role and its permissions of the one the agent talks to
    'limits' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.limits') . "\n" . \OpenCCK\App\Service\RoleCatalog::ofMember($c->principal->role) . "\n" . ($c->policy === null ? '—' : json_encode($c->policy->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    'skills' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.skills'),
    'self_improvement' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.self_improvement'),
    // spec: brief/participants.md#доступ-к-проектам-и-передача — how projects are shared and handed over, on demand
    'projects' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.projects'),
    // spec: brief/internet.md#входы-в-браузере-и-профиль-проекта — how to log in and which profile is used
    'browser' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.browser'),
    // spec: brief/models.md#списки-моделей-по-категориям — the categories of models and what the owner allowed in each, as of now
    'models' => static function (\OpenCCK\App\Agent\Tool\ToolContext $c) use ($modelLists, $modelRoles, $sleepModels): string {
        $parts = [];
        foreach (\OpenCCK\App\Agent\Model\ModelLists::TEXT as $role => $setting) {
            $parts[] = sprintf('%s (%s): %s; выбрана %s', $role, $setting, implode(', ', array_map(static fn(\OpenCCK\Domain\Agent\ModelRef $m): string => (string) $m, $modelLists->of($role))), (string) $modelRoles->model($role));
        }
        foreach (\OpenCCK\App\Agent\Model\ModelLists::OTHER as $category => $setting) {
            // the list of sleep is read at each pass, so it is shown as it is now
            $list = $category === 'sleep' ? $sleepModels() : $modelLists->other($category);
            $parts[] = sprintf('%s (%s): %s', $category, $setting, implode(', ', $list) ?: '—');
        }
        // which providers answer now, and what a subscription has: a model the owner names is looked for there first
        $providers = [];
        foreach ($modelRoles->providers() as $provider) {
            $providers[] = in_array($provider, \OpenCCK\Domain\Agent\ModelRef::SUBSCRIPTIONS, true)
                ? sprintf(\Mercurius\I18n\t('self.models.subscription'), $provider, implode(', ', $modelRoles->catalogOf($provider)) ?: '—')
                : $provider;
        }
        return \Mercurius\I18n\t('self.models') . "\n\n" . sprintf(\Mercurius\I18n\t('self.models.providers'), implode('; ', $providers))
            . "\n\n" . sprintf(\Mercurius\I18n\t('self.models.now'), "\n- " . implode("\n- ", $parts));
    },
    // spec: brief/skills-mcp.md#ящики-участников-и-проектов — how a mailbox is connected and who confirms sending; whether
    // the tools are there and whether the one who asks may use them is counted at the call
    'mail' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.mail') . "\n\n" . sprintf(
        \Mercurius\I18n\t('self.mail.now'),
        $tools->get('mcp_mail_mail_list') !== null
            ? \Mercurius\I18n\t('self.modules.up')
            : (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MAIL_MCP_TOKEN') !== null ? \Mercurius\I18n\t('self.modules.down') : sprintf(\Mercurius\I18n\t('self.modules.off'), 'mail', 'MAIL_MCP_TOKEN')),
        \OpenCCK\App\Mail\Mailboxes::may($c->principal) ? \Mercurius\I18n\t('self.mail.power_yes') : \Mercurius\I18n\t('self.mail.power_no'),
    ),
    // spec: brief/memory.md#словари — where memory keeps what, and the owner's table of words as it is now
    'memory' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.memory') . "\n\n"
        . \Mercurius\I18n\t('self.memory.aliases') . "\n" . $memoryAliases->list(),
    'version' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => sprintf(\Mercurius\I18n\t('self.version'), $version, \OpenCCK\App\Service\SystemPrompt::VERSION),
    // spec: brief/agent-loop.md#запреты — the terms of grants, the grants of areas and the bans, on demand
    'permissions' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.permissions'),
    // spec: brief/scheduler.md#место-итога-задания — how jobs are set, moved and where their reports go
    'jobs' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.jobs'),
    // spec: brief/orchestration.md#этапы-и-план — how long work is kept: the plan and the workers
    'work' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \Mercurius\I18n\t('self.work'),
    // spec: brief/agent-loop.md#прокси-для-команд-песочницы — whether the door is open and where it leads, as of now
    'proxy' => static fn(\OpenCCK\App\Agent\Tool\ToolContext $c): string => \OpenCCK\Infrastructure\Proxy\DoorSettings::about($_ENV),
    // spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — how an entry is written and approved; the connections of
    //       the caller's context and their states are read at the call
    'mcp' => static function (\OpenCCK\App\Agent\Tool\ToolContext $c) use ($mcpServers, $mcpManager): string {
        $context = $c->policy->mcpContext ?? 'member:' . $c->principal->id;
        $lines = [];
        foreach ($mcpServers->inContext($context) as $server) {
            $tools = count($mcpManager->tools($server->key()));
            $lines[] = '- ' . $server->name . ': ' . \Mercurius\I18n\t('service.mcp_commands.state.' . $server->status) . ($tools > 0 ? ', ' . $tools : '') . ($server->note === '' ? '' : ' (' . mb_substr($server->note, 0, 160) . ')');
        }
        return sprintf(\Mercurius\I18n\t('self.mcp'), $context) . "\n" . ($lines === [] ? \Mercurius\I18n\t('self.mcp.none') : implode("\n", $lines));
    },
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

// spec: brief/scheduler.md#как-сделано-задания-по-событию-и-проекты — a signed event starts a job by event
$routes[] = ['POST', '/hooks/{id:\d+}', new JobHookHandler(new HookReceiver($jobRepository, $jobScheduler, $vault, $principals, $logger))];

// Prometheus: GET /metrics behind METRICS_TOKEN; without the token the endpoint does not exist.
$routes[] = ['GET', '/metrics', new MetricsHandler(new MetricsText(new PostgresMetricsRepository($db), $serviceModules->states(...), $mcpServers->all(...)), $traceSettings->metricsToken())];

$health = new HealthService([new DatabaseHealthCheck($db)], 2.0, $logger);
