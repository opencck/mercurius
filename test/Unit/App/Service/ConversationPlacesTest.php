<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use OpenCCK\App\Service\ConversationPlaces;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/participants.md#контекст-беседы-в-личном-чате
 */
final class ConversationPlacesTest extends TestCase {
    private InMemoryConversationRepository $conversations;
    private InMemoryProjectRepository $projects;
    private ConversationPlaces $places;
    private Member $owner;
    private Member $user;

    protected function setUp(): void {
        $this->conversations = new InMemoryConversationRepository();
        $this->projects = new InMemoryProjectRepository();
        $this->projects->useConversations($this->conversations);
        $this->places = new ConversationPlaces($this->conversations, $this->projects);
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $this->user = new Member(2, MemberKind::Human, Role::user(), 'User');
    }

    private function contextOf(Member $who, string $chat): ?string {
        return $this->conversations->meta($this->conversations->openConversation($who->id, 'telegram', $chat))?->context;
    }

    public function testAnOwnerGoesToTheSharedContextAndBack(): void {
        [$done, $answer] = $this->places->switchTo($this->owner, 'telegram', '1', 'global');
        self::assertTrue($done, $answer);
        self::assertSame('owners', $this->contextOf($this->owner, '1'));
        self::assertStringContainsString('🌐 global', $this->places->current($this->metaOf($this->owner, '1'), $this->owner)[0]->mark());

        [$done] = $this->places->switchTo($this->owner, 'telegram', '1', 'personal');
        self::assertTrue($done);
        self::assertNull($this->contextOf($this->owner, '1'));
        self::assertSame('', $this->places->current($this->metaOf($this->owner, '1'), $this->owner)[0]->mark());
    }

    public function testOnlyAnOwnerGoesToTheSharedContext(): void {
        [$done] = $this->places->switchTo($this->user, 'telegram', '2', 'global');
        self::assertFalse($done);
        self::assertNull($this->contextOf($this->user, '2'));
    }

    public function testAMemberGoesToAProjectTheyHaveAccessTo(): void {
        $theirs = $this->projects->create('Site', 'goal', 2);
        $shared = $this->projects->create('Shop', 'goal', 1);
        $closed = $this->projects->create('Secret', 'goal', 1);
        $this->projects->share($shared->id, 2, 'read', 1);

        self::assertTrue($this->places->switchTo($this->user, 'telegram', '2', 'site')[0]);
        self::assertSame('project:' . $theirs->id, $this->contextOf($this->user, '2'));
        self::assertTrue($this->places->switchTo($this->user, 'telegram', '2', (string) $shared->id)[0]);
        self::assertSame('📁 Shop', $this->places->current($this->metaOf($this->user, '2'), $this->user)[0]->mark());
        self::assertFalse($this->places->switchTo($this->user, 'telegram', '2', 'Secret')[0]);
        self::assertSame('project:' . $shared->id, $this->contextOf($this->user, '2'));
        self::assertSame(['Shop', 'Site'],array_map(static fn($p): string => $p->name, $this->places->choices($this->user)));
        self::assertNotContains($closed->name, array_map(static fn($p): string => $p->name, $this->places->choices($this->user)));
    }

    public function testAGroupHasNoContextOfItsConversation(): void {
        [$done] = $this->places->switchTo($this->owner, 'telegram', '-100', 'global');
        self::assertFalse($done);
    }

    public function testALostProjectFallsBackToTheMembersOwnContext(): void {
        $shared = $this->projects->create('Shop', 'goal', 1);
        $this->projects->share($shared->id, 2, 'write', 1);
        $this->places->switchTo($this->user, 'telegram', '2', 'Shop');
        $this->projects->unshare($shared->id, 2);

        [$place, $notice] = $this->places->current($this->metaOf($this->user, '2'), $this->user);
        self::assertSame('personal', $place->kind);
        self::assertNotNull($notice);
        self::assertNull($this->contextOf($this->user, '2'), 'the lost context is taken off');
    }

    public function testALostOwnerRoleFallsBackToTheMembersOwnContext(): void {
        $this->places->switchTo($this->owner, 'telegram', '1', 'global');
        $former = new Member(1, MemberKind::Human, Role::user(), 'Owner');

        [$place, $notice] = $this->places->current($this->metaOf($former, '1'), $former);
        self::assertSame('personal', $place->kind);
        self::assertNotNull($notice);
    }

    public function testASwitchReplacesTheBindingOfThePrivateChat(): void {
        $bound = $this->projects->create('Bound', 'goal', 1);
        $this->projects->activate('telegram', '1', $bound->id);

        $this->places->switchTo($this->owner, 'telegram', '1', 'personal');
        self::assertNull($this->projects->active('telegram', '1'));
    }

    private function metaOf(Member $who, string $chat): \OpenCCK\Domain\Agent\ConversationMeta {
        $meta = $this->conversations->meta($this->conversations->openConversation($who->id, 'telegram', $chat));
        self::assertNotNull($meta);
        return $meta;
    }
}
