<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Sandbox\CommandResult;
use OpenCCK\Sandbox\CommandRunner;
use OpenCCK\Sandbox\Files;
use OpenCCK\Sandbox\Users;
use OpenCCK\Sandbox\Workspace;

/**
 * Real users, processes and files: needs Linux, root and util-linux. Runs only in the sandbox container:
 * docker compose --profile test run --rm sandbox-test
 *
 * @group sandbox
 */
abstract class SandboxTestCase extends AsyncTestCase {
    protected Workspace $workspace;
    protected Files $files;
    protected CommandRunner $runner;

    protected function setUp(): void {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs Linux and root (the sandbox container).');
        }
        $root = '/tmp/ws-' . bin2hex(random_bytes(4));
        mkdir($root, 0711);
        $this->workspace = new Workspace($root);
        $users = new Users($this->workspace);
        $this->files = new Files($this->workspace, $users);
        $this->runner = new CommandRunner($this->workspace, $users, $this->files);
        $this->files->attach($this->runner);
    }

    protected static function limits(int $disk = 50 * 1024 * 1024, int $processes = 64, int $concurrent = 4): SandboxLimits {
        return new SandboxLimits($disk, $processes, 60, 8 * 1024 ** 3, $concurrent);
    }

    /** @param positive-int $timeout */
    protected function sh(int $principalId, string $command, int $timeout = 10, ?SandboxLimits $limits = null): CommandResult {
        return $this->runner->run($principalId, $limits ?? self::limits(), $command, $this->workspace->home($principalId), $timeout, null, new NullCancellation());
    }
}
