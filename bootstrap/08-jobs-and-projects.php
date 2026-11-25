<?php

declare(strict_types=1);

/**
 * Jobs by schedule and by event, self-improvement, groups, forum topics and channels.
 *
 * A part of the start of the agent: bootstrap/index.php includes the parts in order, in one scope. A part reads what the parts
 * before it made and leaves what it makes to the parts after it.
 */

use Mercurius\Messenger\Telegram\TelegramChannel;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Projects\JobCreateTool;
use OpenCCK\App\Agent\Tool\Projects\JobManageTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectTool;
use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Web\TextExtractor;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Repository\PostgresJobRepository;
use Mercurius\Memory\Domain\ContentScreen;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\App\Agent\Tool\Guard\AddressGuard $addressGuard
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var OpenCCK\Infrastructure\Repository\PostgresConversationRepository $conversations
 * @var OpenCCK\App\Agent\ContextMemory|null $contextMemory
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var OpenCCK\Infrastructure\Config\MemorySettings $memorySettings
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var OpenCCK\App\Agent\AgentLoop $loop
 * @var OpenCCK\App\Agent\Model\ModelRoles $modelRoles
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var OpenCCK\App\Service\PolicyService $policies
 * @var OpenCCK\App\Service\RoleCatalog $roleCatalog
 * @var OpenCCK\Infrastructure\Repository\PostgresMemberRepository $principals
 * @var OpenCCK\Infrastructure\Repository\PostgresProjectRepository $projectRepository
 * @var OpenCCK\App\Vault\Vault $vault
 * @var OpenCCK\Infrastructure\Repository\PostgresRunRepository $runs
 * @var OpenCCK\Infrastructure\Sandbox\HttpSandboxClient|null $sandbox
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\App\Skill\SkillLibrary $skillLibrary
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\App\Agent\Tool\Messaging\Recipients $recipients
 * @var OpenCCK\App\Agent\ApprovalService $approvals
 * @var OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository $transcript
 * @var OpenCCK\Infrastructure\Repository\PostgresProjectSleepRepository $projectSleep
 * @var OpenCCK\Infrastructure\Repository\PostgresContextLimitRepository $contextLimitRepository
 */

// spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — jobs by a schedule, run without the owner, reported by the scheduler
$ownerZone = new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'OWNER_TIMEZONE') ?? '+03:00');
$quietRaw = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'QUIET_HOURS') ?? '23-8';
$quietHours = preg_match('~^(\d{1,2})-(\d{1,2})$~', $quietRaw, $q) === 1 ? [(int) $q[1] % 24, (int) $q[2] % 24] : null;
$jobRepository = new PostgresJobRepository($db);
$jobTokens = max(1000, (int) (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'JOB_TOKEN_BUDGET') ?? '300000'));
// spec: brief/scheduler.md#наблюдение-за-страницей — a watched address is read through the guard of internal addresses, as text
$jobWatchHttp = HttpClients::guarded($addressGuard);
$jobWatchText = new TextExtractor();
// spec: brief/scheduler.md#инициатива-агента — initiative messages: a daily cap of reports the owner did not ask for now
$proactivePerDay = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'INITIATIVE_PER_DAY');
$proactiveBudget = new \OpenCCK\App\Job\ProactiveBudget($proactivePerDay !== null && ctype_digit($proactivePerDay) ? (int) $proactivePerDay : 6, $ownerZone);
// spec: brief/participants.md#несколько-пользователей — a participant's own time zone and quiet hours for their jobs
$participantPrefs = new \OpenCCK\Infrastructure\Repository\PostgresParticipantPrefRepository($db);
$participantClock = new \OpenCCK\App\Job\ParticipantClock($participantPrefs, $ownerZone, $quietHours);
// spec: brief/scheduler.md#часовой-пояс-проекта — a project keeps its own clock, the environment's by default
$projectPrefs = new \OpenCCK\Infrastructure\Repository\PostgresProjectPrefRepository($db);
$participantClock->useProjects($projectPrefs);
$tools->register(new \OpenCCK\App\Agent\Tool\Memory\MyPreferencesTool($participantPrefs, $participantClock, $contextLimitRepository));
$jobScheduler = new JobScheduler($jobRepository, $loop, $runs, $transcript, $conversations, $principals, $policies, $tools, $channel, $llmSettings->model, $ownerZone, $quietHours, $logger, tokenBudget: $jobTokens, budget: $proactiveBudget, clock: $participantClock, fetch: static function (string $url) use ($jobWatchHttp, $jobWatchText): string {
    $request = new \Amp\Http\Client\Request($url);
    $request->setTransferTimeout(30);
    $request->setBodySizeLimit(2_000_000);
    $response = $jobWatchHttp->request($request);
    $body = $response->getBody()->buffer(limit: 2_000_000);
    if ($response->getStatus() >= 400) {
        throw new \RuntimeException('HTTP ' . $response->getStatus());
    }
    return $jobWatchText->extract($body, $response->getHeader('content-type') ?? '', $url)[1];
});
$jobScheduler->useSkills($skillLibrary);
// spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — a job marked cheap runs on the model of the cheap role
$jobScheduler->useModels(static fn(string $role): string => (string) $modelRoles->model($role));
// spec: brief/participants.md#контекст-по-месту-и-аудитории — the memory core only for a job that reports to its owner's private chat
$jobScheduler->useCore(static function (Member $who, \OpenCCK\Domain\Job\Job $job) use ($scheduler, $principals): string {
    $private = $job->threadId === '' && $principals->externalId($who->id, $job->channel) === $job->chatId;
    return $private ? $scheduler->coreOf($who) : '';
});
// spec: brief/memory.md#файл-памяти-места — a job of a project gets the project's MEMORY.md
if ($contextMemory !== null) {
    $jobScheduler->usePlaceMemory(static function (Member $who, string $channel, string $chat) use ($contextMemory, $memorySettings): array {
        $place = $contextMemory->shared($who, $channel, $chat, '', false);
        if ($place === null || $place->kind !== \OpenCCK\App\Agent\MemoryPlace::PROJECT) {
            return ['', ''];
        }
        return \OpenCCK\App\Agent\RunScheduler::memoryBlocks(\OpenCCK\App\Agent\MemoryFile::parse($contextMemory->read($place)), $memorySettings->fileLimit, 'place');
    });
}
// spec: brief/scheduler.md#задания-проекта — a job of a project runs in the name of the project's owner and reports to them
$participantInbox = new \OpenCCK\App\Service\ParticipantInbox($channel, $principals, TelegramChannel::NAME, $logger);
$jobScheduler->useProjects($projectRepository);
$jobScheduler->useInbox(static fn(Member $to, string $text): bool => $participantInbox->tell($to, $text));
$jobText = new ContentScreen(static fn(string $text): bool => SecretMasker::mask($text) !== $text);
$tools->register(new JobCreateTool($jobRepository, $conversations, $jobScheduler, $jobText, $runs, $projectRepository));
$jobManage = new JobManageTool($jobRepository, $jobScheduler, $projectRepository, $jobText, $runs, static function (int $jobId) use ($approvals): void {
    $approvals->forgetArea('job:' . $jobId);
});
// spec: brief/scheduler.md#место-итога-задания — a job reports where its setter chose
$jobManage->usePlaces($conversations, $principals);
$tools->register($jobManage);
// spec: brief/scheduler.md#разрешения-задания — a job run is not asked by a card: what it lacked is one list after the run
$loop->useAreas(new \OpenCCK\App\Job\JobAreas($conversations, $projectRepository));
$jobScheduler->useNeeds(static function (\OpenCCK\Domain\Job\Job $job) use ($approvals): void {
    $approvals->afterRun($job->status === \OpenCCK\Domain\Job\Job::DONE, 'job:' . $job->id, sprintf('#%d «%s»', $job->id, $job->title));
});
// spec: brief/scheduler.md#инициатива-агента — the agent's own initiative on the schedule of INITIATIVE_SCHEDULE
if (!(new \OpenCCK\App\Job\Initiative($jobRepository, $ownerZone))->sync($principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null), TelegramChannel::NAME, $ownerDirectory->chatId(), \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'INITIATIVE_SCHEDULE') ?? '')) {
    $logger->warning('INITIATIVE_SCHEDULE is not a schedule (daily:HH:MM, every:<minutes>, weekly:...)');
}

