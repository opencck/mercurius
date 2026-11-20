<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PolicyRepository;

/**
 * Policy of a participant: defaults of the role, then the participant's overrides, capped by the owner.
 *
 * spec: brief/agent-loop.md#права-участника
 */
final class PolicyService {
    public function __construct(
        private readonly PolicyRepository $repository,
        private readonly RunLimits $ownerLimits,
        private readonly ?SandboxLimits $ownerSandbox = null,
        /** @var (\Closure(Role): array<string, mixed>)|null the policy template of a role: the personal settings lie on top */
        private readonly ?\Closure $template = null,
    ) {}

    /** tools that are a power of a role, not only a tool */
    private const POWER_TOOLS = [
        'channel_post' => Power::Publish,
        'participants' => Power::Participants,
        'set_participant_policy' => Power::Participants,
    ];

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
        // spec: brief/participants.md#свои-роли-решение-владельца-2026-11-19 — without the power of tools a role talks, nothing more
        if (!$principal->role->may(Power::Tools)) {
            return $base->capTo($owner);
        }
        $policy = ParticipantPolicy::fromArray(array_replace($this->templateOf($principal->role), $this->repository->find($principal->id) ?? []), $base)->capTo($owner);
        // spec: brief/participants.md#свои-роли-решение-владельца-2026-11-19 — a tool of a power stays out of a role without it
        return $policy->without(array_keys(array_filter(self::POWER_TOOLS, static fn(Power $p): bool => !$principal->role->may($p))));
    }

    /** @return array<string, mixed> */
    private function templateOf(Role $role): array {
        return $this->template === null ? [] : ($this->template)($role);
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
        $policy = ParticipantPolicy::fromArray(array_replace($this->templateOf($target->role), $merged), $base)->capTo($this->owner());
        $this->repository->save($target->id, $merged);
        return $policy;
    }
}
