<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use RuntimeException;

/**
 * Reason of a cancellation caused by the application stopping: the run keeps its running status and resumes on start.
 */
final class ShutdownException extends RuntimeException {
    public function __construct() {
        parent::__construct('The application is stopping');
    }
}
