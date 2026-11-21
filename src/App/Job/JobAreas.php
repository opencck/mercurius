<?php

declare(strict_types=1);

namespace OpenCCK\App\Job;

use OpenCCK\App\Agent\RunAreas;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

/**
 * The areas of a run by its conversation: the job it is a run of, the project its chat works on.
 *
 * spec: brief/scheduler.md#разрешения-задания
 */
final class JobAreas implements RunAreas {
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly ?ProjectRepository $projects = null,
    ) {}

    public function of(Run $run): array {
        $meta = $this->conversations->meta($run->conversationId);
        if ($meta === null) {
            return [];
        }
        $areas = [];
        $job = self::job($meta->channel, $meta->chatId);
        if ($job !== null) {
            $areas[] = 'job:' . $job;
        }
        $project = $this->projects?->active($meta->channel, $meta->chatId, $meta->threadId);
        if ($project !== null) {
            $areas[] = 'project:' . $project->id;
        }
        return $areas;
    }

    public function unattended(Run $run): ?string {
        $meta = $this->conversations->meta($run->conversationId);
        $job = $meta === null ? null : self::job($meta->channel, $meta->chatId);
        return $job === null ? null : 'job:' . $job;
    }

    private static function job(string $channel, string $chat): ?int {
        return $channel === JobScheduler::CHANNEL && preg_match('~^job-(\d+)-\d+$~', $chat, $m) === 1 ? (int) $m[1] : null;
    }
}
