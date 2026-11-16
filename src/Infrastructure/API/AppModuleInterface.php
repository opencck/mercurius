<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\API;

/**
 * A long-running part of the application (HTTP server, Telegram polling, scheduler, ...).
 */
interface AppModuleInterface {
    public function start(): void;

    public function stop(): void;
}
