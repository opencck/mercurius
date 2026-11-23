<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Context;

use Closure;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Repository\ContextLimitRepository;
use OpenCCK\Domain\Repository\ConversationRepository;

/**
 * The limits a conversation goes by: its project's, else in a private chat its participant's, else the agent's; each
 * of the two values separately, so a project may set only its percent and keep the agent's tokens.
 *
 * spec: brief/agent-loop.md#объём-беседы-и-порог-сжатия
 */
final class ContextLimits {
    /** a conversation holds at least this many tokens: below it the system prompt and a turn do not fit */
    public const MIN_TOKENS = 8000;
    public const MIN_PERCENT = 10;
    public const MAX_PERCENT = 95;

    /**
     * @param Closure(ConversationMeta): ?int $project the active project of the conversation's place
     * @param Closure(): array{?int, ?int} $agent the agent's own tokens and percent; null — the model's window, 75% (50% from 512K)
     */
    public function __construct(
        private readonly ContextLimitRepository $limits,
        private readonly ConversationRepository $conversations,
        private readonly Closure $project,
        private readonly Closure $agent,
    ) {}

    /** @return array{?int, ?int} tokens and percent */
    public function of(int $conversationId): array {
        [$tokens, $percent] = ($this->agent)();
        $meta = $this->conversations->meta($conversationId);
        if ($meta === null) {
            return [$tokens, $percent];
        }
        $project = ($this->project)($meta);
        $own = match (true) {
            $project !== null => $this->limits->get(ContextLimitRepository::PROJECT, $project),
            $this->conversations->privateChat($conversationId) === true => $this->limits->get(ContextLimitRepository::PRINCIPAL, $meta->principalId),
            default => [null, null],
        };
        return [$own[0] ?? $tokens, $own[1] ?? $percent];
    }

    /**
     * Reads a value a tool was given: 0 — back to the default (null); out of the range — the error to show.
     *
     * @return array{?int, ?string} the value and the error
     */
    public static function tokens(int $value): array {
        return match (true) {
            $value === 0 => [null, null],
            $value < self::MIN_TOKENS => [null, sprintf(\Mercurius\I18n\t('agent.context_limits.bad_tokens'), self::MIN_TOKENS)],
            default => [$value, null],
        };
    }

    /** @return array{?int, ?string} */
    public static function percent(int $value): array {
        return match (true) {
            $value === 0 => [null, null],
            $value < self::MIN_PERCENT || $value > self::MAX_PERCENT => [null, sprintf(\Mercurius\I18n\t('agent.context_limits.bad_percent'), self::MIN_PERCENT, self::MAX_PERCENT)],
            default => [$value, null],
        };
    }

    /** One line of what holds now, for the tools. */
    public static function describe(?int $tokens, ?int $percent): string {
        return sprintf(
            \Mercurius\I18n\t('agent.context_limits.now'),
            $tokens === null ? \Mercurius\I18n\t('agent.context_limits.window') : (string) $tokens,
            $percent === null ? \Mercurius\I18n\t('agent.context_limits.auto') : $percent . '%',
        );
    }
}
