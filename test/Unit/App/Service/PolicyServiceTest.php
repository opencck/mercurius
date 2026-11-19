<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use DomainException;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryPolicyRepository;
use PHPUnit\Framework\TestCase;

final class PolicyServiceTest extends TestCase {
    private InMemoryPolicyRepository $repository;
    private PolicyService $service;

    protected function setUp(): void {
        $this->repository = new InMemoryPolicyRepository();
        $this->service = new PolicyService($this->repository, new RunLimits(30, 400_000, 900, 120));
    }

    /** @param positive-int $id */
    private function principal(Role $role, int $id = 2): Principal {
        return new Principal($id, PrincipalKind::Human, $role, null);
    }

    public function testOwnerHasAllToolsAndOwnerLimits(): void {
        $policy = $this->service->forPrincipal($this->principal(Role::owner(), 1));

        self::assertNull($policy->tools);
        self::assertSame(30, $policy->run->maxTurns);
        self::assertTrue($policy->mayRequestApproval);
    }

    public function testUserHasNoToolsByDefault(): void {
        $policy = $this->service->forPrincipal($this->principal(Role::user()));

        self::assertSame([], $policy->tools);
        self::assertFalse($policy->mayRequestApproval);
        self::assertFalse($policy->allowsTool('http_request'));
    }

    public function testUpdateIsStoredAndCapped(): void {
        $user = $this->principal(Role::user());
        $policy = $this->service->update($user, ['tools' => ['http_request'], 'max_turns' => 100]);

        self::assertSame(['http_request'], $policy->tools);
        self::assertSame(30, $policy->run->maxTurns);
        self::assertSame(['tools' => ['http_request'], 'max_turns' => 100], $this->repository->find(2));
        self::assertEquals($policy, $this->service->forPrincipal($user));
    }

    public function testUpdateMergesWithEarlierOverrides(): void {
        $user = $this->principal(Role::user());
        $this->service->update($user, ['tools' => ['http_request']]);
        $policy = $this->service->update($user, ['max_turns' => 5]);

        self::assertSame(['http_request'], $policy->tools);
        self::assertSame(5, $policy->run->maxTurns);
    }

    public function testOwnerPolicyCannotBeChanged(): void {
        $this->expectException(DomainException::class);
        $this->service->update($this->principal(Role::owner(), 1), ['max_turns' => 5]);
    }
}
