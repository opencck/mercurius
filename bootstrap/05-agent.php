<?php

declare(strict_types=1);

/**
 * The agent: runs, tools, the sandbox, tracing, approvals, media models, the browser and the agent loop.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\Telegram\TelegramChannel;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\ApprovalService;
use OpenCCK\App\Agent\Context\ContextCompactor;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\SpendGuard;
use OpenCCK\App\Agent\Tool\Agents\SetParticipantPolicyTool;
use OpenCCK\App\Agent\Tool\Files\ListFilesTool;
use OpenCCK\App\Agent\Tool\Files\ReadFileTool;
use OpenCCK\App\Agent\Tool\Files\WriteFileTool;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\Sandbox\RunCommandTool;
use OpenCCK\App\Agent\Tool\Self\SetModelRoleTool;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\Web\BrowserTool;
use OpenCCK\App\Agent\Tool\Web\HttpRequestTool;
use OpenCCK\App\Agent\Tool\Web\WebReadTool;
use OpenCCK\App\Agent\Tool\Web\WebSearchTool;
use OpenCCK\App\Agent\Trace\Tracer;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\App\Web\TextExtractor;
use OpenCCK\Infrastructure\Browser\BrowserClient;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Repository\PostgresApprovalRepository;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresPolicyRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Infrastructure\Repository\PostgresTraceRepository;
use OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository;
use OpenCCK\Infrastructure\Sandbox\HttpSandboxClient;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\Infrastructure\Config\AgentSettings $agentSettings
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var array<string, Mercurius\Llm\LlmClient> $clients
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var OpenCCK\Infrastructure\Llm\ProviderRouter $llm
 * @var OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository $llmCalls
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository $modelCatalog
 * @var Amp\Http\Client\HttpClient $modelHttp
 * @var OpenCCK\App\Agent\Model\ModelRoles $modelRoles
 * @var OpenCCK\App\Agent\Model\ModelRouter $modelRouter
 * @var string|null $openAiKey
 * @var string|null $openRouterKey
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository $principals
 * @var OpenCCK\Infrastructure\Repository\PostgresRoutingDecisionRepository $routingDecisions
 * @var OpenCCK\Infrastructure\Config\SandboxSettings $sandboxSettings
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\App\Vault\Vault $vault
 */

