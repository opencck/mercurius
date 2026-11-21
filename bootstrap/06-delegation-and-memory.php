<?php

declare(strict_types=1);

/**
 * Workers and background delegation, the run scheduler, the memory and its nightly consolidation.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\Delegation\BackgroundDelegations;
use OpenCCK\App\Agent\Delegation\DelegateTaskTool;
use OpenCCK\App\Agent\Delegation\WorkerReportTool;
use OpenCCK\App\Agent\Delegation\WorkerRunner;
use OpenCCK\App\Agent\MemoryCards;
use OpenCCK\App\Agent\Model\ModelSelector;
use OpenCCK\App\Agent\RunScheduler;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Infrastructure\Repository\PostgresDelegationRepository;
use OpenCCK\Infrastructure\Repository\PostgresExperienceRepository;
use OpenCCK\Infrastructure\Repository\PostgresModelReliabilityRepository;
use OpenCCK\Infrastructure\Repository\PostgresProjectRepository;
use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\MemoryContext;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\App\Recall;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\PostgresConsolidationJournal;
use Mercurius\Memory\Infrastructure\PostgresMemoryStore;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\Infrastructure\Config\AgentSettings $agentSettings
 * @var OpenCCK\App\Agent\ApprovalService $approvals
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var OpenCCK\Infrastructure\Repository\PostgresConversationRepository $conversations
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var OpenCCK\Infrastructure\Repository\PostgresDeliveryRepository $deliveryRepository
 * @var OpenCCK\Infrastructure\Llm\ProviderRouter $llm
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var OpenCCK\App\Agent\AgentLoop $loop
 * @var OpenCCK\Infrastructure\Config\MemorySettings $memorySettings
 * @var OpenCCK\Infrastructure\Repository\PostgresModelCatalogRepository $modelCatalog
 * @var OpenCCK\App\Agent\Model\ModelRoles $modelRoles
 * @var OpenCCK\App\Agent\Model\ModelRouter $modelRouter
 * @var OpenCCK\App\Settings\SettingsService $settingsService
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var OpenCCK\App\Service\PolicyService $policies
 * @var OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository $principals
 * @var OpenCCK\Infrastructure\Repository\PostgresRoutingDecisionRepository $routingDecisions
 * @var OpenCCK\Infrastructure\Repository\PostgresRunMessageRepository $runMessages
 * @var OpenCCK\Infrastructure\Repository\PostgresRunRepository $runs
 * @var OpenCCK\Infrastructure\Sandbox\HttpSandboxClient|null $sandbox
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\App\Agent\Trace\Tracer $tracer
 * @var OpenCCK\Infrastructure\Repository\PostgresTraceRepository $traces
 * @var OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository $transcript
 */

