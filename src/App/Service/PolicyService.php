<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PolicyRepository;

/**
 * Policy of a participant: defaults of the role, then the participant's overrides, capped by the owner.
 *
 * spec: BRIEF.md#права-участника
 */
final class PolicyService {
    public function __construct(
        private readonly PolicyRepository $repository,
        private readonly RunLimits $ownerLimits,
        private readonly ?SandboxLimits $ownerSandbox = null,
    ) {}

    public function owner(): ParticipantPolicy {
        return new ParticipantPolicy(null, $this->ownerLimits, $this->ownerSandbox, true);
    }

    /** What a visitor — or anyone the agent does not know — may do: talk, nothing more. */
    public function visitor(): ParticipantPolicy {
        return (new ParticipantPolicy([], $this->ownerLimits, $this->ownerSandbox, false))->capTo($this->owner());
    }

    public function forPrincipal(Principal $principal): ParticipantPolicy {
        $owner = $this->owner();
        if ($principal->isOwner()) {
            return $owner;
        }
        $base = new ParticipantPolicy([], $this->ownerLimits, $this->ownerSandbox, false);
        if ($principal->role === Role::Visitor) {
            // spec: BRIEF.md#участники-люди-и-агенты — a visitor talks, nothing more; for tools the owner makes them a user
            return $base->capTo($owner);
        }
        return ParticipantPolicy::fromArray($this->repository->find($principal->id) ?? [], $base)->capTo($owner);
    }

    /**
     * Merges new overrides into the stored ones. The owner's policy comes from the environment and is not changed here.
     *
     * @param array<string, mixed> $overrides
     * @throws DomainException when the target is the owner
     * @throws \InvalidArgumentException on bad overrides
     */
    public function update(Principal $target, array $overrides): ParticipantPolicy {
        if ($target->isOwner()) {
            throw new DomainException('The owner policy is set in the environment and cannot be changed');
        }
        $merged = array_replace($this->repository->find($target->id) ?? [], $overrides);
        $base = new ParticipantPolicy([], $this->ownerLimits, $this->ownerSandbox, false);
        $policy = ParticipantPolicy::fromArray($merged, $base)->capTo($this->owner());
        $this->repository->save($target->id, $merged);
        return $policy;
    }
}
