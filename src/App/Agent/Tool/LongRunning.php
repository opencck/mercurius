<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool that runs other runs (delegation): it is bounded by its own timeout and the time left in the run, not by the
 * per-tool limit meant for single commands and requests.
 */
interface LongRunning {}