// Delegation: workers run through the same loop, so the tool is registered once the loop exists.
$workers = new WorkerRunner($loop, $runs, $transcript, $conversations, $tools, $modelRoles, $agentSettings->workerMaxTurns, $agentSettings->workerTokenBudget, $logger, new ModelSelector($modelCatalog, $modelRoles, $routingDecisions, $logger, new PostgresModelReliabilityRepository($db)), $agentSettings->maxWorkersPerRun);
// spec: brief/orchestration.md#белый-список-инструментов-воркера — a worker gets only tools of the white list
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
// spec: brief/memory.md#словари — the owner's table of words that mean one thing, before facts are keyed again
$memoryAliases = new \Mercurius\Memory\App\Aliases(
    new \Mercurius\Memory\Infrastructure\PostgresAliasStore($db),
    $textAnalyzer,
    $memoryStore,
    new ContentScreen(static fn(string $text): bool => SecretMasker::mask($text) !== $text),
);
try {
    $memoryAliases->load();
} catch (Throwable $e) {
    $logger->warning('Memory aliases were not read', ['error' => $e::class]);
}
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
// spec: brief/memory.md#ключи-и-однокоренные — facts written before a new rule of the analyzer get its keys; in the background
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
// spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту — the facts of the active project of a chat, for
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
// spec: brief/memory.md#файл-памяти-места — MEMORY.md at the root of the folder of the context: the participant's own in their
// private chat, the project's or the place's for everyone there; no sandbox — no file
$contextMemory = null;
if ($sandbox !== null) {
    $contextMemory = new \OpenCCK\App\Agent\ContextMemory(
        $sandbox,
        static function (int $principalId) use ($principals, $policies): ?\OpenCCK\Domain\Agent\SandboxLimits {
            $principal = $principals->find($principalId);
            return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
        },
        $projectRepository,
        static fn(): ?int => $ownerDirectory->principal()?->id,
    );
    $scheduler->useMemoryFile(static function (\OpenCCK\Domain\Entity\Principal $principal) use ($contextMemory): string {
        $place = $contextMemory->personal($principal);
        return $place === null ? '' : $contextMemory->read($place);
    }, $memorySettings->fileLimit);
    $scheduler->usePlaceMemory(static function (\OpenCCK\Domain\Entity\Principal $principal, string $channel, string $chatId, string $threadId, bool $private) use ($contextMemory): string {
        $place = $contextMemory->shared($principal, $channel, $chatId, $threadId, $private);
        return $place === null ? '' : $contextMemory->read($place);
    });
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
// spec: brief/memory.md#консолидация-dreaming — the card says on how many days the person said it, by their own messages
$corroboration = new \Mercurius\Memory\App\Corroboration($textAnalyzer);
$memoryCards->useCorroboration(static function (int $principalId, string $text) use ($corroboration, $transcript): int {
    $zone = new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'OWNER_TIMEZONE') ?? '+03:00');
    $since = time() - \Mercurius\Memory\App\Corroboration::WINDOW_DAYS * 86400;
    return $corroboration->days($text, $transcript->spokenBy($principalId, $since, \Mercurius\Memory\App\Corroboration::LINES), $zone);
});
// spec: brief/memory.md#факты — every N turns a cheap model proposes facts through the owner's card
$factNudgeTurns = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MEMORY_NUDGE_TURNS');
$factNudge = new \OpenCCK\App\Agent\FactNudge(
    $llm,
    static fn(): string => (string) $modelRoles->model(ModelRole::CHEAP),
    $transcript,
    $conversations,
    $proposals,
    $memoryCards,
    $audience,
    $logger,
    is_numeric($factNudgeTurns) ? max(0, min(1000, (int) $factNudgeTurns)) : \OpenCCK\App\Agent\FactNudge::EVERY,
);
// spec: brief/memory.md#одно-знание--в-одно-место — a standing instruction goes to the rules of the file of its place, not into a fact
if ($contextMemory !== null) {
    $factNudge->useMemoryFiles($contextMemory);
}
$scheduler->useAfterRun($factNudge->afterRun(...));
// spec: brief/memory.md#сообщение-об-изменении-правил — a change of the rules of a MEMORY.md is told when the owner keeps it on
$rulesNotice = null;
if ($contextMemory !== null) {
    $rulesNotice = new \OpenCCK\App\Agent\MemoryRulesNotice(
        $contextMemory,
        new \OpenCCK\Infrastructure\Repository\PostgresMemoryRuleRepository($db),
        $conversations,
        $audience,
        $channel,
        $principals,
        $ownerDirectory,
        static fn(): bool => $settingsService->current('MEMORY_RULES_NOTICE') === '1',
        $logger,
        \Mercurius\Messenger\Telegram\TelegramChannel::NAME,
    );
    $scheduler->useAfterRun($rulesNotice->afterRun(...));
}
// spec: brief/memory.md#запись-сведения-о-людях-и-забывание — a forgotten fact also leaves the transcripts it was recalled into
$experience = new PostgresExperienceRepository($db);
$workers->useExperience($experience);
// spec: brief/operations.md#шаги-и-хранение — a worker is a step of its orchestrator's trace
$workers->useEvents($tracer);
// spec: brief/orchestration.md#права-воркера-задаёт-оркестратор — the conversation's context only when the orchestrator asks
$workers->useContext($scheduler->workerContext(...));
// spec: brief/orchestration.md#часть-1--синхронное-делегирование — reports share what is left of the orchestrator's window
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
    // spec: brief/memory.md#запись-сведения-о-людях-и-забывание — and the copies of it: arguments of calls and nightly reports
    $transcript->redactCalls($fact->text, \Mercurius\I18n\t('memory.forgotten'));
    $consolidationJournal->redact($fact->text, \Mercurius\I18n\t('memory.forgotten'));
});
// spec: brief/memory.md#страж-потерь — the guard every night and on command, with or without sleep
$lossGuard = new \Mercurius\Memory\App\LossGuard(
    $memoryStore,
    new \Mercurius\Memory\Infrastructure\PostgresGuardJournal($db),
    static fn(string $text): bool => $channel->send(new OutgoingMessage($ownerDirectory->chatId(), $text))->success,
);
// spec: brief/models.md#списки-моделей-по-категориям — sleep runs while the owner lists a model for it, read at each pass
$sleepModels = static function () use ($settingsService): array {
    $list = $settingsService->current('MEMORY_SLEEP_MODELS') ?? '';
    return array_values(array_filter(array_map('trim', explode(',', $list)), static fn(string $m): bool => $m !== ''));
};
$sleeps = static fn(): bool => $sleepModels() !== [];
// spec: brief/memory.md#консолидация-dreaming — the nightly check: code only, the owner decides
$consolidation = new Consolidation(
    $memoryStore,
    $proposals,
    $consolidationJournal,
    $memoryCards->show(...),
    static function (string $text) use ($channel, $ownerDirectory): bool {
        return $channel->send(new OutgoingMessage($ownerDirectory->chatId(), $text))->success;
    },
);
// spec: brief/memory.md#судья-пары — the model of sleep judges the gray zone; its verdicts are kept while the texts stay
$verdictCache = new \Mercurius\Memory\Infrastructure\PostgresVerdictCache($db);
// spec: brief/memory.md#слияние — the same model writes the text of a merge; the code checks it before the card
$sleepJudge = new \OpenCCK\App\Agent\LlmSleepJudge($llm, static function () use ($sleepModels): array {
    $refs = [];
    foreach ($sleepModels() as $model) {
        try {
            $refs[] = (string) ModelRef::parse($model);
        } catch (\InvalidArgumentException) {
            continue;
        }
    }
    return $refs;
}, $logger);
$consolidation->useJudge(new \Mercurius\Memory\App\CachedJudge($sleepJudge, $verdictCache, \OpenCCK\App\Agent\LlmSleepJudge::PROMPT_VERSION));
$consolidation->useMerger($sleepJudge);
$proposals->onForget(static fn(Fact $fact) => $verdictCache->forgetFact($fact->id));
