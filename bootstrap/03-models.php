<?php

declare(strict_types=1);

/**
 * Clients of the model providers, the catalog of models, model roles and the choice of a conversation's model.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Llm\LlmException;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Model\RequestClassifier;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Llm\ProviderClients;
use OpenCCK\Infrastructure\Llm\ProviderRouter;
use OpenCCK\Infrastructure\Repository\PostgresConversationRepository;
use OpenCCK\Infrastructure\Repository\PostgresLlmCallRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelRoleRepository;
use OpenCCK\Infrastructure\Repository\PostgresRoutingDecisionRepository;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\Infrastructure\Config\Config $config
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var OpenCCK\App\Vault\Vault $vault
 * @var OpenCCK\App\Settings\SettingsService $settingsService
 * @var non-empty-string $version
 */

// Models: one client per provider with a key (its profile says which), chosen by the model's prefix; all go through VLESS when VLESS_URI is set.
// spec: brief/models.md#провайдеры-моделей-openrouter-и-openai
$modelHttp = HttpClients::create($llmSettings->routeUri(), $logger);
$onRetry = static fn(LlmException $e, int $attempt, float $delay): null => $logger->warning(
    'Retrying model call',
    ['kind' => $e->kind->value, 'attempt' => $attempt, 'delay' => $delay],
);
$providerClients = ProviderClients::build($llmSettings, $modelHttp, $config->appUrl, $onRetry);
$clients = $providerClients->clients;
$catalogSources = $providerClients->catalogs;
$toolReliableSources = $providerClients->toolReliable;
// spec: brief/models.md#провайдеры-моделей-openrouter-и-openai — the owner's ChatGPT subscription: chatgpt:<model>, signed in
// by /codex login; its tokens live in the vault, so without the vault it is not offered
$codexAccount = null;
$modelProviders = $llmSettings->providers();
if ($vault->enabled()) {
    $codexAccount = new \OpenCCK\Infrastructure\Llm\ChatGpt\CodexAccount($modelHttp, $vault, static fn(): ?\OpenCCK\Domain\Entity\Member => $ownerDirectory->principal());
    $clients[\OpenCCK\Domain\Agent\ModelRef::CHATGPT] = new \Mercurius\Llm\RetryingClient(new \OpenCCK\Infrastructure\Llm\ChatGpt\CodexClient($modelHttp, $codexAccount, $version), onRetry: $onRetry);
    $catalogSources[\OpenCCK\Domain\Agent\ModelRef::CHATGPT] = (new \OpenCCK\Infrastructure\Llm\ChatGpt\CodexCatalog($modelHttp, $codexAccount))->fetch(...);
    $modelProviders[] = \OpenCCK\Domain\Agent\ModelRef::CHATGPT;
}
// spec: brief/models.md#claude-по-подписке — the owner's Claude subscription: claude:<model>, served by the module
// claude-code (the official Claude Code behind a relay). Without the token of the module there is no provider claude:.
// The models go into work only when the owner puts them into the lists of the categories: no roles by default.
$claudeSubscription = null;
$claudeToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'CLAUDE_CODE_TOKEN');
$claudeUrl = rtrim(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'CLAUDE_CODE_URL') ?? 'http://claude-code:8094', '/');
// spec: brief/models.md#лимиты-подписки-и-переход-на-модель-категории — a used-up window takes the provider off the
// choice until it reopens; the owner hears once per window (who tells him is set with the channel, in bootstrap/10-chat.php)
$providerPauses = new \OpenCCK\App\Agent\Model\ProviderPauses(null, null, static fn(string $provider): string => $provider === \OpenCCK\Domain\Agent\ModelRef::CLAUDE
    ? ($settingsService->current('CLAUDE_CODE_EXTRA_USAGE') ?? 'stop') . '|' . ($settingsService->current('CLAUDE_CODE_EXTRA_USAGE_RESERVE_PERCENT') ?? '')
    : '');
