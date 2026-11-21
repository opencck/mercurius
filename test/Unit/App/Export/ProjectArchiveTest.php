<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Export;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use InvalidArgumentException;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Export\ProjectArchive;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Job\Job;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryApprovalRepository;
use OpenCCK\Test\Support\InMemoryJobRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\InMemoryProjectLinkRepository;
use OpenCCK\Test\Support\InMemoryProjectPolicyRepository;
use OpenCCK\Test\Support\InMemoryProjectPrefRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;

/**
 * A project packed on one instance comes to another with what belongs to it and without the values of its secrets.
 *
 * spec: brief/participants.md#экспорт-и-импорт-проекта
 */
final class ProjectArchiveTest extends AsyncTestCase {
    /** @return array{ProjectArchive, InMemoryProjectRepository, InMemoryProjectPolicyRepository, InMemoryProjectPrefRepository, InMemoryApprovalRepository, InMemoryJobRepository, FakeSandbox, InMemoryPrincipalRepository, Vault, InMemoryProjectLinkRepository} */
    private function instance(string $folder): array {
        $projects = new InMemoryProjectRepository();
        $policies = new InMemoryProjectPolicyRepository();
        $prefs = new InMemoryProjectPrefRepository();
        $approvals = new InMemoryApprovalRepository();
        $jobs = new InMemoryJobRepository();
        $principals = new InMemoryPrincipalRepository();
        $principals->ensureOwner('telegram', '42', 'Owner');
        $sandbox = new FakeSandbox();
        $vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $links = new InMemoryProjectLinkRepository();
        $archive = new ProjectArchive($projects, $policies, $prefs, $approvals, $jobs, $principals, $folder, $sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), null, $vault, $links);
        return [$archive, $projects, $policies, $prefs, $approvals, $jobs, $sandbox, $principals, $vault, $links];
    }

    public function testAProjectMovesWithItsCardFolderJobsRightsBansAndClockButNoSecretValues(): void {
        $folder = sys_get_temp_dir() . '/mrcp-' . bin2hex(random_bytes(4));
        [$from, $projects, $policies, $prefs, $approvals, $jobs, $sandbox, $principals, $vault, $links] = $this->instance($folder);
        $project = $projects->create('Новости', 'дайджест');
        $projects->update($project->id, null, null, 'Карточка');
        $policies->save($project->id, ['tools' => ['web_read'], 'max_turns' => 10]);
        $prefs->save($project->id, 'Asia/Bangkok', '22-8');
        $approvals->addBan(null, 'project:' . $project->id, 'tool:ssh_run', 'команды', 1);
        $approvals->addGrant(1, 'area:project:' . $project->id);
        $approvals->addGrant(1, 'project:' . $project->id . '|http-any:hh.ru');
        $job = $jobs->create(1, 'telegram', '42', 'Утро', 'Собери новости', 'daily:10:00', null);
        $jobs->setProject($job->id, $project->id);
        $vault->putProject($project->id, 'hh-token', 'hh.ru', 'very-secret-value-123', $principals->owner() ?? self::fail('no owner'));
        $agent = $principals->createAgent('helper', Role::admin());
        $links->subscribe($project->id, $agent->id);
        $sandbox->outcome = new CommandOutcome(0, 'packed', '', false, false, 1);
        $sandbox->files[1]['.mercurius/project-export-' . $project->id . '.tgz'] = 'FOLDER-TGZ';

        $path = $from->export($projects->find($project->id) ?? $project, 'long-password-123', new NullCancellation());
        self::assertStringNotContainsString('very-secret-value-123', (string) file_get_contents($path), 'sealed, and no secret value inside anyway');

        [$to, $projectsB, $policiesB, $prefsB, $approvalsB, $jobsB, $sandboxB, $principalsB] = $this->instance($folder);
        $principalsB->createAgent('helper', Role::admin());
        $projectsB->create('Новости', 'занято');
        $sandboxB->outcome = new CommandOutcome(0, 'unpacked', '', false, false, 1);
        $owner = $principalsB->owner() ?? self::fail('no owner');
        try {
            $to->import($path, 'wrong-password-123', $owner, new NullCancellation());
            self::fail('a wrong password opens nothing');
        } catch (InvalidArgumentException) {
        }
        $answer = $to->import($path, 'long-password-123', $owner, new NullCancellation());

        $moved = $projectsB->byName('Новости (импорт)', null);
        self::assertNotNull($moved, 'a taken name gets a mark');
        self::assertSame(['дайджест', 'Карточка'], [$moved->goal, $moved->card]);
        self::assertSame(['tools' => ['web_read'], 'max_turns' => 10], $policiesB->find($moved->id));
        self::assertSame(['timezone' => 'Asia/Bangkok', 'quiet' => '22-8'], $prefsB->get($moved->id));
        self::assertSame(['tool:ssh_run'], array_map(static fn(\OpenCCK\Domain\Agent\Ban $b): string => $b->key, array_values(array_filter($approvalsB->bans(), static fn(\OpenCCK\Domain\Agent\Ban $b): bool => $b->area === 'project:' . $moved->id))));
        self::assertTrue($approvalsB->hasGrant($owner->id, 'area:project:' . $moved->id));
        self::assertTrue($approvalsB->hasGrant($owner->id, 'project:' . $moved->id . '|http-any:hh.ru'), 'the grants of the area come under the new number');
        $movedJobs = $jobsB->ofProjects([$moved->id]);
        self::assertCount(1, $movedJobs);
        self::assertSame(['Утро', 'daily:10:00', Job::ACTIVE, 'project import'], [$movedJobs[0]->title, $movedJobs[0]->schedule, $movedJobs[0]->status, $movedJobs[0]->origin]);
        self::assertNotNull($movedJobs[0]->nextRunAt);
        self::assertContains('put 1 .mercurius/project-import-' . $moved->id . '.tgz 10', $sandboxB->calls, 'the folder goes into the new owner\'s sandbox');
        self::assertStringContainsString('/secret Новости (импорт): hh-token <значение> hh.ru', $answer, 'the secrets to put are named, not their values');
        self::assertStringContainsString('helper', $answer, 'the agent of the same name is subscribed again');
        @unlink($path);
        @rmdir($folder);
    }
}
