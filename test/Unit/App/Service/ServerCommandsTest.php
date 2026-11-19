<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\ServerCommands;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryCommandAuditRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemoryServerRepository;
use OpenCCK\Test\Support\ScriptedLlm;

final class ServerCommandsTest extends AsyncTestCase {
    private Vault $vault;
    private AgentStack $stack;

    protected function setUp(): void {
        parent::setUp();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->stack = new AgentStack(new ScriptedLlm([]));
        $this->stack->useServers(new ServerCommands($this->vault, new InMemoryServerRepository(), new InMemoryCommandAuditRepository()));
    }

    public function testASecretIsStoredAndItsMessageDeleted(): void {
        $this->stack->say('/secret web-pass Sup3r-secret-pass');

        $owner = new Principal(1, PrincipalKind::Human, Role::owner(), null);
        self::assertSame('Sup3r-secret-pass', $this->vault->reveal('web-pass', $owner));
        self::assertCount(1, $this->stack->channel->deleted, 'the message with the secret is gone');
        $replies = implode("\n", $this->stack->channel->texts('send'));
        self::assertStringContainsString('Секрет «web-pass» сохранён (пароль)', $replies);
        self::assertStringNotContainsString('Sup3r-secret-pass', $replies);
        self::assertSame([], $this->stack->llm->requests, 'the model never saw it');
        self::assertSame([], $this->stack->transcript->messages, 'nor the transcript');
    }

    public function testInAGroupTheSecretIsRefusedButStillDeleted(): void {
        $this->stack->sayInGroup('/secret web-pass Sup3r-secret-pass');

        self::assertSame([], $this->vault->names(new Principal(1, PrincipalKind::Human, Role::owner(), null)));
        self::assertCount(1, $this->stack->channel->deleted);
        self::assertStringContainsString('только в личном чате', implode("\n", $this->stack->channel->texts('send')));
    }

    public function testWhenTheMessageCannotBeDeletedTheOwnerIsTold(): void {
        $this->stack->channel->deleteFails = true;

        $this->stack->say('/secret key2 another-secret-value');

        self::assertStringContainsString('удалите его сами', implode("\n", $this->stack->channel->texts('send')));
    }

    public function testListsShowNamesOnlyAndTheJournalIsForTheOwner(): void {
        $this->stack->say('/secret db-pass value-of-db');
        $this->stack->say('/secrets');
        $this->stack->say('/audit');
        $this->stack->say('/servers');

        $replies = implode("\n", $this->stack->channel->texts('send'));
        self::assertStringContainsString('db-pass — password', $replies);
        self::assertStringNotContainsString('value-of-db', $replies);
        self::assertStringContainsString('Журнал команд пуст', $replies);
        self::assertStringContainsString('Цепочка журнала цела', $replies);
        self::assertStringContainsString('Серверов в реестре нет', $replies);
    }
}
