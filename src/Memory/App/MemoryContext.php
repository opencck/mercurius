<?php

declare(strict_types=1);

namespace OpenCCK\Memory\App;

use OpenCCK\Memory\Domain\MemoryStore;

/**
 * What memory adds to a run: layer 1 for the system prompt and the facts that answer the message.
 *
 * spec: BRIEF.md#память-слои — layer 1 is always in context for the owner; facts come before the turn
 * ref: references/hermes-agent/agent/turn_context.py@e96221fa:L125-L136 (compose_user_api_content) — recalled memory
 *      goes after the user's text, marked as reference data, and is kept in the message so the next turn replays the
 *      same bytes; here it is kept in the transcript
 */
final class MemoryContext {
    public const PROFILE = 'profile';
    public const SUMMARY = 'summary';

    public function __construct(
        private readonly MemoryStore $store,
        private readonly Recall $recall,
    ) {}

    /**
     * @param list<string> $scopes
     * @return string facts as lines, '' when nothing answers the message
     */
    public function facts(string $message, array $scopes, int $conversationId): string {
        return Recall::format($this->recall->find($message, $scopes, $conversationId));
    }

    /** Layer 1: the owner's profile and the summary of what is going on; '' while both are empty. */
    public function core(): string {
        $parts = [];
        foreach ([self::PROFILE => 'Профиль владельца', self::SUMMARY => 'Текущие дела'] as $section => $title) {
            $text = trim($this->store->core($section));
            if ($text !== '') {
                $parts[] = $title . ":\n" . $text;
            }
        }
        return implode("\n\n", $parts);
    }
}