if ($claudeToken !== null) {
    $claudeHttp = \OpenCCK\Infrastructure\Llm\Claude\ClaudeSubscription::http(
        static fn(): ?string => \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'CLAUDE_CODE_OAUTH_TOKEN'),
        static fn(): string => $settingsService->current('CLAUDE_CODE_EXTRA_USAGE') ?? 'stop',
        static fn(): string => $settingsService->current('CLAUDE_CODE_EXTRA_USAGE_RESERVE_PERCENT') ?? '',
    );
    $claudeSubscription = new \OpenCCK\Infrastructure\Llm\Claude\ClaudeSubscription($claudeHttp, $claudeUrl, $claudeToken, $logger);
    // one try: the service answers a limit with the time it reopens, and a repeat of an overload is the service's own
    $clients[\OpenCCK\Domain\Agent\ModelRef::CLAUDE] = new \Mercurius\Llm\RetryingClient(
        new \Mercurius\Llm\Anthropic\AnthropicClient($claudeHttp, $claudeToken, $claudeUrl . '/v1', name: 'Claude subscription'),
        maxAttempts: 2,
        onRetry: $onRetry,
    );
    $claudeModels = new \Mercurius\Llm\ModelCatalog\AnthropicCatalog($claudeHttp, $claudeToken, new \Mercurius\Llm\ModelCatalog\OpenRouterCatalog($modelHttp), $claudeUrl . '/v1');
    $catalogSources[\OpenCCK\Domain\Agent\ModelRef::CLAUDE] = (new \OpenCCK\Infrastructure\Llm\Claude\ClaudeCatalog(static fn(): array => $claudeModels->fetch()))->fetch(...);
    $modelProviders[] = \OpenCCK\Domain\Agent\ModelRef::CLAUDE;
}
// spec: brief/models.md#google-по-подписке — the owner's Google Antigravity plan: antigravity:<model>, served by the
// module antigravity (the official CLI as the model of one call). Without the token of the module there is no provider.
// The models go into work only when the owner puts them into the lists of the categories: no roles by default.
$antigravitySubscription = null;
$antigravityToken = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'ANTIGRAVITY_TOKEN');
$antigravityUrl = rtrim(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'ANTIGRAVITY_URL') ?? 'http://antigravity:8095', '/');
if ($antigravityToken !== null) {
    // the service is on the internal network: the client goes directly, without the route of the models
    $antigravityHttp = (new \Amp\Http\Client\HttpClientBuilder())->retry(0)->followRedirects(0)->build();
    $antigravitySubscription = new \OpenCCK\Infrastructure\Llm\Antigravity\AntigravitySubscription($antigravityHttp, $antigravityUrl, $antigravityToken, $logger);
    // two tries: the service answers a limit with the time it reopens and a broken rule with an error that is not
    // repeated; an overload may pass
    $clients[\OpenCCK\Domain\Agent\ModelRef::ANTIGRAVITY] = new \Mercurius\Llm\RetryingClient(
        new \Mercurius\Llm\Antigravity\AntigravityClient($antigravityHttp, $antigravityToken, $antigravityUrl . '/v1'),
        maxAttempts: 2,
        onRetry: $onRetry,
    );
    $catalogSources[\OpenCCK\Domain\Agent\ModelRef::ANTIGRAVITY] = $antigravitySubscription->models(...);
    $modelProviders[] = \OpenCCK\Domain\Agent\ModelRef::ANTIGRAVITY;
}
$openRouterKey = $llmSettings->openRouterKey();
$openAiKey = $llmSettings->openAiKey();
// Model catalog per provider, model roles, the choice of a conversation's model.
$modelCatalog = new PostgresModelCatalogRepository($db);
// The subscription waits for /codex login: until then its catalog is not asked for, then it loads at once.
$modelCatalogModule = new \OpenCCK\Infrastructure\Module\ModelCatalogModule(
    $catalogSources,
    $modelCatalog,
    $logger,
    toolReliable: $toolReliableSources,
    connected: [
        ...($codexAccount === null ? [] : [\OpenCCK\Domain\Agent\ModelRef::CHATGPT => $codexAccount->signedIn(...)]),
        ...($claudeSubscription === null ? [] : [\OpenCCK\Domain\Agent\ModelRef::CLAUDE => $claudeSubscription->signedIn(...)]),
        ...($antigravitySubscription === null ? [] : [\OpenCCK\Domain\Agent\ModelRef::ANTIGRAVITY => $antigravitySubscription->signedIn(...)]),
    ],
);
// spec: brief/models.md#реестр-провайдеров — providers that come and go (MCP); the names of the installation are reserved
$providerRegistry = new \OpenCCK\App\Agent\Model\ProviderRegistry(array_values(array_unique([...\OpenCCK\App\Agent\Model\ModelCatalogView::KNOWN, ...array_keys($clients)])));
$llm = new ProviderRouter($clients, $modelCatalog, $providerPauses, $providerRegistry);
$routingDecisions = new PostgresRoutingDecisionRepository($db);
// spec: brief/agent-loop.md#модели — a change of the model of a role reaches the open conversations of that role
// spec: brief/models.md#списки-моделей-по-категориям — the agent calls only the models the owner listed
// spec: brief/models.md#списки-моделей-по-категориям — read live: a list changed in the dialog works at once
$modelLists = \OpenCCK\App\Agent\Model\ModelLists::live(static fn(string $setting): ?string => $settingsService->current($setting), $llmSettings->model);
$modelRoles = new ModelRoles(new PostgresModelRoleRepository($db), $modelCatalog, $modelProviders, new \OpenCCK\App\Agent\Model\RoleConversations(new PostgresConversationRepository($db), $routingDecisions, $logger), $modelLists);
$modelRoles->useRegistry($providerRegistry);
$modelListEditor = new \OpenCCK\App\Agent\Model\ModelListEditor($settingsService, $modelLists, $modelRoles);
// a model the owner names for a role joins its list through the editor
$modelRoles->widenWith(static function (string $role, string $model, \OpenCCK\Domain\Entity\Member $by) use ($modelListEditor): void {
    $modelListEditor->change($by, $role, 'add', [$model]);
});
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
$logger->info('Model route', ['route' => $llmSettings->routeUri() === null ? 'direct' : 'vless', 'providers' => $modelProviders, 'model' => $llmSettings->model]);
