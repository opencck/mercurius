<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Agent;

/**
 * A row of the model role table: which model plays the role, why, and who set it.
 *
 * spec: brief/agent-loop.md#модели
 */
final class ModelRole {
    public const CHEAP = 'cheap';
    public const MAIN = 'main';
    public const STRONG = 'strong';
    public const MEDIA = 'media';
    public const ALL = [self::CHEAP, self::MAIN, self::STRONG, self::MEDIA];

    public const SET_BY_SEED = 'seed';
    /** the agent itself or a participant the owner allowed to change roles */
    public const SET_BY_AGENT = 'agent';
    /** fixed by the owner: nobody else changes it */
    public const SET_BY_OWNER = 'owner';
    /** not a record: the first model of the role's list answers, because the recorded choice is gone from the list */
    public const SET_BY_LIST = 'list';

    public function __construct(
        public readonly string $role,
        public readonly ModelRef $model,
        public readonly string $reason,
        public readonly string $setBy,
        public readonly ?int $setByPrincipal = null,
    ) {}
}
