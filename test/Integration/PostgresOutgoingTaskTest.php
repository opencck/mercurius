<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresPeerRepository;
use OpenCCK\Test\Support\TestDatabase;
use PHPUnit\Framework\Assert;

/**
 * spec: brief/network.md#подключения-агентов — a task goes to an agent that connected here too,
 * though it is not a neighbour in peers
 *
 * @group integration
 */
final class PostgresOutgoingTaskTest extends AsyncTestCase {
    public function testATaskToAConnectedAgentIsKept(): void {
        $db = TestDatabase::connect();
        $row = $db->execute("INSERT INTO principals (kind, role) VALUES ('human', 'user') RETURNING id")->fetchRow();
        $principal = $row['id'] ?? null;
        Assert::assertIsInt($principal);
        $name = 'linked-' . bin2hex(random_bytes(4));

        $out = (new PostgresPeerRepository($db))->addOutgoing($name, 'task-1', $principal, 'telegram', '42', 'посчитай', 'TASK_STATE_SUBMITTED');

        self::assertSame($name, $out->peer);
        $db->execute('DELETE FROM a2a_outgoing WHERE peer = ?', [$name]);
        $db->execute('DELETE FROM principals WHERE id = ?', [$principal]);
        $db->close();
    }
}
