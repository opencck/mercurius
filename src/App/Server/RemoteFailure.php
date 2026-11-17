<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use RuntimeException;

/** The connection, the login or the run failed; the message is safe to show (no credentials in it). */
final class RemoteFailure extends RuntimeException {
}
