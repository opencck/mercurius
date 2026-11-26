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
 * another, when the project gets a new owner. Each side is worked by its own participant: one packs the folder and cuts
 * the archive into pieces, the pieces go over one by one, the other puts them together and unpacks; the source is
 * removed only after the copy is in place.
 *
 * spec: brief/participants.md#доступ-к-проектам-и-передача — the folder of a project follows the project
 */
final class ProjectFolders {
    /** a piece of the archive: the sandbox gives a file of up to 25 MB in one read */
    public const PIECE_BYTES = 16_000_000;
    /** an archive of more pieces than this (about 1 GB) is not moved: the handover is refused with the reason */
    public const MAX_PIECES = 64;

    /** @param Closure(int): ?SandboxLimits $limitsOf the sandbox limits of a participant; null — no sandbox for them */
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly Closure $limitsOf,
    ) {}

    /**
     * Deletes the folder of a deleted project from its owner's sandbox.
     *
     * spec: brief/participants.md#удаление-проекта
     *
     * @throws RuntimeException|\OpenCCK\App\Agent\Sandbox\SandboxFailure
     */
    public function remove(int $owner, int $project): void {
        $limits = ($this->limitsOf)($owner) ?? throw new RuntimeException('no sandbox for the owner of the project');
        // the path is made of the project's number alone
        $this->sandbox->exec($owner, $limits, 'project-remove-' . $project, sprintf("rm -rf 'projects/%d'", $project), '.', 120, new NullCancellation());
    }

    /** @throws RuntimeException|\OpenCCK\App\Agent\Sandbox\SandboxFailure the folder stays where it was */
    public function move(int $from, int $to, int $project): void {
        $source = ($this->limitsOf)($from);
        $target = ($this->limitsOf)($to);
        if ($source === null || $target === null) {
            throw new RuntimeException('no sandbox for one of the two participants');
        }
        // the paths are made of the project's number alone
        $dir = 'projects/' . $project;
        $archive = '.mercurius/handover-' . $project;
        $none = new NullCancellation();
        $packed = $this->sandbox->exec($from, $source, 'project-pack-' . $project, sprintf(
            'if [ -d %1$s ]; then mkdir -p .mercurius && rm -f %2$s.part-* && tar -czf %2$s.tgz %1$s && split -b %3$d -d -a 4 %2$s.tgz %2$s.part- && rm -f %2$s.tgz && echo "parts $(ls %2$s.part-* | wc -l)"; else echo none; fi',
            $dir,
            $archive,
            self::PIECE_BYTES,
        ), '.', 600, $none);
        if (trim($packed->stdout) === 'none') {
            return;
        }
        if (preg_match('/^parts\s+(\d+)$/', trim($packed->stdout), $m) !== 1 || (int) $m[1] < 1) {
            throw new RuntimeException('the folder could not be packed: ' . mb_substr(trim($packed->stderr), 0, 200));
        }
        $pieces = (int) $m[1];
        $clean = fn(): \OpenCCK\App\Agent\Sandbox\CommandOutcome => $this->sandbox->exec($from, $source, 'project-clean-' . $project, sprintf('rm -f %s.part-*', $archive), '.', 60, $none);
        if ($pieces > self::MAX_PIECES) {
            $clean();
            throw new RuntimeException(sprintf('the folder is too large to move: more than %d MB packed', intdiv(self::PIECE_BYTES * self::MAX_PIECES, 1_000_000)));
        }
        try {
            for ($i = 0; $i < $pieces; $i++) {
                $piece = sprintf('%s.part-%04d', $archive, $i);
                $this->sandbox->put($to, $target, $piece, $this->sandbox->fetch($from, $source, $piece, self::PIECE_BYTES + 1, $none), $none);
            }
            $unpacked = $this->sandbox->exec($to, $target, 'project-unpack-' . $project, sprintf('cat %1$s.part-* | tar -xzf - && echo unpacked; rm -f %1$s.part-*', $archive), '.', 600, $none);
            if (trim($unpacked->stdout) !== 'unpacked') {
                throw new RuntimeException('the folder was not unpacked: ' . mb_substr(trim($unpacked->stderr), 0, 200));
            }
        } catch (\Throwable $e) {
            $clean();
            throw $e;
        }
        $this->sandbox->exec($from, $source, 'project-drop-' . $project, sprintf('rm -rf %s %s.part-*', $dir, $archive), '.', 120, $none);
    }
}
