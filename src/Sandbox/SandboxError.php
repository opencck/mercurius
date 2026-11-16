<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

use RuntimeException;

/**
 * A refusal of the executor. The message is meant for the model; the status goes into the HTTP response.
 */
final class SandboxError extends RuntimeException {
    /** @param 400|401|403|404|409|413|429|500 $status */
    public function __construct(string $message, public readonly int $status = 400) {
        parent::__construct($message);
    }
}
