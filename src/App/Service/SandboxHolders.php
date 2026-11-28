<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Sandbox\SandboxError;
use OpenCCK\Sandbox\Workspace;

/**
 * Whose folder a project works in: the holder of the project, its own user in its folder in its owner's. A project
 * of the agent's owner lies in the folder of the owner's member.
 *
 * spec: brief/agent-loop.md#папка-контекста
 */
final class SandboxHolders {
    /** @param Closure(): ?int $agentOwner the member of the owner of the agent */
    public function __construct(private readonly Closure $agentOwner) {}

    /** null — no owner to keep the folder (no owner of the agent yet) */
    public function forProject(Project $project): ?int {
        $owner = $project->ownerId ?? ($this->agentOwner)();
        if ($owner === null) {
            return null;
        }
        try {
            return Workspace::projectHolder($project->id, $owner);
        } catch (SandboxError) {
            return null;
        }
    }
}
