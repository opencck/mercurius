<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

/**
 * A tool of a category of models: offered only while the category has a model. The owner fills and empties a category
 * in the dialog, so the answer is asked at each request, not once at the start.
 *
 * spec: brief/models.md#категории-моделей — an empty category means the ability is not there
 */
interface Available {
    public function available(): bool;
}
