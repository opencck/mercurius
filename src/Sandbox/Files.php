<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\NullCancellation;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

/**
 * File operations as the participant's user: the kernel enforces the home boundary (homes are 0700), so a symlink
 * to another home or a root-only file fails like it would for the participant. Only the size check runs as root.
 *
 * spec: brief/agent-loop.md#песочница — paths only inside the participant's home; size checked before writing
 */
final class Files implements FileStore {
    private const LIST_LIMIT = 500;
    // Below the read window (4 MB head + 4 MB tail), so a file read for replace always comes back whole.
    private const REPLACE_MAX = 4_000_000;
    private const TIMEOUT = 60;

    private ?CommandRunner $runner = null;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Users $users,
        private readonly Proc $proc = new Proc(windowBytes: 4_000_000),
    ) {}

    /** The runner and the file store need each other: the runner asks for usage, files run as the participant. */
    public function attach(CommandRunner $runner): void {
        $this->runner = $runner;
    }

    public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines): array {
        $file = $this->workspace->resolve($principalId, $path);
        $end = $startLine + $maxLines - 1;
        $result = $this->asUser($principalId, $limits, sprintf(
            'test -f %1$s || { echo "not a file" >&2; exit 2; }; wc -l < %1$s; sed -n %2$s %1$s',
            escapeshellarg($file),
            escapeshellarg($startLine . ',' . $end . 'p'),
        ));
        if ($result->exitCode !== 0) {
            throw new SandboxError(sprintf(t('sandbox.files.ne_udalos_prochitat'), $path, self::reason($result)), 404);
        }
        [$count, $text] = explode("\n", $result->stdout, 2) + [1 => ''];
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new SandboxError(sprintf(t('sandbox.files.dvoichnyy_fayl_ego_ne_pokazat'), $path), 409);
        }
        return ['text' => $text, 'totalLines' => (int) trim($count)];
    }

    /**
     * The whole file as bytes, read as the participant: the shell gives it in base64 so binary files come whole.
     *
     * spec: brief/agent-loop.md#песочница — a file the agent made goes to the chat
     */
    public function fetch(int $principalId, SandboxLimits $limits, string $path, int $maxBytes): string {
        $file = $this->workspace->resolve($principalId, $path);
        $result = $this->asUser($principalId, $limits, sprintf(
            'test -f %1$s || { echo "not a file" >&2; exit 2; }; s=$(stat -c %%s -- %1$s); [ "$s" -le %2$d ] || { echo "bigger than %2$d bytes" >&2; exit 3; }; base64 -w0 -- %1$s',
            escapeshellarg($file),
            $maxBytes,
        ));
        if ($result->exitCode !== 0) {
            throw new SandboxError(sprintf(t('sandbox.files.ne_udalos_prochitat'), $path, self::reason($result)), $result->exitCode === 3 ? 413 : 404);
        }
        $bytes = base64_decode(trim($result->stdout), true);
        if ($bytes === false) {
            throw new SandboxError(sprintf(t('sandbox.files.ne_udalos_prochitat'), $path, 'base64'), 500);
        }
        return $bytes;
    }

    public function write(int $principalId, SandboxLimits $limits, string $path, string $content): void {
        $file = $this->workspace->resolve($principalId, $path);
        $this->checkRoom($principalId, $limits, strlen($content));
        $result = $this->asUser($principalId, $limits, sprintf(
            'mkdir -p -- %s && cat > %s',
            escapeshellarg(dirname($file)),
            escapeshellarg($file),
        ), $content);
        if ($result->exitCode !== 0) {
            throw new SandboxError(sprintf(t('sandbox.files.ne_udalos_zapisat'), $path, self::reason($result)), 409);
        }
        if ($principalId === Workspace::OWNERS) {
            $this->users->handOver();
        }
    }

    public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new): void {
        if ($old === '') {
            throw new SandboxError(t('sandbox.files.fragment_dlya_zameny_pust'));
        }
        $file = $this->workspace->resolve($principalId, $path);
        // The output window keeps at most REPLACE_MAX bytes whole; a bigger file would come back cut and be saved so.
        $result = $this->asUser($principalId, $limits, sprintf(
            'test "$(stat -c %%s -- %1$s)" -le %2$d || { echo too-large >&2; exit 3; }; cat -- %1$s',
            escapeshellarg($file),
            self::REPLACE_MAX,
        ));
        if ($result->exitCode === 3 && str_contains($result->stderr, 'too-large')) {
            throw new SandboxError(sprintf(t('sandbox.files.fayl_bolshe_mb_prav_ego'), $path, intdiv(self::REPLACE_MAX, 1024 * 1024)), 413);
        }
        if ($result->exitCode !== 0) {
            throw new SandboxError(sprintf(t('sandbox.files.ne_udalos_prochitat'), $path, self::reason($result)), 404);
        }
        $count = substr_count($result->stdout, $old);
        if ($count !== 1) {
            throw new SandboxError(sprintf(t('sandbox.files.fragment_vstrechaetsya_v_raz_nuzhno'), $path, $count), 409);
        }
        $this->write($principalId, $limits, $path, str_replace($old, $new, $result->stdout));
    }

    public function list(int $principalId, SandboxLimits $limits, string $path, int $depth): array {
        $dir = $this->workspace->resolve($principalId, $path);
        $result = $this->asUser($principalId, $limits, sprintf(
            'find %s -mindepth 1 -maxdepth %d -printf %s | head -n %d',
            escapeshellarg($dir),
            max(1, min(3, $depth)),
            escapeshellarg('%y\t%s\t%P\n'),
            self::LIST_LIMIT,
        ));
        if ($result->exitCode !== 0) {
            throw new SandboxError(sprintf(t('sandbox.files.ne_udalos_prochitat_katalog'), $path, self::reason($result)), 404);
        }
        $entries = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            $parts = explode("\t", $line, 3);
            if (count($parts) === 3) {
                $entries[] = ['path' => $parts[2], 'type' => $parts[0] === 'd' ? 'dir' : ($parts[0] === 'l' ? 'link' : 'file'), 'size' => (int) $parts[1]];
            }
        }
        return $entries;
    }

    public function usage(int $principalId): int {
        $result = $this->proc->run(['du', '-sb', '--', $this->workspace->home($principalId)], '/', 60);
        return (int) strtok($result->stdout, "\t");
    }

    /** @throws SandboxError */
    private function checkRoom(int $principalId, SandboxLimits $limits, int $adding): void {
        $this->users->ensure($principalId);
        // the owners' area has no quota of its own: its files lie in the members' folders and in the root
        if ($principalId !== Workspace::OWNERS && $this->usage($principalId) + $adding > $limits->diskBytes) {
            throw new SandboxError(sprintf(
                t('sandbox.files.rabochiy_katalog_zapolnen_limit_mb'),
                intdiv($limits->diskBytes, 1024 * 1024),
            ), 413);
        }
    }

    private function asUser(int $principalId, SandboxLimits $limits, string $command, ?string $stdin = null): CommandResult {
        $this->users->ensure($principalId);
        if ($this->runner === null) {
            throw new SandboxError(t('sandbox.files.ispolnitel_ne_nastroen'), 500);
        }
        return $this->proc->run(
            $this->runner->argv($principalId, $limits, $this->workspace->home($principalId)),
            $this->workspace->home($principalId),
            self::TIMEOUT,
            CommandRunner::input($command, $stdin),
            new NullCancellation(),
        );
    }

    private static function reason(CommandResult $result): string {
        $text = trim($result->stderr);
        return $text === '' ? t('sandbox.files.kod') . $result->exitCode : mb_substr($text, 0, 300);
    }
}
