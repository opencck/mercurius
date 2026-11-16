<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\NullCancellation;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * File operations as the participant's user: the kernel enforces the home boundary (homes are 0700), so a symlink
 * to another home or a root-only file fails like it would for the participant. Only the size check runs as root.
 *
 * spec: BRIEF.md#песочница — paths only inside the participant's home; size checked before writing
 */
final class Files implements FileStore {
    private const LIST_LIMIT = 500;
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
            throw new SandboxError(sprintf('Не удалось прочитать %s: %s', $path, self::reason($result)), 404);
        }
        [$count, $text] = explode("\n", $result->stdout, 2) + [1 => ''];
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new SandboxError(sprintf('%s — двоичный файл, его не показать текстом.', $path), 409);
        }
        return ['text' => $text, 'totalLines' => (int) trim($count)];
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
            throw new SandboxError(sprintf('Не удалось записать %s: %s', $path, self::reason($result)), 409);
        }
    }

    public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new): void {
        if ($old === '') {
            throw new SandboxError('Фрагмент для замены пуст.');
        }
        $file = $this->workspace->resolve($principalId, $path);
        $result = $this->asUser($principalId, $limits, 'cat -- ' . escapeshellarg($file));
        if ($result->exitCode !== 0) {
            throw new SandboxError(sprintf('Не удалось прочитать %s: %s', $path, self::reason($result)), 404);
        }
        $count = substr_count($result->stdout, $old);
        if ($count !== 1) {
            throw new SandboxError(sprintf('Фрагмент встречается в %s %d раз, нужно ровно один.', $path, $count), 409);
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
            throw new SandboxError(sprintf('Не удалось прочитать каталог %s: %s', $path, self::reason($result)), 404);
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
        if ($this->usage($principalId) + $adding > $limits->diskBytes) {
            throw new SandboxError(sprintf(
                'Рабочий каталог заполнен: лимит %d МБ. Удали лишнее и повтори.',
                intdiv($limits->diskBytes, 1024 * 1024),
            ), 413);
        }
    }

    private function asUser(int $principalId, SandboxLimits $limits, string $command, ?string $stdin = null): CommandResult {
        $this->users->ensure($principalId);
        if ($this->runner === null) {
            throw new SandboxError('Исполнитель не настроен.', 500);
        }
        return $this->proc->run(
            $this->runner->argv($principalId, $limits, $command),
            $this->workspace->home($principalId),
            self::TIMEOUT,
            $stdin,
            new NullCancellation(),
        );
    }

    private static function reason(CommandResult $result): string {
        $text = trim($result->stderr);
        return $text === '' ? 'код ' . $result->exitCode : mb_substr($text, 0, 300);
    }
}
