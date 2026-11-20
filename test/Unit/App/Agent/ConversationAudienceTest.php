<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use PHPUnit\Framework\TestCase;

final class ConversationAudienceTest extends TestCase {
    public function testTheKindOfAChatOutlivesARestart(): void {
        // spec: brief/decisions.md#контекст-по-месту-и-аудитории — a run after a restart keeps the memory of a private chat
        $conversations = new InMemoryConversationRepository();
        $private = $conversations->openConversation(1, 'telegram', '42');
        $group = $conversations->openConversation(1, 'telegram', '-100');
        $before = new ConversationAudience($conversations);
        $before->remember($private, true);
        $before->remember($group, false);

        $after = new ConversationAudience($conversations);

        self::assertTrue($after->isPrivate($private));
        self::assertFalse($after->isPrivate($group));
        self::assertFalse($after->isPrivate(999), 'not known: the narrowest audience');
    }
}
