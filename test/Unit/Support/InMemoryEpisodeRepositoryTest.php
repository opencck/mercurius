<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Support;

use OpenCCK\Test\Support\EpisodeRepositoryContract;
use OpenCCK\Test\Support\InMemoryEpisodeRepository;

final class InMemoryEpisodeRepositoryTest extends EpisodeRepositoryContract {
    private InMemoryEpisodeRepository $store;

    protected function setUp(): void {
        parent::setUp();
        $this->episodes = $this->store = new InMemoryEpisodeRepository();
    }

    protected function conversation(string $channel, string $chatId): int {
        return $this->store->addConversation($channel, $chatId);
    }

    protected function message(int $conversationId, string $role, string $content): int {
        return $this->store->addMessage($conversationId, $role, $content);
    }
}
