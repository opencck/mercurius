<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\Cancellation;
use Amp\TimeoutCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\ProjectRepository;

/**
 * The MEMORY.md of a context, at the root of its folder: a private chat — the participant's own folder; a chat, topic
 * or task of the network of agents bound to a project — the project's folder (`projects/<n>/` in the sandbox of its
 * owner); a group, channel or topic without a project — the place's folder (`places/<key>/` in the sandbox of the
 * agent's owner). No file is the same as an empty one. A private chat bound to a project has both: the person's own
 * file and the project's.
 *
 * spec: brief/memory.md#файл-памяти-места
 */
final class ContextMemory {
    public const FILE = 'MEMORY.md';
    public const MAX_BYTES = 400_000;

    /**
     * @param Closure(int): ?SandboxLimits $limitsOf the sandbox limits of a participant; null — no sandbox
     * @param Closure(): ?int $ownerId the agent's owner: the holder of places and of projects without one
     */
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly Closure $limitsOf,
        private readonly ProjectRepository $projects,
        private readonly Closure $ownerId,
    ) {}

    /** The person's own file: in their sandbox folder, when they have one. */
    public function personal(Member $principal): ?MemoryPlace {
        return ($this->limitsOf)($principal->id) === null ? null : new MemoryPlace($principal->id, self::FILE, MemoryPlace::PERSON);
    }

    /**
     * The file of the place a conversation is in: the project's when the chat (or topic, or task) is bound to one the
     * person has access to; else, outside a private chat, the place's own. A private chat without a project has none.
     */
    public function shared(Member $principal, string $channel, string $chatId, string $threadId, bool $private): ?MemoryPlace {
        $project = $principal->role->may(Permission::Projects) ? $this->projects->active($channel, $chatId, $threadId) : null;
        if ($project !== null) {
            if ($project->levelOf($principal, $this->projects->memberLevel($project->id, $principal->id)) === null) {
                return null;
            }
            $holder = $project->ownerId ?? ($this->ownerId)();
            return $holder === null ? null : new MemoryPlace($holder, 'projects/' . $project->id . '/' . self::FILE, MemoryPlace::PROJECT, $project->id);
        }
        if ($private) {
            return null;
        }
        $holder = ($this->ownerId)();
        return $holder === null ? null : new MemoryPlace($holder, 'places/' . self::key($channel, $chatId, $threadId) . '/' . self::FILE, MemoryPlace::PLACE);
    }

    /** The folder of a place: its channel, chat and topic, in letters a path may hold. */
    public static function key(string $channel, string $chatId, string $threadId): string {
        $key = $channel . '_' . $chatId . ($threadId === '' ? '' : '_' . $threadId);
        return (string) preg_replace('~[^A-Za-z0-9_-]~', '_', $key);
    }

    /** The text of the file; '' when there is none or the sandbox does not answer. */
    public function read(MemoryPlace $place, ?Cancellation $cancellation = null): string {
        $limits = ($this->limitsOf)($place->holder);
        if ($limits === null) {
            return '';
        }
        try {
            return $this->sandbox->fetch($place->holder, $limits, $place->path, self::MAX_BYTES, $cancellation ?? new TimeoutCancellation(5));
        } catch (SandboxFailure) {
            return '';
        }
    }

    /** @throws SandboxFailure */
    public function write(MemoryPlace $place, string $text, Cancellation $cancellation): void {
        $this->sandbox->write($place->holder, $this->limits($place), $place->path, $text, $cancellation);
    }

    /** @throws SandboxFailure */
    public function replace(MemoryPlace $place, string $old, string $new, Cancellation $cancellation): void {
        $this->sandbox->replace($place->holder, $this->limits($place), $place->path, $old, $new, $cancellation);
    }

    /** @throws SandboxFailure */
    private function limits(MemoryPlace $place): SandboxLimits {
        $limits = ($this->limitsOf)($place->holder);
        if ($limits === null) {
            throw new SandboxFailure('no sandbox for the holder of the file');
        }
        return $limits;
    }
}
