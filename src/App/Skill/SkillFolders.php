<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use Amp\NullCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\Domain\Agent\SandboxLimits;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Moves the skills of the old layout into the new one at start: `skills/` of a participant to `.mercurius/skills/`,
 * `projects/<n>/skills/` to `projects/<n>/.mercurius/skills/`. The old folder is kept in `.mercurius/backup/`; where the
 * new place already exists nothing is touched, so a second start does nothing.
 *
 * spec: brief/skills-mcp.md#скиллы-и-mcp — the layout of skills, existing folders moved automatically (owner's decision В14)
 */
final class SkillFolders {
    /**
     * @param Closure(int): ?SandboxLimits $limitsOf the sandbox limits of a participant; null — no sandbox
     * @param (Closure(): string)|null $day the date in the name of the backup
     */
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly Closure $limitsOf,
        private ?Closure $day = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->day ??= static fn(): string => date('Y-m-d');
    }

    /**
     * A shell command that moves $old to $new once, keeping $old in .mercurius/backup/$backup; prints "moved" when it did.
     * The paths are fixed by the code (digits and fixed names), not taken from anyone.
     */
    public static function moveCommand(string $old, string $new, string $backup): string {
        $q = static fn(string $s): string => "'" . str_replace("'", "'\\''", $s) . "'";
        return sprintf(
            'if [ -d %1$s ] && [ ! -L %1$s ] && [ ! -e %2$s ]; then mkdir -p "$(dirname %2$s)" .mercurius/backup && cp -a %1$s %2$s && mv %1$s %3$s && echo moved; fi',
            $q($old),
            $q($new),
            $q('.mercurius/backup/' . $backup),
        );
    }

    /**
     * @param list<int> $people participants who may have skills
     * @param list<array{int, int}> $projects project and the participant whose sandbox holds it
     * @return int folders moved
     */
    public function migrate(array $people, array $projects): int {
        $day = ($this->day ?? static fn(): string => date('Y-m-d'))();
        $moved = 0;
        foreach ($people as $id) {
            $moved += $this->move($id, SkillLibrary::LEGACY_MINE, SkillLibrary::MINE, 'skills-' . $day);
        }
        foreach ($projects as [$project, $owner]) {
            $moved += $this->move($owner, 'projects/' . $project . '/skills', 'projects/' . $project . '/' . SkillLibrary::MINE, 'projects-' . $project . '-skills-' . $day);
        }
        return $moved;
    }

    private function move(int $owner, string $old, string $new, string $backup): int {
        $limits = ($this->limitsOf)($owner);
        if ($limits === null) {
            return 0;
        }
        try {
            $outcome = $this->sandbox->exec($owner, $limits, 'skills-move-' . $owner, self::moveCommand($old, $new, $backup), '.', 60, new NullCancellation());
        } catch (SandboxFailure $e) {
            $this->logger->warning('Skills were not moved', ['principal' => $owner, 'from' => $old, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return 0;
        }
        if (trim($outcome->stdout) === 'moved') {
            $this->logger->notice('Skills moved to the new layout', ['principal' => $owner, 'from' => $old, 'to' => $new]);
            return 1;
        }
        return 0;
    }
}
