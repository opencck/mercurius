<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Amp\Cancellation;
use SensitiveParameter;

/**
 * Runs one command on a server. The command is cut off on the server by `timeout`, stdin gets only what is given and
 * is closed, the output is kept within a limit.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh
 */
interface RemoteShell {
    /**
     * @param string|null $stdin what the command reads, then end of input (a sudo password); null — nothing
     * @throws UnknownHostKey the key of the server (or of the one in between) is not trusted yet: the owner is asked
     * @throws RemoteFailure the connection, the login or the run failed
     */
    public function run(Connection $target, string $command, int $timeout, int $maxOutput, Cancellation $cancellation, #[SensitiveParameter] ?string $stdin = null): RemoteResult;
}
