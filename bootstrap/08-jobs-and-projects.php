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
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Repository\PostgresJobRepository;
use Mercurius\Memory\Domain\ContentScreen;

/**
 * Made by the parts before this one:
 *
 * @var OpenCCK\App\Agent\Tool\Guard\AddressGuard $addressGuard
 * @var Mercurius\Messenger\RoutingChannel $channel
 * @var OpenCCK\Infrastructure\Repository\PostgresConversationRepository $conversations
 * @var Amp\Postgres\PostgresConnectionPool $db
 * @var OpenCCK\Infrastructure\Config\LlmSettings $llmSettings
 * @var Monolog\Logger $logger
 * @var OpenCCK\App\Agent\AgentLoop $loop
 * @var OpenCCK\App\Agent\Model\ModelRoles $modelRoles
 * @var OpenCCK\App\Service\OwnerDirectory $ownerDirectory
 * @var OpenCCK\App\Service\PolicyService $policies
 * @var OpenCCK\Infrastructure\Repository\PostgresPrincipalRepository $principals
 * @var OpenCCK\Infrastructure\Repository\PostgresProjectRepository $projectRepository
 * @var OpenCCK\Infrastructure\Repository\PostgresRunRepository $runs
 * @var OpenCCK\App\Agent\RunScheduler $scheduler
 * @var OpenCCK\App\Skill\SkillLibrary $skillLibrary
 * @var OpenCCK\Infrastructure\Config\TelegramSettings $telegram
 * @var OpenCCK\App\Agent\Tool\ToolRegistry $tools
 * @var OpenCCK\Infrastructure\Repository\PostgresTranscriptRepository $transcript
 */

// spec: brief/scheduler.md#как-сделано-задания-и-доставка-итогов — jobs by a schedule, run without the owner, reported by the scheduler
$ownerZone = new DateTimeZone(\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'OWNER_TIMEZONE') ?? '+03:00');
$quietRaw = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'QUIET_HOURS') ?? '23-8';
$quietHours = preg_match('~^(\d{1,2})-(\d{1,2})$~', $quietRaw, $q) === 1 ? [(int) $q[1] % 24, (int) $q[2] % 24] : null;
$jobRepository = new PostgresJobRepository($db);
$jobTokens = max(1000, (int) (\OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'JOB_TOKEN_BUDGET') ?? '100000'));
// spec: brief/scheduler.md#наблюдение-за-страницей — a watched address is read through the guard of internal addresses, as text
$jobWatchHttp = HttpClients::guarded($addressGuard);
$jobWatchText = new TextExtractor();
// spec: brief/scheduler.md#инициатива-агента — initiative messages: a daily cap of reports the owner did not ask for now
$proactivePerDay = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'INITIATIVE_PER_DAY');
$proactiveBudget = new \OpenCCK\App\Job\ProactiveBudget($proactivePerDay !== null && ctype_digit($proactivePerDay) ? (int) $proactivePerDay : 6, $ownerZone);
// spec: brief/participants.md#несколько-пользователей — a participant's own time zone and quiet hours for their jobs
$participantPrefs = new \OpenCCK\Infrastructure\Repository\PostgresParticipantPrefRepository($db);
$participantClock = new \OpenCCK\App\Job\ParticipantClock($participantPrefs, $ownerZone, $quietHours);
$tools->register(new \OpenCCK\App\Agent\Tool\Memory\MyPreferencesTool($participantPrefs, $participantClock));
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
$jobScheduler->useCore(static function (Principal $who, \OpenCCK\Domain\Job\Job $job) use ($scheduler, $principals): string {
    $private = $job->threadId === '' && $principals->externalId($who->id, $job->channel) === $job->chatId;
    return $private ? $scheduler->coreOf($who) : '';
});
$digestTime = \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'DIGEST_TIME');
$digestTime = $digestTime !== null && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $digestTime) === 1 ? $digestTime : null;
$tools->register(new JobCreateTool($jobRepository, $conversations, $jobScheduler, new ContentScreen(static fn(string $text): bool => SecretMasker::mask($text) !== $text), $runs));
$tools->register(new JobManageTool($jobRepository, $jobScheduler));
// spec: brief/scheduler.md#инициатива-агента — the agent's own initiative on the schedule of INITIATIVE_SCHEDULE
if (!(new \OpenCCK\App\Job\Initiative($jobRepository, $ownerZone))->sync($principals->ensureOwner(TelegramChannel::NAME, $telegram->ownerId, null), TelegramChannel::NAME, $ownerDirectory->chatId(), \OpenCCK\Infrastructure\Config\Env::raw($_ENV, 'INITIATIVE_SCHEDULE') ?? '')) {
    $logger->warning('INITIATIVE_SCHEDULE is not a schedule (daily:HH:MM, every:<minutes>, weekly:...)');
}

// spec: brief/memory.md#проекты-задачи-и-отношение-участника-к-проекту
$scheduler->useProjects(new ProjectContext($projectRepository));
// spec: brief/self-improvement.md#самоулучшение — the agent proposes, the owner accepts; notes come into new prompts
$improvementRepository = new \OpenCCK\Infrastructure\Repository\PostgresImprovementRepository($db);
$evalScores = new \OpenCCK\Infrastructure\Repository\PostgresEvalScoreRepository($db);
$promptNotes = new \OpenCCK\App\Improve\PromptNotes($improvementRepository);
$scheduler->useNotes($promptNotes);
$traceReview = new \OpenCCK\App\Agent\Tool\Self\TraceReviewTool(new \OpenCCK\Infrastructure\Repository\PostgresTraceReviewRepository($db));
// spec: brief/skills-mcp.md#учёт-использования-журнал-правок-и-обновление-скиллов — the review names the skills nobody opened for a month
$traceReview->useSkills($skillLibrary);
$tools->register($traceReview);
$tools->register(new ProjectTool($projectRepository, $conversations));
$groupRepository = new \OpenCCK\Infrastructure\Repository\PostgresGroupRepository($db);
$forumTopics = new \OpenCCK\App\Service\ForumTopics($channel, $groupRepository, $projectRepository, $logger);
$tools->register(new ProjectEditTool($projectRepository, $conversations, $ownerZone, $forumTopics));
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\ForumTopicTool($channel, $conversations));
$channelRepository = new \OpenCCK\Infrastructure\Repository\PostgresChannelRepository($db);
$channelRegistry = new \OpenCCK\App\Service\ChannelRegistry($channelRepository, $channel, $ownerDirectory, $logger);
$tools->register(new \OpenCCK\App\Agent\Tool\Messaging\ChannelPostTool($channel, $channelRepository));
$scheduler->useJobRuns($jobScheduler);
