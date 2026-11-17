<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Infrastructure\Repository\PostgresEpisodeRepository;
use OpenCCK\Test\Support\EpisodeRepositoryContract;
use OpenCCK\Test\Support\TestDatabase;
use PHPUnit\Framework\Assert;

/**
 * @group integration
 */
final class PostgresEpisodeRepositoryTest extends EpisodeRepositoryContract {
    private PostgresConnection $db;
    private ?int $principal = null;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->episodes = new PostgresEpisodeRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testWordFormsMatch(): void {
        $chat = $this->conversation('telegram', '10');
        $id = $this->message($chat, 'user', 'Вчера настроили резервные копии базы');
        $en = $this->message($chat, 'assistant', 'The backups are running');

        $found = $this->episodes->search(['копия базе'], HistoryScope::all(), 8, 2);
        self::assertSame($id, $found[0]->id ?? null);
        self::assertSame($en, $this->episodes->search(['backup'], HistoryScope::all(), 8, 2)[0]->id ?? null);
    }

    protected function conversation(string $channel, string $chatId): int {
        if ($this->principal === null) {
            $row = $this->db->execute("INSERT INTO principals (kind, role) VALUES ('human', 'user') RETURNING id")->fetchRow();
            $principal = $row['id'] ?? null;
            Assert::assertIsInt($principal);
            $this->principal = $principal;
        }
        $row = $this->db->execute('INSERT INTO conversations (principal_id, channel, chat_id, closed_at) VALUES (?, ?, ?, now()) RETURNING id', [$this->principal, $channel, $chatId])->fetchRow();
        $id = $row['id'] ?? null;
        Assert::assertIsInt($id);
        return $id;
    }

    protected function message(int $conversationId, string $role, string $content): int {
        $row = $this->db->execute('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?) RETURNING id', [$conversationId, $role, $content])->fetchRow();
        $id = $row['id'] ?? null;
        Assert::assertIsInt($id);
        return $id;
    }
}
