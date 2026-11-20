<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\ProjectContext;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use Mercurius\Memory\App\Audience;
use Mercurius\Memory\App\MemoryContext;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;

/**
 * spec: brief/participants.md#несколько-пользователей
 */
final class MultiUserTest extends AsyncTestCase {
    public function testAUserWorksWithTheirOwnProjectsOnly(): void {
        $principals = new \OpenCCK\Test\Support\InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $visitor = $principals->createVisitor('telegram', '7', 'Сергей');
        $user = $principals->decideVisitor($visitor->id, Role::user(), PrincipalStatus::Active);
        self::assertNotNull($user);
        $projects = new InMemoryProjectRepository();
        $conversations = new InMemoryConversationRepository();
        $ownerChat = $conversations->openConversation($owner->id, 'telegram', '42');
        $userChat = $conversations->openConversation($user->id, 'telegram', '7');
        $edit = new ProjectEditTool($projects, $conversations);
        $view = new ProjectTool($projects, $conversations);
        $asOwner = new ToolContext(1, $ownerChat, $owner, new NullCancellation());
        $asUser = new ToolContext(2, $userChat, $user, new NullCancellation());

        self::assertFalse($edit->execute(['action' => 'create', 'project' => 'Дом', 'text' => 'ремонт владельца'], $asOwner)->isError);
        self::assertFalse($edit->execute(['action' => 'create', 'project' => 'Дом', 'text' => 'дача Сергея'], $asUser)->isError, 'the same name for another owner');

        self::assertStringContainsString('дача Сергея', $view->execute(['name' => 'Дом'], $asUser)->content);
        self::assertStringNotContainsString('ремонт владельца', $view->execute([], $asUser)->content . $view->execute(['name' => 'Дом'], $asUser)->content);
        self::assertStringContainsString('ремонт владельца', $view->execute(['name' => 'Дом'], $asOwner)->content);

        // A chat bound to the owner's project gives the user nothing of it.
        $projects->activate('telegram', '7', 1);
        self::assertSame('', (new ProjectContext($projects))->block($user, 'telegram', '7'));
        self::assertTrue($edit->execute(['action' => 'card', 'text' => 'чужое'], $asUser)->isError);
    }

    public function testLayerOneIsKeptPerParticipant(): void {
        self::assertSame('profile', MemoryContext::section('profile', Audience::OWNER));
        self::assertSame('profile@principal:7', MemoryContext::section('profile', Audience::principal(7)));
    }
}
