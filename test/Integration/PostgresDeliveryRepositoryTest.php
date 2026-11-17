<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Agent\Delivery;
use OpenCCK\Infrastructure\Repository\PostgresDeliveryRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresDeliveryRepositoryTest extends AsyncTestCase {
    public function testAnObligationGoesThroughItsStates(): void {
        $db = TestDatabase::connect();
        $deliveries = new PostgresDeliveryRepository($db);
        $done = $deliveries->open(null, 'telegram', '42', '12', '7', 'готово', 'markdown');
        $deliveries->attempting($done);
        $deliveries->progress($done, 1);
        $deliveries->delivered($done);
        $lost = $deliveries->open(null, 'telegram', '42', null, null, 'не дошло', 'plain', time() - 100);
        $deliveries->progress($lost, 1);
        $deliveries->failed($lost, 'down');

        $open = $deliveries->undelivered();

        self::assertCount(1, $open, 'a delivered answer is not open');
        self::assertSame([$lost, Delivery::FAILED, 1, 1, 'не дошло', null], [$open[0]->id, $open[0]->status, $open[0]->sent, $open[0]->attempts, $open[0]->text, $open[0]->threadId]);
        self::assertEqualsWithDelta(time() - 100, $open[0]->createdAt, 5);
        $deliveries->abandon($lost);
        self::assertCount(0, $deliveries->undelivered());
        $db->close();
    }
}
