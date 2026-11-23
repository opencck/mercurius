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
    $codexAccount = new \OpenCCK\Infrastructure\Llm\ChatGpt\CodexAccount($modelHttp, $vault, static fn(): ?\OpenCCK\Domain\Entity\Principal => $ownerDirectory->principal());
    $clients[\OpenCCK\Domain\Agent\ModelRef::CHATGPT] = new \Mercurius\Llm\RetryingClient(new \OpenCCK\Infrastructure\Llm\ChatGpt\CodexClient($modelHttp, $codexAccount, $version), onRetry: $onRetry);
    $catalogSources[\OpenCCK\Domain\Agent\ModelRef::CHATGPT] = (new \OpenCCK\Infrastructure\Llm\ChatGpt\CodexCatalog($modelHttp, $codexAccount))->fetch(...);
    $modelProviders[] = \OpenCCK\Domain\Agent\ModelRef::CHATGPT;
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
    connected: $codexAccount === null ? [] : [\OpenCCK\Domain\Agent\ModelRef::CHATGPT => $codexAccount->signedIn(...)],
);
$llm = new ProviderRouter($clients, $modelCatalog);
$routingDecisions = new PostgresRoutingDecisionRepository($db);
// spec: brief/agent-loop.md#модели — a change of the model of a role reaches the open conversations of that role
// spec: brief/models.md#списки-моделей-по-категориям — the agent calls only the models the owner listed
// spec: brief/models.md#списки-моделей-по-категориям — read live: a list changed in the dialog works at once
$modelLists = \OpenCCK\App\Agent\Model\ModelLists::live(static fn(string $setting): ?string => $settingsService->current($setting), $llmSettings->model);
$modelRoles = new ModelRoles(new PostgresModelRoleRepository($db), $modelCatalog, $modelProviders, new \OpenCCK\App\Agent\Model\RoleConversations(new PostgresConversationRepository($db), $routingDecisions, $logger), $modelLists);
$modelListEditor = new \OpenCCK\App\Agent\Model\ModelListEditor($settingsService, $modelLists, $modelRoles);
// a model the owner names for a role joins its list through the editor
$modelRoles->widenWith(static function (string $role, string $model, \OpenCCK\Domain\Entity\Principal $by) use ($modelListEditor): void {
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
$logger->info('Model route', ['route' => $llmSettings->routeUri() === null ? 'direct' : 'vless', 'providers' => $llmSettings->providers(), 'model' => $llmSettings->model]);
