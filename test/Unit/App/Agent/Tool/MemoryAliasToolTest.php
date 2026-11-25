<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Mercurius\Memory\App\Aliases;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryAliasStore;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use OpenCCK\App\Agent\Tool\Memory\MemoryAliasTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use PHPUnit\Framework\TestCase;

/**
 * The agent fills the owner's table of words itself, in a conversation of the owner only; reading it is harmless.
 *
 * spec: brief/memory.md#словари
 */
final class MemoryAliasToolTest extends TestCase {
    public function testTheAgentAddsAGroupForTheOwnerOnly(): void {
        $tool = new MemoryAliasTool(new Aliases(new InMemoryAliasStore(), new TextAnalyzer(), new InMemoryMemoryStore(), new ContentScreen()));
        $owner = new ToolContext(1, 1, new Member(1, MemberKind::Human, Role::owner(), 'owner'), new NullCancellation());
        $guest = new ToolContext(2, 2, new Member(5, MemberKind::Human, Role::admin(), 'guest'), new NullCancellation());

        self::assertFalse($tool->execute(['action' => 'add', 'words' => ['прокс', 'proxmox']], $owner)->isError);
        self::assertTrue($tool->execute(['action' => 'add', 'words' => ['кубер', 'kubernetes']], $guest)->isError);
        self::assertStringContainsString('#1: прокс, proxmox', $tool->execute(['action' => 'list'], $guest)->content);
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'list']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'add']));
    }
}
