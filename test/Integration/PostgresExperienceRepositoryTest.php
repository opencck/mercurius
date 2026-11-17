<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\Postgres\PostgresConnection;
use OpenCCK\Domain\Agent\Experience;
use OpenCCK\Domain\Agent\HistoryScope;
use OpenCCK\Infrastructure\Repository\PostgresExperienceRepository;
use OpenCCK\Test\Support\ExperienceRepositoryContract;
use OpenCCK\Test\Support\TestDatabase;
use PHPUnit\Framework\Assert;

/**
 * @group integration
 */
final class PostgresExperienceRepositoryTest extends ExperienceRepositoryContract {
    private PostgresConnection $db;
    private ?int $principal = null;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
        $this->experience = new PostgresExperienceRepository($this->db);
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }

    public function testWordFormsMatchGoals(): void {
        $chat = $this->conversation('telegram', '10');
        $id = $this->experience->add(new Experience($chat, null, null, 'Сравнить тарифы хостингов', 'main', [], 'm', null, 'done', null, null, 1, 1, 1));

        self::assertSame($id, $this->experience->search(['хостинг'], HistoryScope::all(), 5)[0]->id ?? null);
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
}
