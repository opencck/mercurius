<?php

declare(strict_types=1);

namespace OpenCCK\App\Server;

use RuntimeException;

/** The server showed a key nobody trusted yet (first connection) or a key that differs from the trusted one. */
final class UnknownHostKey extends RuntimeException {
    public function __construct(
        public readonly string $fingerprint,
        /** a trusted key exists and this one differs */
        public readonly bool $changed,
        /** which server of the registry: the target or the one in between */
        public readonly ?string $server = null,
    ) {
        parent::__construct($changed ? 'Host key changed: ' . $fingerprint : 'Unknown host key: ' . $fingerprint);
    }
}
