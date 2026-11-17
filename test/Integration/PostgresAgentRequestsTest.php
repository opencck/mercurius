<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Peer\AgentRequest;
use OpenCCK\Infrastructure\Repository\PostgresPeerRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresAgentRequestsTest extends AsyncTestCase {
    public function testOneRequestPerKeyDecidedOnceAndCapped(): void {
        $db = TestDatabase::connect();
        $peers = new PostgresPeerRepository($db);
        $db->query("DELETE FROM agent_requests WHERE status = 'pending'");
        $key = 'SHA256:' . bin2hex(random_bytes(32));

        $first = $peers->requestAccess($key, 'tester', 'https://t.example', 'ws', 2);
        $again = $peers->requestAccess($key, 'other', '', 'http', 2);

        self::assertNotNull($first);
        self::assertTrue($first[1]);
        self::assertSame(['tester', 'https://t.example', 'ws', AgentRequest::PENDING], [$first[0]->name, $first[0]->url, $first[0]->transport, $first[0]->status]);
        self::assertSame([$first[0]->id, false], [$again[0]->id ?? null, $again[1] ?? null], 'the same key keeps its request');

        self::assertNotNull($peers->requestAccess('SHA256:' . bin2hex(random_bytes(32)), 'b', '', 'ws', 2));
        self::assertNull($peers->requestAccess('SHA256:' . bin2hex(random_bytes(32)), 'c', '', 'ws', 2), 'no more than the cap wait');

        self::assertSame(AgentRequest::ALLOWED, $peers->decideRequest($first[0]->id, AgentRequest::ALLOWED)?->status);
        self::assertNull($peers->decideRequest($first[0]->id, AgentRequest::REJECTED), 'decided once');
        self::assertSame(AgentRequest::ALLOWED, $peers->requestAccess($key, 'x', '', 'ws', 2)[0]->status ?? null);

        $lost = $peers->requestAccess('SHA256:' . bin2hex(random_bytes(32)), 'lost', '', 'ws', 5);
        self::assertNotNull($lost);
        $peers->dropRequest($lost[0]->id);
        $peers->dropRequest($first[0]->id);
        self::assertNull($peers->decideRequest($lost[0]->id, AgentRequest::ALLOWED), 'a dropped request is gone');
        self::assertNotNull($peers->requestAccess($key, 'x', '', 'ws', 5), 'a decided request is not dropped');
        $db->close();
    }

    public function testAKeptConnectionIsRemembered(): void {
        $db = TestDatabase::connect();
        $peers = new PostgresPeerRepository($db);
        $name = 'keep-' . bin2hex(random_bytes(3));
        $peers->addPeer($name, 'https://k.example', 'secret', 'key', null);

        self::assertFalse($peers->peer($name)?->keep);
        self::assertTrue($peers->setKeep($name, true));
        $peers->addPeer($name, 'https://k2.example', 'secret', 'key', null);
        self::assertTrue($peers->peer($name)?->keep, 'a refreshed card does not drop it');
        self::assertFalse($peers->setKeep('no-such-' . $name, true));
        $peers->removePeer($name);
        $db->close();
    }
}
