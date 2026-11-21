<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\App\Job\JobScheduler;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ApprovalRepository;
use OpenCCK\Domain\Repository\JobRepository;
use OpenCCK\Domain\Repository\MailboxRepository;
use OpenCCK\Domain\Repository\PlaceStreamRepository;
use OpenCCK\Domain\Repository\PrincipalRepository;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * The owner's look at a participant in one message: their private place and projects — jobs, rights and limits,
 * grants and bans, secrets by name, mailboxes, streams — and how to change each from the owner's own chat.
 *
 * spec: brief/participants.md#обзор-владельца
 */
final class OwnerOverview {
    private const JOBS = 20;

    public function __construct(
        private readonly PrincipalRepository $principals,
        private readonly ProjectRepository $projects,
        private readonly JobRepository $jobs,
        private readonly PolicyService $policies,
        private readonly ApprovalRepository $approvals,
        private readonly JobScheduler $scheduler,
        private readonly ?Vault $vault = null,
        private readonly ?MailboxRepository $mailboxes = null,
        private readonly ?PlaceStreamRepository $streams = null,
        private readonly ?ProjectPolicies $rights = null,
    ) {}

    public function show(Principal $owner, string $argument): string {
        if (!$owner->isOwner()) {
            return t('overview.owner_only');
        }
        $argument = trim($argument);
        if ($argument === '') {
            return $this->everyone();
        }
        $who = $this->find($argument);
        return $who === null ? sprintf(t('overview.no_one'), $argument) : $this->participant($who);
    }

    private function everyone(): string {
        $lines = [t('overview.head_all')];
        foreach ($this->principals->all() as $p) {
            $projects = count(array_filter($this->projects->all(200, $p->isOwner() ? null : $p->id), static fn(Project $x): bool => $x->ownerId === ($p->isOwner() ? null : $p->id)));
            $lines[] = sprintf(t('overview.line_all'), $p->id, $p->displayName ?? '—', $p->role->value, $projects, count($this->jobs->list($p->id)), count($this->approvals->grants($p->id)));
        }
        return implode("\n", $lines) . "\n\n" . t('overview.hint_all');
    }

    private function participant(Principal $who): string {
        $policy = $this->policies->forPrincipal($who);
        $parts = [sprintf(t('overview.head'), $who->displayName ?? '—', $who->id, $who->role->value, $who->status->value)];
        $parts[] = sprintf(t('overview.rights'), $policy->tools === null ? t('overview.all_tools') : (string) count($policy->tools), $policy->run->maxTurns, $policy->run->tokenBudget, $policy->dailySpendUsd > 0 ? sprintf('$%.2f', $policy->dailySpendUsd) : '—');
        $jobs = array_slice($this->jobs->list($who->id), 0, self::JOBS);
        $parts[] = t('overview.jobs') . ($jobs === [] ? ' —' : "\n" . implode("\n", array_map(fn(Job $j): string => '  ' . \OpenCCK\App\Agent\Tool\Projects\JobManageTool::line($j, $this->scheduler), $jobs)));
        $own = array_values(array_filter($this->projects->all(200, $who->isOwner() ? null : $who->id), static fn(Project $p): bool => $p->ownerId === ($who->isOwner() ? null : $who->id)));
        foreach ($own as $project) {
            $projectJobs = $this->jobs->ofProjects([$project->id]);
            $secrets = array_map(static fn(array $s): string => $s['name'] . '→' . $s['site'], $this->vault?->projectNames($project->id) ?? []);
            $mail = array_map(static fn(\OpenCCK\Domain\Mail\Mailbox $m): string => $m->name, $this->mailboxes?->ofProject($project->id) ?? []);
            $parts[] = sprintf(t('overview.project'), $project->name, $project->id, $this->rights === null ? '—' : $this->rights->describe($project))
                . "\n  " . t('overview.jobs') . ($projectJobs === [] ? ' —' : "\n" . implode("\n", array_map(fn(Job $j): string => '    ' . \OpenCCK\App\Agent\Tool\Projects\JobManageTool::line($j, $this->scheduler), $projectJobs)))
                . "\n  " . sprintf(t('overview.project_rest'), $secrets === [] ? '—' : implode(', ', $secrets), $mail === [] ? '—' : implode(', ', $mail), count(array_filter($this->approvals->bans(), static fn(\OpenCCK\Domain\Agent\Ban $b): bool => $b->area === 'project:' . $project->id)));
        }
        $grants = $this->approvals->grants($who->id);
        $bans = array_filter($this->approvals->bans(), static fn(\OpenCCK\Domain\Agent\Ban $b): bool => $b->principalId === $who->id);
        $secrets = array_column($this->vault?->names($who) ?? [], 'name');
        $mail = array_map(static fn(\OpenCCK\Domain\Mail\Mailbox $m): string => $m->name, $this->mailboxes?->of($who->id) ?? []);
        $streams = array_map(static fn(\OpenCCK\Domain\Peer\PlaceStream $s): string => $s->title . ($s->subscribed ? ' ✓' : ''), $this->streams?->of($who->id) ?? []);
        $parts[] = sprintf(
            t('overview.rest'),
            count($grants),
            count($bans),
            $secrets === [] ? '—' : implode(', ', $secrets),
            $mail === [] ? '—' : implode(', ', $mail),
            $streams === [] ? '—' : implode(', ', $streams),
        );
        $parts[] = t('overview.hint');
        return implode("\n\n", $parts);
    }

    /** A participant by "#id", an id or a name. */
    private function find(string $named): ?Principal {
        $id = ltrim($named, '#');
        if (ctype_digit($id)) {
            return $this->principals->find((int) $id);
        }
        foreach ($this->principals->all() as $p) {
            if (mb_strtolower((string) $p->displayName) === mb_strtolower($named)) {
                return $p;
            }
        }
        return null;
    }
}
