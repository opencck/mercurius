<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Vault;

use DomainException;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemorySecretRepository;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase {
    private InMemorySecretRepository $store;
    private Vault $vault;
    private Member $owner;
    private Member $user;

    protected function setUp(): void {
        $this->store = new InMemorySecretRepository();
        $this->vault = new Vault($this->store, random_bytes(32));
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $this->user = new Member(2, MemberKind::Human, Role::user(), 'u');
    }

    public function testValuesAreStoredEncryptedAndReadByAuthorAndOwner(): void {
        $this->vault->put('db', 'password', 'p@ss-word-1', $this->user);

        self::assertStringNotContainsString('p@ss-word-1', $this->store->secrets[1]['ciphertext']);
        self::assertSame('p@ss-word-1', $this->vault->reveal('DB', $this->user));
        self::assertSame('p@ss-word-1', $this->vault->reveal('db', $this->owner));
        self::assertNull($this->vault->reveal('db', new Member(3, MemberKind::Human, Role::user(), 'x')));
        self::assertSame([['name' => 'db', 'kind' => 'password']], $this->vault->names($this->user));
        self::assertSame([], $this->vault->names(new Member(3, MemberKind::Human, Role::user(), 'x')));
    }

    public function testTheOwnerDoesNotReadTheSecretsOfAFormerOwner(): void {
        // spec: brief/participants.md#несколько-пользователей — the personal stays with the former owner
        $vault = new Vault($this->store, random_bytes(32), hidden: static fn(?int $author): bool => $author === 2);
        $vault->put('panel', 'password', 'former-owner-pass', $this->user);

        self::assertNull($vault->reveal('panel', $this->owner));
        self::assertSame([], $vault->names($this->owner));
        self::assertFalse($vault->remove('panel', $this->owner));
        self::assertSame('former-owner-pass', $vault->reveal('panel', $this->user), 'the former owner keeps it');
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

        self::assertFalse($this->vault->remove('a', new Member(3, MemberKind::Human, Role::user(), 'x')));
        self::assertTrue($this->vault->remove('a', $this->owner));
        self::assertNull($this->vault->reveal('a', $this->user));
    }
}
