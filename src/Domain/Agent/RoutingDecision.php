<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * Why a conversation got its model: the rough class of the first request, how it was found, the role and model.
 *
 * spec: BRIEF.md#модели — every choice is logged with a reason, as data for the full model selection
 */
final class RoutingDecision {
    public function __construct(
        public readonly ?int $conversationId,
        /** short, normal or complex */
        public readonly string $class,
        /** heuristic, model or fallback */
        public readonly string $method,
        public readonly string $role,
        public readonly string $model,
        public readonly string $reason,
    ) {}
}
