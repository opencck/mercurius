<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Postgres\PostgresConnection;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Infrastructure\PostgresMemoryStore;
use Mercurius\Memory\Infrastructure\PostgresQuestionStore;
use OpenCCK\Test\Support\TestDatabase;

/**
 * The questions of a fact in the database: kept with its version, nights not found counted and started again.
 *
 * spec: brief/memory.md#самопроверка-поиска
 *
 * @group integration
 */
final class PostgresQuestionStoreTest extends AsyncTestCase {
    private PostgresConnection $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = TestDatabase::connect();
    }

    public function testQuestionsGoWithTheVersionOfTheFactAndMissesAreCounted(): void {
        $fact = (new PostgresMemoryStore($this->db))->addFact('owner', Fact::KNOWLEDGE, 'вопросы ' . bin2hex(random_bytes(4)), ['вопрос'], 0.5, Fact::AGENT, null);
        $questions = new PostgresQuestionStore($this->db);
        self::assertNull($questions->questions($fact->id, $fact->version));

        $questions->save($fact->id, $fact->version, ['где «это»', 'как это назвать']);
        self::assertSame(['где «это»', 'как это назвать'], $questions->questions($fact->id, $fact->version));
        self::assertNull($questions->questions($fact->id, $fact->version + 1), 'another version has none');
        self::assertSame(1, $questions->miss($fact->id));
        self::assertSame(2, $questions->miss($fact->id));
        $questions->found($fact->id);
        self::assertSame(1, $questions->miss($fact->id));
        $questions->save($fact->id, $fact->version + 1, ['новый']);
        self::assertSame(1, $questions->miss($fact->id), 'a new version starts again');
    }

    protected function tearDown(): void {
        $this->db->close();
        parent::tearDown();
    }
}
