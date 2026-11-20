<?php

declare(strict_types=1);

/**
 * Workers and background delegation, the run scheduler, the memory and its nightly consolidation.
 *
 * A part of the start of the agent: index.php includes the parts in order, in one scope. A part reads what the parts
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
// spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — a worker gets only tools of the white list
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
// spec: brief/memory.md#анализ-текста — facts written before a new rule of the analyzer get its keys; in the background
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
// spec: brief/memory.md#всегда-в-контексте — the participant's MEMORY.md, whole, with a limit; no sandbox — no file
if ($sandbox !== null) {
    $scheduler->useMemoryFile(static function (\OpenCCK\Domain\Entity\Principal $principal) use ($sandbox, $policies): string {
        $limits = $policies->forPrincipal($principal)->sandbox;
        if ($limits === null) {
            return '';
        }
        try {
            return $sandbox->fetch($principal->id, $limits, 'MEMORY.md', 400_000, new \Amp\TimeoutCancellation(5));
        } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure) {
            return ''; // no file yet
        }
    }, $memorySettings->fileLimit);
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
// spec: brief/memory.md#память-слои — every N turns a cheap model proposes facts through the owner's card
$factNudgeTurns = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'MEMORY_NUDGE_TURNS');
$scheduler->useAfterRun((new \OpenCCK\App\Agent\FactNudge(
    $llm,
    static fn(): string => (string) $modelRoles->model(ModelRole::CHEAP),
    $transcript,
    $conversations,
    $proposals,
    $memoryCards,
    $audience,
    $logger,
    is_numeric($factNudgeTurns) ? max(0, min(1000, (int) $factNudgeTurns)) : \OpenCCK\App\Agent\FactNudge::EVERY,
))->afterRun(...));
// spec: brief/memory.md#память-слои — a forgotten fact also leaves the transcripts it was recalled into
$experience = new PostgresExperienceRepository($db);
$workers->useExperience($experience);
// spec: brief/operations.md#настройки-агента — a worker is a step of its orchestrator's trace
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
    // spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — and the copies of it: arguments of calls and nightly reports
    $transcript->redactCalls($fact->text, \Mercurius\I18n\t('memory.forgotten'));
    $consolidationJournal->redact($fact->text, \Mercurius\I18n\t('memory.forgotten'));
});
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
