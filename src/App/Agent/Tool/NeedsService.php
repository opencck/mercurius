<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool of a module service: offered only while that service is up.
 *
 * spec: brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы
 */
interface NeedsService {
    /** The module's name, as in ServiceModules: media-video, media-docs, browser. */
    public function service(): string;
}
