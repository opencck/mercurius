<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SshHostKeyRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/servers.md#ssh-как-предустановленный-mcp — the host key of an entry is kept by the connection and the
 * address it was seen at; the owner's press decides the key the card showed
 */
abstract class SshHostKeyStoreContract extends TestCase {
    abstract protected function store(): SshHostKeyRepository;

    public function testASeenKeyWaitsAndTheCardDecidesOnlyTheKeyItShowed(): void {
        $store = $this->store();
        $server = 'member:1|base_' . bin2hex(random_bytes(3));

        $row = $store->setPending($server, 'srv.example:22', 'SHA256:one');
        self::assertSame([null, 'SHA256:one'], [$row->hostKey, $row->pending]);
        self::assertNull($store->decide($row->id, true, 'SHA256:two'), 'another key came in its place');

        $trusted = $store->decide($row->id, true, 'SHA256:one');
        self::assertSame(['SHA256:one', null], [$trusted?->hostKey, $trusted?->pending]);
        self::assertSame('SHA256:one', $store->of($server, 'srv.example:22')?->hostKey);
        self::assertNull($store->of($server, 'srv.example:2222'), 'another address is another key');
    }

    public function testARefusalAndForgettingDropTheKey(): void {
        $store = $this->store();
        $server = 'project:2|db_' . bin2hex(random_bytes(3));
        $row = $store->setPending($server, '[::1]:22', 'SHA256:a');

        self::assertNull($store->decide($row->id, false, 'SHA256:a')?->pending);
        $store->pin($server, '[::1]:22', 'SHA256:b');
        self::assertSame('SHA256:b', $store->of($server, '[::1]:22')?->hostKey);
        self::assertNull($store->forget($row->id)?->hostKey);
        self::assertSame($server, $store->find($row->id)?->server);
    }
}
