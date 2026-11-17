<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use Amp\Cancellation;
use OpenCCK\Domain\Server\Server;
use SensitiveParameter;

/**
 * Runs one command on a server. The command is cut off on the server by `timeout`, stdin is closed at once, the output
 * is kept within a limit.
 *
 * spec: BRIEF.md#фаза-7-по-частям
 */
interface RemoteShell {
    /**
     * @param string $credential the password or the private key, from the vault, used for this connection only
     * @param string $kind password or private_key
     * @throws UnknownHostKey the server's key is not trusted yet: the owner is asked
     * @throws RemoteFailure the connection, the login or the run failed
     */
    public function run(Server $server, #[SensitiveParameter] string $credential, string $kind, string $command, int $timeout, int $maxOutput, Cancellation $cancellation): RemoteResult;
}
