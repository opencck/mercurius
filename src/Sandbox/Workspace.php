<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use function Mercurius\I18n\t;

/**
 * Participants' homes in the sandbox: user p<id>, uid 20000 + id, home /workspace/p<id>. The owners' area is the
 * holder 0: user owners, uid 19999, home the root itself, where every member's folder lies.
 * resolve() is the first line of defence (lexical); the second is the kernel, because every operation runs as
 * the participant's user and homes are 0700.
 *
 * spec: brief/agent-loop.md#песочница — a home and a Unix user per participant
 * spec: brief/agent-loop.md#раскладка-каталогов — the owners' area is the root
 */
final class Workspace {
    public const UID_BASE = 20000;
    /** the holder of the owners' area */
    public const OWNERS = 0;
    public const OWNERS_UID = 19999;
    private const MAX_PATH = 4096;

    public function __construct(public readonly string $root = '/workspace') {}

    /** the uid of a project: 3000000 + its number, apart from the members' */
    public const PROJECT_UID_BASE = 3_000_000;
    /** a member's number fits below it in the holder of a project */
    private const OWNER_SPAN = 2_097_152;

    /**
     * The holder of a project: its own user, its home its folder in its owner's. Negative, so it is never a member's;
     * the owner is in it, so the folder is found without a lookup and a transfer gives a new holder.
     *
     * spec: brief/agent-loop.md#папка-контекста
     */
    public static function projectHolder(int $projectId, int $ownerId): int {
        if ($projectId < 1 || $ownerId < 1 || $ownerId >= self::OWNER_SPAN || $projectId > intdiv(PHP_INT_MAX, self::OWNER_SPAN) - 1) {
            throw new SandboxError(t('sandbox.workspace.nedopustimyy_put'));
        }
        return -($projectId * self::OWNER_SPAN + $ownerId);
    }

    public static function isProject(int $holder): bool {
        return $holder < 0;
    }

    public static function projectOf(int $holder): int {
        return intdiv(-$holder, self::OWNER_SPAN);
    }

    public static function ownerOf(int $holder): int {
        return -$holder % self::OWNER_SPAN;
    }

    public function user(int $principalId): string {
        if (self::isProject($principalId)) {
            return 'j' . self::projectOf($principalId);
        }
        return $principalId === self::OWNERS ? 'owners' : 'p' . $principalId;
    }

    public function uid(int $principalId): int {
        if (self::isProject($principalId)) {
            return self::PROJECT_UID_BASE + self::projectOf($principalId);
        }
        return $principalId === self::OWNERS ? self::OWNERS_UID : self::UID_BASE + $principalId;
    }

    public function home(int $principalId): string {
        if (self::isProject($principalId)) {
            return $this->home(self::ownerOf($principalId)) . '/projects/' . self::projectOf($principalId);
        }
        return $principalId === self::OWNERS ? $this->root : $this->root . '/' . $this->user($principalId);
    }

    /**
     * An absolute path inside the participant's home; relative paths start from the home.
     *
     * @throws SandboxError
     */
    public function resolve(int $principalId, string $path): string {
        if (str_contains($path, "\0") || strlen($path) > self::MAX_PATH) {
            throw new SandboxError(t('sandbox.workspace.nedopustimyy_put'));
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
        return new SandboxError(sprintf(t('sandbox.workspace.put_vne_rabochego_kataloga_uchastnika'), mb_substr($path, 0, 200)), 403);
    }
}
