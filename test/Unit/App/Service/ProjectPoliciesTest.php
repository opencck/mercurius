<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use InvalidArgumentException;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\App\Service\ProjectPolicies;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Test\Support\InMemoryPolicyRepository;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use OpenCCK\Test\Support\InMemoryProjectPolicyRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The rights of a project: never above its owner's, changed only within them, narrowed when the owner loses a right.
 *
 * spec: brief/participants.md#права-проекта
 */
final class ProjectPoliciesTest extends TestCase {
    private const TOOLS = ['read_file', 'web_read', 'ssh_run', 'http_request'];

    private InMemoryProjectRepository $projects;
    private InMemoryMemberRepository $principals;
    private InMemoryProjectPolicyRepository $stored;
    private PolicyService $policies;
    private ProjectPolicies $rights;
    private Member $alice;
    private Member $bob;
    private Project $project;

    protected function setUp(): void {
        $this->projects = new InMemoryProjectRepository();
        $this->principals = new InMemoryMemberRepository();
        $this->principals->ensureOwner('telegram', '42', 'Owner');
        $this->policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 1_000_000, 900, 120));
        $this->alice = $this->participant('51', 'Алиса');
        $this->bob = $this->participant('52', 'Боб');
        $this->policies->update($this->alice, ['tools' => ['read_file', 'web_read', 'ssh_run'], 'token_budget' => 200_000]);
        $this->policies->update($this->bob, ['tools' => ['read_file']]);
        $this->stored = new InMemoryProjectPolicyRepository();
        $this->rights = new ProjectPolicies(
            $this->stored,
            $this->projects,
            $this->principals,
            $this->policies,
            static fn(ParticipantPolicy $p): array => array_values(array_filter(self::TOOLS, $p->allowsTool(...))),
            new NullLogger(),
        );
        $this->project = $this->projects->create('Новости', '', $this->alice->id);
    }

    private static function memory(ParticipantPolicy $policy): ?int {
        $sandbox = $policy->sandbox;
        return $sandbox === null ? null : $sandbox->memoryBytes;
    }

    private function participant(string $id, string $name): Member {
        $visitor = $this->principals->createVisitor('telegram', $id, $name);
        return $this->principals->decideVisitor($visitor->id, Role::user(), MemberStatus::Active) ?? throw new \RuntimeException('no participant');
    }

    public function testANewProjectHasTheRightsOfItsOwner(): void {
        $policy = $this->rights->of($this->project);

        self::assertSame(['read_file', 'web_read', 'ssh_run'], $policy->tools);
        self::assertSame(200_000, $policy->run->tokenBudget);
    }

    public function testAProjectDoesNotGetAToolItsOwnerHasNot(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('http_request');

        $this->rights->grant($this->project, 'http_request');
    }

    public function testTheOwnerNarrowsTheProjectAndALimitAboveTheirOwnIsRefused(): void {
        $this->rights->revoke($this->project, 'ssh_run');
        $this->rights->limit($this->project, 'token_budget', 50_000);

        $policy = $this->rights->of($this->project);
        self::assertSame(['read_file', 'web_read'], $policy->tools);
        self::assertSame(50_000, $policy->run->tokenBudget);
        try {
            $this->rights->limit($this->project, 'token_budget', 300_000);
            self::fail('a limit above the owner\'s is refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('200000', $e->getMessage());
        }
        $this->rights->grant($this->project, 'ssh_run');
        self::assertContains('ssh_run', $this->rights->of($this->project)->tools ?? []);
    }

    public function testAnOwnerWithoutALimitOfAddressSpaceChangesTheProjectAndSetsOne(): void {
        // the sandbox of the agent's owner has memory_bytes 0 — no limit of the address space; the first change of the
        // project's rights starts from them and must not fail on that 0
        $policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 1_000_000, 900, 120), new \OpenCCK\Domain\Agent\SandboxLimits(1_000_000_000, 64, 600, 0, 4));
        $rights = new ProjectPolicies(
            new InMemoryProjectPolicyRepository(),
            $this->projects,
            $this->principals,
            $policies,
            static fn(ParticipantPolicy $p): array => self::TOOLS,
            new NullLogger(),
        );
        $project = $this->projects->create('Сайт', '', null);

        $rights->revoke($project, 'http_request');
        self::assertNotContains('http_request', $rights->of($project)->tools ?? []);
        self::assertSame(0, self::memory($rights->of($project)), 'still no limit');
        $rights->grant($project, 'http_request');
        self::assertContains('http_request', $rights->of($project)->tools ?? []);

        $rights->limit($project, 'sandbox.memory_bytes', 2_000_000_000);
        self::assertSame(2_000_000_000, self::memory($rights->of($project)), 'an owner without a limit may give the project one');
    }

    public function testWhenTheOwnerLosesAToolTheProjectLosesItAndTheLimitGoesDown(): void {
        $this->rights->limit($this->project, 'token_budget', 150_000);
        $this->policies->update($this->alice, ['tools' => ['read_file'], 'token_budget' => 100_000]);

        self::assertSame(['read_file'], $this->rights->of($this->project)->tools, 'capped at once, before any reconcile');
        $changes = $this->rights->reconcile($this->project);

        self::assertCount(2, $changes);
        self::assertStringContainsString('web_read, ssh_run', $changes[0]);
        self::assertStringContainsString('150000 → 100000', $changes[1]);
        self::assertSame(['read_file'], $this->stored->find($this->project->id)['tools'] ?? null, 'the stored rights are narrowed too');
        self::assertSame([], $this->rights->reconcile($this->project), 'nothing more to narrow');
        $this->policies->update($this->alice, ['tools' => ['read_file', 'web_read', 'ssh_run']]);
        self::assertSame(['read_file'], $this->rights->of($this->project)->tools, 'a right the owner gets back does not come back to the project by itself');
    }

    public function testAMemberWorksWithTheRightsOfTheProjectAndAReaderOnlyReads(): void {
        $this->projects->share($this->project->id, $this->bob->id, 'write', $this->alice->id);
        $carol = $this->participant('53', 'Кэрол');
        $this->projects->share($this->project->id, $carol->id, 'read', $this->alice->id);
        $stranger = $this->participant('54', 'Дэйв');

        $bob = $this->rights->forRun($this->project, $this->bob);
        $reader = $this->rights->forRun($this->project, $carol);

        self::assertNotNull($bob);
        self::assertSame(['read_file', 'web_read', 'ssh_run'], $bob->tools, 'more than Bob has of his own');
        self::assertFalse($bob->readOnly);
        self::assertNotNull($reader);
        self::assertTrue($reader->readOnly);
        self::assertNull($this->rights->forRun($this->project, $stranger), 'no access — the usual rights');
    }

    public function testAProjectPassedOnIsNarrowedToItsNewOwner(): void {
        $this->rights->revoke($this->project, 'ssh_run');
        $this->projects->setOwner($this->project->id, $this->bob->id);
        $moved = $this->projects->find($this->project->id);
        self::assertNotNull($moved);

        $changes = $this->rights->reconcile($moved);

        self::assertSame(['read_file'], $this->rights->of($moved)->tools);
        self::assertStringContainsString('web_read', implode(' ', $changes));
    }
}
