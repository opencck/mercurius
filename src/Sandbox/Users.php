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
        if ($principalId === Workspace::OWNERS) {
            // spec: brief/agent-loop.md#раскладка-каталогов — the root stays root's; the owners get it by ACL, and every
            //       member's folder too: the kernel checks each file, so a link a member plants opens nothing more
            $this->must(['setfacl', '-m', 'u:' . Workspace::OWNERS_UID . ':rwx', $home]);
            $names = scandir($this->workspace->root);
            foreach ($names === false ? [] : $names as $name) {
                if (preg_match('~^p(\\d+)$~', $name, $m) === 1 && (int) $m[1] > 0) {
                    $this->ensure((int) $m[1]);
                }
            }
            return;
        }
        // a folder is made without following a link: the owners write in the root and could plant p<number> there
        if (is_link($home)) {
            unlink($home);
        }
        if (!is_dir($home)) {
            mkdir($home, 0700);
        }
        $this->must(['chown', '-h', $id . ':' . $id, $home]);
        $this->share($principalId);
    }

    /**
     * The member's folder opens to the owners by ACL, the new files in it to both by default ACL. It runs as the member:
     * only the member's own files change, a link to anything else is not followed and changes nothing.
     *
     * spec: brief/agent-loop.md#раскладка-каталогов
     */
    private function share(int $principalId): void {
        $id = (string) $this->workspace->uid($principalId);
        $owners = (string) Workspace::OWNERS_UID;
        // a file the member keeps from the owners on purpose (an ACL taken off) stays so: the result is not checked
        $this->proc->run([
            'setpriv', '--reuid=' . $id, '--regid=' . $id, '--clear-groups', '--',
            'setfacl', '-R', '-P', '-m', 'u:' . $owners . ':rwX,d:u:' . $owners . ':rwX,d:u:' . $id . ':rwX', $this->workspace->home($principalId),
        ], '/', 600);
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
