<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Vault;

use DomainException;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemorySecretRepository;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase {
    private InMemorySecretRepository $store;
    private Vault $vault;
    private Principal $owner;
    private Principal $user;

    protected function setUp(): void {
        $this->store = new InMemorySecretRepository();
        $this->vault = new Vault($this->store, random_bytes(32));
        $this->owner = new Principal(1, PrincipalKind::Human, Role::Owner, null);
        $this->user = new Principal(2, PrincipalKind::Human, Role::User, 'u');
    }

    public function testValuesAreStoredEncryptedAndReadByAuthorAndOwner(): void {
        $this->vault->put('db', 'password', 'p@ss-word-1', $this->user);

        self::assertStringNotContainsString('p@ss-word-1', $this->store->secrets[1]['ciphertext']);
        self::assertSame('p@ss-word-1', $this->vault->reveal('DB', $this->user));
        self::assertSame('p@ss-word-1', $this->vault->reveal('db', $this->owner));
        self::assertNull($this->vault->reveal('db', new Principal(3, PrincipalKind::Human, Role::User, 'x')));
        self::assertSame([['name' => 'db', 'kind' => 'password']], $this->vault->names($this->user));
        self::assertSame([], $this->vault->names(new Principal(3, PrincipalKind::Human, Role::User, 'x')));
    }

    public function testACiphertextMovedUnderAnotherNameDoesNotOpen(): void {
        $this->vault->put('a', 'password', 'secret-a', $this->owner);
        $this->vault->put('b', 'password', 'secret-b', $this->owner);
        $this->store->secrets[2] = ['name' => 'b'] + $this->store->secrets[1];

        self::assertNull($this->vault->reveal('b', $this->owner));
    }

    public function testAnotherKeyOpensNothing(): void {
        $this->vault->put('a', 'token', 'secret-a', $this->owner);

        self::assertNull((new Vault($this->store, random_bytes(32)))->reveal('a', $this->owner));
    }

    public function testOffWithoutAKeyAndNamesAreChecked(): void {
        $off = new Vault($this->store, null);
        self::assertFalse($off->enabled());
        try {
            $off->put('a', 'password', 'x', $this->owner);
            self::fail('the vault is off');
        } catch (DomainException) {
        }
        $this->expectException(DomainException::class);
        $this->vault->put('bad name', 'password', 'x', $this->owner);
    }

    public function testOnlyTheAuthorOrTheOwnerRemoves(): void {
        $this->vault->put('a', 'password', 'x', $this->user);

        self::assertFalse($this->vault->remove('a', new Principal(3, PrincipalKind::Human, Role::User, 'x')));
        self::assertTrue($this->vault->remove('a', $this->owner));
        self::assertNull($this->vault->reveal('a', $this->user));
    }
}
