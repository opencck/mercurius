<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\NullCancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\SandboxLimits;
use RuntimeException;

/**
 * Moves the folder of a project (`projects/<n>`: its skills and files) from the sandbox of one participant to that of
 * another, when the project gets a new owner. Each side is worked by its own participant: one packs, the other
 * unpacks; the source is removed only after the copy is in place.
 *
 * spec: brief/participants.md#доступ-к-проектам-и-передача — the folder of a project follows the project
 */
final class ProjectFolders {
    /** an archive of a project larger than this is not moved: the handover is refused with the reason */
    public const MAX_BYTES = 256 * 1024 * 1024;

    /** @param Closure(int): ?SandboxLimits $limitsOf the sandbox limits of a participant; null — no sandbox for them */
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly Closure $limitsOf,
    ) {}

    /** @throws RuntimeException|\OpenCCK\App\Agent\Sandbox\SandboxFailure the folder stays where it was */
    public function move(int $from, int $to, int $project): void {
        $source = ($this->limitsOf)($from);
        $target = ($this->limitsOf)($to);
        if ($source === null || $target === null) {
            throw new RuntimeException('no sandbox for one of the two participants');
        }
        // the paths are made of the project's number alone
        $dir = 'projects/' . $project;
        $archive = '.mercurius/handover-' . $project . '.tgz';
        $none = new NullCancellation();
        $packed = $this->sandbox->exec($from, $source, 'project-pack-' . $project, sprintf('if [ -d %1$s ]; then mkdir -p .mercurius && tar -czf %2$s %1$s && echo packed; else echo none; fi', $dir, $archive), '.', 300, $none);
        if (trim($packed->stdout) === 'none') {
            return;
        }
        if (trim($packed->stdout) !== 'packed') {
            throw new RuntimeException('the folder could not be packed: ' . mb_substr(trim($packed->stderr), 0, 200));
        }
        try {
            $this->sandbox->put($to, $target, $archive, $this->sandbox->fetch($from, $source, $archive, self::MAX_BYTES, $none), $none);
            $unpacked = $this->sandbox->exec($to, $target, 'project-unpack-' . $project, sprintf('tar -xzf %1$s && echo unpacked; rm -f %1$s', $archive), '.', 300, $none);
            if (trim($unpacked->stdout) !== 'unpacked') {
                throw new RuntimeException('the folder could not be unpacked: ' . mb_substr(trim($unpacked->stderr), 0, 200));
            }
        } catch (\Throwable $e) {
            $this->sandbox->exec($from, $source, 'project-pack-' . $project, 'rm -f ' . $archive, '.', 60, $none);
            throw $e;
        }
        $this->sandbox->exec($from, $source, 'project-drop-' . $project, sprintf('rm -rf %s %s', $dir, $archive), '.', 120, $none);
    }
}