// Agent: runs, tools, scheduler.
$conversations = new PostgresConversationRepository($db);
$runs = new PostgresRunRepository($db);
$runMessages = new \OpenCCK\Infrastructure\Repository\PostgresRunMessageRepository($db);
$deliveryRepository = new \OpenCCK\Infrastructure\Repository\PostgresDeliveryRepository($db);
$transcript = new PostgresTranscriptRepository($db);
// spec: brief/participants.md#свои-роли — the owner's list of roles: titles, order, powers, templates
$roleCatalog = new \OpenCCK\App\Service\RoleCatalog(new \OpenCCK\Infrastructure\Repository\PostgresRoleRepository($db));
$roleCatalog->load();
$policies = new PolicyService(new PostgresPolicyRepository($db), $agentSettings->ownerLimits(), $sandboxSettings->ownerLimits(), $roleCatalog->template(...));
$addressGuard = new AddressGuard();
$tools = new ToolRegistry();
// spec: brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы — module services from .env; a tool of a module only while its service is up,
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
// spec: brief/agent-loop.md#подтверждения-и-запреты — a host the owner allowed is dialled through a guard that lets it alone in
$guardedClient = static fn(AddressGuard $guard): \Amp\Http\Client\HttpClient => HttpClients::guarded($guard);
// spec: brief/servers.md#вход-по-логину-и-паролю — cookies of http_request live with the project of the conversation,
//       else with the participant, like browser profiles
$httpCookieProjects = new PostgresProjectRepository($db);
$httpCookieScope = static function (\OpenCCK\App\Agent\Tool\ToolContext $context) use ($conversations, $httpCookieProjects): string {
    $meta = $conversations->meta($context->conversationId);
    $project = $meta === null ? null : $httpCookieProjects->active($meta->channel, $meta->chatId, $meta->threadId);
    return $project === null ? 'p' . $context->principal->id : 'project:' . $project->id;
};
$tools->register(new HttpRequestTool(HttpClients::guarded($addressGuard), $addressGuard, $vault, $guardedClient, new \OpenCCK\Infrastructure\Repository\PostgresHttpCookieRepository($db), $httpCookieScope));
// spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — pages as text; search by the model's web search on OpenRouter
$tools->register(new WebReadTool(HttpClients::guarded($addressGuard), $addressGuard, new TextExtractor(), $guardedClient));
// spec: brief/participants.md#решения-владельца-о-правах — search providers in the order of SEARCH_PROVIDERS
[$webSearch, $searchSkipped, $searchEach] = \OpenCCK\Infrastructure\Config\SearchSettings::build($_ENV, $modelHttp, $llmSettings->openRouterKey());
if ($searchSkipped !== []) {
    $logger->warning('Search providers without settings are skipped', ['providers' => $searchSkipped]);
}
if ($webSearch !== null) {
    $webSearchTool = new WebSearchTool($webSearch, $searchEach, array_map('\Mercurius\I18n\t', \OpenCCK\Infrastructure\Config\SearchSettings::ABOUT));
    $webSearchTool->useAccounting($llmCalls, $runs);
    $tools->register($webSearchTool);
}
// spec: brief/participants.md#выдача-прав-и-владение — rights are given only in the owner's private chat: the owner's own
//      chat in Telegram or over the WebSocket API, not a group, a channel, a job or a neighbour's task
$ownerPrivateChat = static function (int $conversationId) use ($conversations): bool {
    $meta = $conversations->meta($conversationId);
    return $meta !== null && in_array($meta->channel, [TelegramChannel::NAME, \Mercurius\Messenger\Websocket\WebsocketChannel::NAME], true) && !str_starts_with($meta->chatId, '-');
};
$tools->register(new SetParticipantPolicyTool($principals, $policies, $ownerPrivateChat));
// spec: brief/network.md#участник-просит-инструмент — a participant without a tool asks the owner for it
$tools->register(new \OpenCCK\App\Agent\Tool\Agents\RequestToolTool($tools, $policies));
// spec: brief/internet.md#как-влияют-пользователи — the owner's media models and principles, each participant's own way with files
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
    // spec: brief/scheduler.md#git-с-доступом-из-хранилища — git with a token of the vault in the environment of one command
    $tools->register(new \OpenCCK\App\Agent\Tool\Sandbox\GitTool($sandbox, $sandbox, $vault, $agentSettings->toolTimeout));
    // spec: brief/agent-loop.md#песочница — a file the agent made goes to the chat it was asked in
    // spec: brief/internet.md#между-агентами — in a task of another agent a file goes with the answer
    $tools->register(new \OpenCCK\App\Agent\Tool\Messaging\SendFileTool($sandbox, $channel, $conversations, new \OpenCCK\App\Agent\Tool\Messaging\Recipients($principals, TelegramChannel::NAME), static function (int $runId, string $name, string $bytes) use (&$a2aServer): bool {
        return $a2aServer instanceof \OpenCCK\App\Peer\A2aServer && $a2aServer->answerFile($runId, $name, $bytes);
    }));
}
// spec: brief/channels.md#личные-сообщения-участникам — a location, a contact, a poll, an article: what the messenger carries
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\SendContentTool($channel, $conversations, new \OpenCCK\App\Agent\Tool\Messaging\Recipients($principals, TelegramChannel::NAME)));
$logger->info('Sandbox', ['enabled' => $sandbox !== null]);
// Tracing: steps of every run, masked payloads.
$traces = new PostgresTraceRepository($db);
$tracer = new Tracer($traces, $modelCatalog, $logger);
// Approvals: cards in the owner's private chat (in Telegram its id is the owner's user id).
$approvalStore = new PostgresApprovalRepository($db);
$approvals = new ApprovalService($approvalStore, $principals, $channel, $ownerDirectory, $agentSettings->approvalTimeout, $logger, $tracer);
// spec: brief/skills-mcp.md#ящики-участников-и-проектов — a card about a participant's own mailbox goes to their private chat
$approvals->useParticipantCards(TelegramChannel::NAME);
// spec: brief/internet.md#модели-вложений — vision, speech and video models through the models' HTTP client (VLESS when set);
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
// spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — the browser module (profile browser), offered while its service answers
$browserToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_TOKEN');
$browserUrl = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_URL') ?? 'http://browser:3000';
if ($serviceModules->add(BrowserTool::MODULE, $browserUrl, $browserToken) && $browserToken !== null) {
    $browserClient = new BrowserClient(HttpClients::create(), $browserUrl, $browserToken);
    // spec: brief/agent-loop.md#раскладка-каталогов — profiles of the old flat layout go into the owner's set, logins stay
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
        // spec: brief/agent-loop.md#раскладка-каталогов — each participant has profiles of their own and of their project
        static function (int $conversationId) use ($conversations, $httpCookieProjects): ?int {
            $meta = $conversations->meta($conversationId);
            return $meta === null ? null : $httpCookieProjects->active($meta->channel, $meta->chatId, $meta->threadId)?->id;
        },
        // spec: brief/decisions.md#принятые-владельцем-риски — browser confirmations are off unless the owner turns them on
        \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'BROWSER_CONFIRM') === '1',
        // spec: brief/internet.md#входы-в-браузере-и-профиль-проекта — the profiles of a project are those of its owner for everyone
        //       who works in it: the owner logs in once, the jobs and the members of the project use that login
        static function (int $projectId, \OpenCCK\Domain\Entity\Principal $who) use ($httpCookieProjects, $principals): ?int {
            $project = $httpCookieProjects->find($projectId);
            $level = $project?->levelOf($who, $httpCookieProjects->memberLevel($projectId, $who->id));
            if ($project === null || $level?->atLeast(\OpenCCK\Domain\Project\ProjectLevel::Write) !== true) {
                return null;
            }
            return $project->ownerId ?? $principals->owner()?->id;
        },
    ));
}
if ($sandbox !== null) {
    $tools->register(new \OpenCCK\App\Agent\Tool\Files\MediaTool($sandbox, $mediaModels, $mediaPrefs, new \OpenCCK\Infrastructure\Media\VideoService(HttpClients::create(), $serviceModules), $docsService));
    // spec: brief/models.md#категории-моделей — the image category: offered only while it has a model
    if ($mediaSettings->models('image') !== []) {
        $tools->register(new \OpenCCK\App\Agent\Tool\Files\ImageGenerateTool($sandbox, $mediaModels, $mediaSettings));
    }
    // spec: brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы — new docx, xlsx, pptx by the media-docs module, while it runs
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
    // spec: brief/scheduler.md#снимки-рабочего-каталога — a snapshot of the working folder before the first change of each run
    new ToolExecutor(logger: $logger, sandbox: $sandbox, checkpoints: $checkpoints),
    $llmSettings->maxTokens,
    $llmSettings->reasoningEffort === 'none' ? null : $llmSettings->reasoningEffort,
    $llmSettings->historyLimit,
    $logger,
    approvals: $approvalStore,
    gate: $approvals,
    // spec: brief/agent-loop.md#контекст — tool results are bounded by the window of the model
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
// spec: brief/agent-loop.md#инструменты — models that answer in prose are told to act through tools
$loop->useToolUseGuidance(new \OpenCCK\App\Agent\ToolUseGuidance(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'AGENT_TOOL_USE_GUIDANCE') ?? 'auto'));
// spec: brief/skills-mcp.md#mcp-ресурсы-и-поиск-инструментов — over 5% of the window the tools of MCP servers go behind tool_search
$tools->register(new \OpenCCK\App\Agent\Tool\ToolSearchTool(\OpenCCK\App\Agent\Tool\ToolSearchTool::SEARCH, $tools));
$tools->register(new \OpenCCK\App\Agent\Tool\ToolSearchTool(\OpenCCK\App\Agent\Tool\ToolSearchTool::DESCRIBE, $tools));
$loop->useToolSearch(new \OpenCCK\App\Agent\Tool\ToolSearch($modelRouter->window(...)));
