<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

/**
 * Creates the participant's Unix user and home on first use. Users do not survive a container rebuild, but the uid is
 * fixed (20000 + id), so files on the workspace volume keep their owner.
 *
 * spec: BRIEF.md#песочница — a Unix user per participant, home 0700
 */
final class Users {
    /** @var array<int, true> */
    private array $known = [];

    public function __construct(
        private readonly Workspace $workspace,
        private readonly Proc $proc = new Proc(),
    ) {}

    /** @throws SandboxError */
    public function ensure(int $principalId): void {
        if (isset($this->known[$principalId])) {
            return;
        }
        $user = $this->workspace->user($principalId);
        $id = (string) $this->workspace->uid($principalId);
        $home = $this->workspace->home($principalId);
        if ($this->proc->run(['getent', 'passwd', $user], '/', 10)->exitCode !== 0) {
            $this->must(['groupadd', '--gid', $id, $user]);
            $this->must(['useradd', '--uid', $id, '--gid', $id, '--no-create-home', '--home-dir', $home, '--shell', '/bin/bash', $user]);
        }
        $this->must(['install', '-d', '-o', $id, '-g', $id, '-m', '0700', $home]);
        $this->known[$principalId] = true;
    }

    /**
     * @param list<string> $argv
     * @throws SandboxError
     */
    private function must(array $argv): void {
        $result = $this->proc->run($argv, '/', 30);
        if ($result->exitCode !== 0) {
            throw new SandboxError(sprintf('Не удалось подготовить пользователя песочницы (%s).', $argv[0]), 500);
        }
    }
}
