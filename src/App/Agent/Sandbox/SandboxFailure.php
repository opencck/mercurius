<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Sandbox;

use RuntimeException;

/** The sandbox refused or is unreachable; the message is meant for the model. */
final class SandboxFailure extends RuntimeException {}
