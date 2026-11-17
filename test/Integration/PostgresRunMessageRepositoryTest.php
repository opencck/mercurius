<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Infrastructure\Repository\PostgresRunMessageRepository;
use OpenCCK\Infrastructure\Repository\PostgresRunRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresRunMessageRepositoryTest extends AsyncTestCase {
    public function testAMessageNamesTheRunThatWroteIt(): void {
        $db = TestDatabase::connect();
        [$principalId, $conversationId] = TestDatabase::conversation($db);
        $run = (new PostgresRunRepository($db))->start($conversationId, $principalId, 'm', new RunLimits(30, 1000, 900, 120));
        self::assertNotNull($run);
        $messages = new PostgresRunMessageRepository($db);

        $messages->record($run->id, 'telegram', '42', '295');
        $messages->record($run->id, 'telegram', '42', '295');

        self::assertSame($run->id, $messages->runOf('telegram', '42', '295'));
        self::assertNull($messages->runOf('telegram', '43', '295'), 'a message number is per chat');
        $db->close();
    }
}
