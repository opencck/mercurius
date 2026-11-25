<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use Amp\Future;
use Throwable;

use function Amp\async;

use function Mercurius\I18n\t;

/**
 * Creates the participant's Unix user and home on first use. Users do not survive a container rebuild, but the uid is
 * fixed (20000 + id), so files on the workspace volume keep their owner. Parallel first operations of a participant
 * wait for one creation instead of racing.
 *
 * spec: brief/agent-loop.md#песочница — a Unix user per participant, home 0700
 */
final class Users {
    /** groupadd/useradd exit code: the group or user already exists. */
    private const EXISTS = 9;

    /** @var array<int, Future<void>> */
    private array $ready = [];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Proc $proc = new Proc(),
    ) {}

    /** @throws SandboxError */
    public function ensure(int $principalId): void {
        $this->ready[$principalId] ??= async($this->create(...), $principalId);
        try {
            $this->ready[$principalId]->await();
        } catch (Throwable $e) {
            unset($this->ready[$principalId]);
            throw $e;
        }
    }

    /** @throws SandboxError */
    private function create(int $principalId): void {
        $user = $this->workspace->user($principalId);
        $id = (string) $this->workspace->uid($principalId);
        $home = $this->workspace->home($principalId);
        if ($this->proc->run(['getent', 'passwd', $user], '/', 10)->exitCode !== 0) {
            $this->must(['groupadd', '--gid', $id, $user]);
            $this->must(['useradd', '--uid', $id, '--gid', $id, '--no-create-home', '--home-dir', $home, '--shell', '/bin/bash', $user]);
        }
        if ($principalId !== Workspace::OWNERS) {
            // the root stays root's: the owners enter it by the override of file rights, not as its owner
            $this->must(['install', '-d', '-o', $id, '-g', $id, '-m', '0700', $home]);
        }
    }

    /**
     * Files the owners made in a member's folder become the member's: the folder stays theirs alone.
     *
     * spec: brief/agent-loop.md#раскладка-каталогов — a file made by the owners in a member's folder goes to its holder
     */
    public function handOver(): void {
        $names = scandir($this->workspace->root);
        foreach ($names === false ? [] : $names as $name) {
            if (preg_match('~^p(\d+)$~', $name, $m) !== 1) {
                continue;
            }
            $uid = (string) $this->workspace->uid((int) $m[1]);
            $this->proc->run(['find', $this->workspace->root . '/' . $name, '-xdev', '-uid', (string) Workspace::OWNERS_UID, '-exec', 'chown', '-h', $uid . ':' . $uid, '{}', '+'], '/', 120);
        }
    }

    /**
     * @param list<string> $argv
     * @throws SandboxError
     */
    private function must(array $argv): void {
        $result = $this->proc->run($argv, '/', 30);
        if ($result->exitCode !== 0 && $result->exitCode !== self::EXISTS) {
            throw new SandboxError(sprintf(t('sandbox.users.ne_udalos_podgotovit_polzovatelya_pesoch'), $argv[0]), 500);
        }
    }
}
