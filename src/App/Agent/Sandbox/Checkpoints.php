<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Sandbox;

use Amp\Cancellation;
use OpenCCK\Domain\Agent\SandboxLimits;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Snapshots of a participant's working folder: a shadow git store in `.checkpoints/` of the folder (its own git dir,
 * nothing leaks into the participant's repositories), taken as that participant before the first change of a run;
 * listed, compared and restored by the checkpoint tool. Incoming files, dependencies and caches are left out.
 *
 * spec: BRIEF.md#планировщик-и-проекты — snapshots of the working folder before changes, with a rollback
 * ref: references/hermes-agent/tools/checkpoint_manager.py@e96221fa:L1-L48 — a shadow git store with GIT_DIR and
 *      GIT_WORK_TREE, a snapshot once per turn before file-mutating operations, rollback; here one store per participant
 *      inside its own folder (the sandbox keeps folders apart), without the size cap and pruning of hermes
 * ref: references/hermes-agent/tools/checkpoint_manager.py@e96221fa:L100-L170 (DEFAULT_EXCLUDES) — what is left out
 */
final class Checkpoints {
    public const DIR = '.checkpoints';
    private const EXCLUDES = [
        '.checkpoints/', 'inbox/', '.media/', 'node_modules/', 'vendor/', 'dist/', 'build/', 'target/', '__pycache__/',
        '*.pyc', '.cache/', '.venv/', 'venv/', '.git/', '*.iso', '*.mp4', '*.mov', '*.zip', '*.tar', '*.gz',
    ];
    private const SECONDS = 60;
    /** runs remembered as snapshotted, the oldest forgotten first */
    private const REMEMBER = 500;

    /** @var array<int, true> */
    private array $taken = [];

    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** A snapshot before the first change of the run; later calls of the same run do nothing. Never throws. */
    public function ensure(int $principalId, SandboxLimits $limits, int $runId, Cancellation $cancellation): void {
        if (isset($this->taken[$runId])) {
            return;
        }
        $this->taken[$runId] = true;
        if (count($this->taken) > self::REMEMBER) {
            unset($this->taken[array_key_first($this->taken)]);
        }
        try {
            $this->take($principalId, $limits, 'before run ' . $runId, $cancellation);
        } catch (Throwable $e) {
            $this->logger->notice('Checkpoint was not taken', ['run' => $runId, 'error' => $e::class]);
        }
    }

    public function take(int $principalId, SandboxLimits $limits, string $reason, Cancellation $cancellation): CommandOutcome {
        $message = self::quote(preg_replace('~[^\w .:-]~u', '', $reason) ?? 'snapshot');
        return $this->git($principalId, $limits, 'add -A && ' . self::GIT . ' -c user.name=mercurius -c user.email=checkpoint@mercurius commit -q -m ' . $message . ' || true', $cancellation);
    }

    public function list(int $principalId, SandboxLimits $limits, Cancellation $cancellation): CommandOutcome {
        return $this->git($principalId, $limits, "log -n 20 --format='%h %ci %s' 2>/dev/null || echo none", $cancellation, false);
    }

    public function diff(int $principalId, SandboxLimits $limits, string $id, Cancellation $cancellation): CommandOutcome {
        return $this->git($principalId, $limits, 'diff --stat ' . self::quote($id) . ' 2>&1 | tail -50', $cancellation, false);
    }

    /** Takes what is there now first, so the restore itself can be undone; then the files of the snapshot. */
    public function restore(int $principalId, SandboxLimits $limits, string $id, ?string $path, Cancellation $cancellation): CommandOutcome {
        $this->take($principalId, $limits, 'before restore of ' . $id, $cancellation);
        return $this->git($principalId, $limits, 'checkout ' . self::quote($id) . ' -- ' . self::quote($path ?? '.') . ' 2>&1', $cancellation, false);
    }

    /** A hash of a snapshot: hex, 4 to 40 characters, nothing an option could hide in. */
    public static function validId(string $id): bool {
        return preg_match('~^[0-9a-f]{4,40}$~', $id) === 1;
    }

    /** A path inside the folder: relative, without `..` and without the store itself. */
    public static function validPath(string $path): bool {
        $path = str_replace('\\', '/', trim($path));
        return $path !== '' && !str_starts_with($path, '/') && !str_starts_with($path, '-') && !in_array('..', explode('/', $path), true)
            && !str_starts_with($path, self::DIR);
    }

    private const GIT = 'git --git-dir=' . self::DIR . ' --work-tree=.';

    private function git(int $principalId, SandboxLimits $limits, string $command, Cancellation $cancellation, bool $init = true): CommandOutcome {
        $setup = '';
        if ($init) {
            $exclude = implode("\\n", self::EXCLUDES);
            $setup = '[ -d ' . self::DIR . ' ] || { git init -q --bare ' . self::DIR . ' && git --git-dir=' . self::DIR . ' config core.bare false'
                . " && printf '" . $exclude . "\\n' > " . self::DIR . '/info/exclude; } && ';
        }
        return $this->sandbox->exec($principalId, $limits, 'checkpoint-' . bin2hex(random_bytes(4)), $setup . self::GIT . ' ' . $command, '.', self::SECONDS, $cancellation);
    }

    /** POSIX single quotes for the sandbox shell, whatever runs this. */
    private static function quote(string $value): string {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
