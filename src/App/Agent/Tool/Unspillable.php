<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool whose result is never moved into a file: read_file reads those files, spilling it would loop.
 *
 * ref: references/hermes-agent/tools/budget_config.py@e96221fa:L10-L20 — read_file has no spill threshold
 */
interface Unspillable {}
