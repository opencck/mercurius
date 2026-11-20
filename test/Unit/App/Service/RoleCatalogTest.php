<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use InvalidArgumentException;
use OpenCCK\App\Service\RoleCatalog;
use OpenCCK\Domain\Entity\Power;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryRoleRepository;
use PHPUnit\Framework\TestCase;

/**
 * The owner keeps any list of roles: adds, changes, orders and removes them; a role someone has stays.
 *
 * spec: brief/participants.md#свои-роли
 */
final class RoleCatalogTest extends TestCase {
    private InMemoryRoleRepository $store;
    private RoleCatalog $catalog;

    protected function setUp(): void {
        $this->store = new InMemoryRoleRepository(static fn(string $name): bool => $name === 'user');
        $this->catalog = new RoleCatalog($this->store);
        $this->catalog->load();
    }

    protected function tearDown(): void {
        Role::reset();
    }

    public function testARoleIsAddedGivenPowersAndATemplateAndOrdered(): void {
        $this->catalog->add('trader', 'Трейдер');
        $this->catalog->set('trader', 'powers', 'tools, reports');
        $this->catalog->set('trader', 'tools', 'web_search,http_request');
        $this->catalog->set('trader', 'max_turns', '40');
        $this->catalog->set('trader', 'position', '5');

        $trader = Role::of('trader');
        self::assertSame('Трейдер', $trader->title());
        self::assertTrue($trader->may(Power::Reports));
        self::assertFalse($trader->may(Power::Secrets));
        self::assertSame(['tools' => ['web_search', 'http_request'], 'max_turns' => 40], $this->catalog->template($trader));
        self::assertSame('trader', Role::assignable()[0]->value, 'first in the order');

        // read anew from the store, as after a restart
        Role::reset();
        (new RoleCatalog($this->store))->load();
        self::assertTrue(Role::of('trader')->may(Power::Tools));
        self::assertStringContainsString('trader «Трейдер» — tools, reports', $this->catalog->describe());
    }

    public function testTheOwnerKeepsTheListByCommandsInTheirPrivateChat(): void {
        $stack = new \OpenCCK\Test\Support\AgentStack();
        $stack->principals->ensureOwner('telegram', '42', 'Владелец');
        $stack->chat->useRoles($this->catalog);

        $stack->say('/role add trader Трейдер рынка');
        $stack->say('/role set trader powers tools,reports');
        $stack->say('/role set trader powers fly');
        $stack->say('/roles');
        $stack->sayInGroup('/roles');

        $sent = $stack->channel->texts('send');
        self::assertContains(sprintf(\Mercurius\I18n\t('roles.added'), 'trader'), $sent);
        self::assertContains(sprintf(\Mercurius\I18n\t('roles.changed'), 'trader'), $sent);
        self::assertStringContainsString('fly', implode("\n", $sent));
        self::assertStringContainsString('trader «Трейдер рынка» — tools, reports', implode("\n", $sent));
        self::assertContains(\Mercurius\I18n\t(\OpenCCK\App\Service\ChatService::PRIVATE_ONLY), $sent);
    }

    public function testBadInputAndAHeldRoleAreRefused(): void {
        foreach ([
            fn(): string => $this->catalog->add('owner', 'x'),
            fn(): string => $this->catalog->add('Bad Name', 'x'),
            fn(): string => $this->catalog->add('user', 'x'),
            fn(): string => $this->catalog->set('user', 'powers', 'fly'),
            fn(): string => $this->catalog->set('user', 'max_turns', '0'),
            fn(): string => $this->catalog->set('nobody', 'title', 'x'),
            fn(): string => $this->catalog->remove('user'),
        ] as $i => $call) {
            try {
                $call();
                self::fail('not refused: #' . $i);
            } catch (InvalidArgumentException) {
            }
        }
        $this->catalog->remove('visitor');
        self::assertNotContains(Role::visitor(), Role::assignable(), 'a role nobody has is removed');
    }
}
