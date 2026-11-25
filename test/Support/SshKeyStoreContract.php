<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\SshKeyRepository;
use OpenCCK\Domain\Ssh\SshKey;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/servers.md#ключи-ssh-в-песочнице — the keys of a participant's ~/.ssh with their fingerprints and the
 * secret that holds a key's passphrase
 */
abstract class SshKeyStoreContract extends TestCase {
    abstract protected function store(): SshKeyRepository;

    public function testAKeyIsKeptPerHolderWithItsFingerprint(): void {
        $store = $this->store();
        $store->save(new SshKey(41, 'deploy', 'SHA256:abc', 'ssh-ed25519 AAAA deploy'));
        $store->save(new SshKey(42, 'deploy', 'SHA256:xyz', 'ssh-ed25519 BBBB other'));

        $found = $store->of(41);
        self::assertCount(1, $found, 'another holder keeps its own');
        self::assertSame(['deploy', 'SHA256:abc', 'ssh-ed25519 AAAA deploy', null], [$found[0]->name, $found[0]->fingerprint, $found[0]->publicKey, $found[0]->passphraseSecret]);
        $store->remove(41, 'deploy');
        $store->remove(42, 'deploy');
    }

    public function testASavedAgainKeyIsReplacedAndKeepsItsPassphraseSecret(): void {
        $store = $this->store();
        $store->save(new SshKey(43, 'git', 'SHA256:one', 'ssh-ed25519 AAAA'));
        self::assertTrue($store->setPassphrase(43, 'git', 'git-pass'));
        $store->save(new SshKey(43, 'git', 'SHA256:two', 'ssh-ed25519 CCCC'));

        $key = $store->find(43, 'git');
        self::assertSame(['SHA256:two', 'git-pass'], [$key?->fingerprint, $key?->passphraseSecret]);
        self::assertFalse($store->setPassphrase(43, 'none', 'x'), 'no such key');
        self::assertTrue($store->remove(43, 'git'));
        self::assertFalse($store->remove(43, 'git'));
        self::assertNull($store->find(43, 'git'));
    }
}
