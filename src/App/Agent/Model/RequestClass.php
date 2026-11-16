<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Model;

/**
 * A rough class of a request, without scores on axes; the full task profile comes later.
 *
 * spec: BRIEF.md#модели — classification: short or typical, normal, complex
 */
enum RequestClass: string {
    case Short = 'short';
    case Normal = 'normal';
    case Complex = 'complex';
}
