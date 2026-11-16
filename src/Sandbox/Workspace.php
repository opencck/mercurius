<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

/**
 * Participants' homes in the sandbox: user p<id>, uid 20000 + id, home /workspace/p<id>.
 * resolve() is the first line of defence (lexical); the second is the kernel, because every operation runs as
 * the participant's user and homes are 0700.
 *
 * spec: BRIEF.md#песочница — a home and a Unix user per participant
 */
final class Workspace {
    public const UID_BASE = 20000;
    private const MAX_PATH = 4096;

    public function __construct(public readonly string $root = '/workspace') {}

    public function user(int $principalId): string {
        return 'p' . $principalId;
    }

    public function uid(int $principalId): int {
        return self::UID_BASE + $principalId;
    }

    public function home(int $principalId): string {
        return $this->root . '/' . $this->user($principalId);
    }

    /**
     * An absolute path inside the participant's home; relative paths start from the home.
     *
     * @throws SandboxError
     */
    public function resolve(int $principalId, string $path): string {
        if (str_contains($path, "\0") || strlen($path) > self::MAX_PATH) {
            throw new SandboxError('Недопустимый путь.');
        }
        $home = $this->home($principalId);
        $full = str_starts_with($path, '/') ? $path : $home . '/' . $path;

        $parts = [];
        foreach (explode('/', $full) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw $this->outside($path);
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $normalized = '/' . implode('/', $parts);
        if ($normalized !== $home && !str_starts_with($normalized, $home . '/')) {
            throw $this->outside($path);
        }
        return $normalized;
    }

    private function outside(string $path): SandboxError {
        return new SandboxError(sprintf('Путь %s — вне рабочего каталога участника.', mb_substr($path, 0, 200)), 403);
    }
}
