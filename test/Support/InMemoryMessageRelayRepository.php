<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DateTimeImmutable;
use OpenCCK\Domain\Relay\MessageRelay;
use OpenCCK\Domain\Repository\MessageRelayRepository;

final class InMemoryMessageRelayRepository implements MessageRelayRepository {
    /** @var array<int, MessageRelay> open questions by id */
    public array $relays = [];
    private int $next = 1;

    public function create(string $channel, string $recipientChatId, int $recipientPrincipalId, int $originConversationId, int $originPrincipalId, ?int $originRunId, string $question): int {
        foreach ($this->relays as $old => $relay) {
            if ($relay->channel === $channel && $relay->recipientChatId === $recipientChatId && $relay->originConversationId === $originConversationId) {
                unset($this->relays[$old]);
            }
        }
        $id = $this->next++;
        $this->relays[$id] = new MessageRelay($id, $channel, $recipientChatId, $recipientPrincipalId, $originConversationId, $originPrincipalId, $originRunId, $question);
        return $id;
    }

    public function takeOpen(string $channel, string $recipientChatId, DateTimeImmutable $since): ?MessageRelay {
        foreach ($this->relays as $id => $relay) {
            if ($relay->channel === $channel && $relay->recipientChatId === $recipientChatId) {
                unset($this->relays[$id]);
                return $relay;
            }
        }
        return null;
    }
}
