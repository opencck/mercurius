<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use RuntimeException;

/** The address is internal or unknown; the message is meant for the model. */
final class AddressRefused extends RuntimeException {}