// spec: brief/memory.md#проекты-и-отношение-участника-к-проекту
$projectContext = new ProjectContext($projectRepository);
$projectContext->usePrefs($projectPrefs);
// spec: brief/scheduler.md#задания-проекта — a project shows its jobs: the agent changes the one that is there
$projectContext->useJobs(static fn(int $projectId): array => array_map(static fn(\OpenCCK\Domain\Job\Job $job): string => JobManageTool::line($job, $jobScheduler), $jobRepository->ofProjects([$projectId])));
$scheduler->useProjects($projectContext);
// what a run of a job sends "here" goes where its report goes
$recipients->useHome(static fn(\OpenCCK\Domain\Agent\ConversationMeta $meta): ?array => $meta->channel === JobScheduler::CHANNEL ? $jobScheduler->homeOf($meta->chatId) : null);
// spec: brief/self-improvement.md#самоулучшение — the agent proposes, the owner accepts; notes come into new prompts
$improvementRepository = new \OpenCCK\Infrastructure\Repository\PostgresImprovementRepository($db);
$evalScores = new \OpenCCK\Infrastructure\Repository\PostgresEvalScoreRepository($db);
$promptNotes = new \OpenCCK\App\Improve\PromptNotes($improvementRepository);
$scheduler->useNotes($promptNotes);
$traceReview = new \OpenCCK\App\Agent\Tool\Self\TraceReviewTool(new \OpenCCK\Infrastructure\Repository\PostgresTraceReviewRepository($db));
// spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — the review names the skills nobody opened for a month
$traceReview->useSkills($skillLibrary);
$tools->register($traceReview);
$tools->register(new ProjectTool($projectRepository, $conversations, $projectContext));
$groupRepository = new \OpenCCK\Infrastructure\Repository\PostgresGroupRepository($db);
$forumTopics = new \OpenCCK\App\Service\ForumTopics($channel, $groupRepository, $projectRepository, $logger);
// spec: brief/participants.md#доступ-к-проектам-и-передача — a project passes to another participant with their consent: the
//       taker is told in their private place, the folder of the project follows it into the taker's sandbox
$projectFolders = $sandbox === null ? null : new \OpenCCK\App\Service\ProjectFolders($sandbox, static function (int $id) use ($principals, $policies): ?\OpenCCK\Domain\Agent\SandboxLimits {
    $principal = $principals->find($id);
    return $principal === null ? null : $policies->forPrincipal($principal)->sandbox;
});
$projectTransfers = new \OpenCCK\App\Service\ProjectTransfers(
    $projectRepository,
    $principals,
    $projectFolders === null ? null : $projectFolders->move(...),
    static function (Member $to, string $text, array $buttons) use ($participantInbox): void {
        $participantInbox->tell($to, $text, $buttons);
    },
    $channel,
    $logger,
);
$projectLinks = new \OpenCCK\Infrastructure\Repository\PostgresProjectLinkRepository($db);
// spec: brief/network.md#проект-как-канал-для-агентов — an agent of the network enters a project, listens to it, leaves it
$projectChannels = new \OpenCCK\App\Peer\ProjectChannels($projectRepository, $projectLinks);
$projectEditTool = new ProjectEditTool($projectRepository, $conversations, $forumTopics, $principals, $projectTransfers);
$projectEditTool->useChannels($projectChannels);
$tools->register($projectEditTool);
// spec: brief/participants.md#права-проекта — a run in a project works with the project's rights, never above its owner's
$projectPolicyStore = new \OpenCCK\Infrastructure\Repository\PostgresProjectPolicyRepository($db);
$projectPolicies = new \OpenCCK\App\Service\ProjectPolicies(
    $projectPolicyStore,
    $projectRepository,
    $principals,
    $policies,
    static fn(\OpenCCK\Domain\Agent\ParticipantPolicy $policy): array => $tools->names($policy),
    $logger,
    $participantInbox,
);
$projectEditTool->useRights($projectPolicies);
$projectEditTool->usePrefs($projectPrefs, $jobScheduler->rescheduleProject(...));
// spec: brief/memory.md#сон-проекта — the owner of a project turns its sleep on and off
$projectEditTool->useSleep($projectSleep);
// spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия — the owner of a project sets the limits of its context
$projectEditTool->useLimits($contextLimitRepository);
$scheduler->useProjectPolicies($projectPolicies);
$jobScheduler->useProjectPolicies($projectPolicies);
$projectTransfers->usePolicies($projectPolicies);
$policies->onChange($projectPolicies->reconcileAll(...));
$roleCatalog->onChange($policies->changed(...));
// the owner's limits come from the environment: a change there reaches the projects at the start
$projectPolicies->reconcileAll();
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\ForumTopicTool($channel, $conversations));
$channelRepository = new \OpenCCK\Infrastructure\Repository\PostgresChannelRepository($db);
$channelRegistry = new \OpenCCK\App\Service\ChannelRegistry($channelRepository, $channel, $ownerDirectory, $logger);
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\ChannelPostTool($channel, $channelRepository));
$scheduler->useJobRuns($jobScheduler);
