<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use OpenCCK\App\Agent\ContextMemory;
use OpenCCK\App\Agent\ConversationAudience;
use OpenCCK\App\Agent\Tool\Memory\MemoryFileTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * memory_file edits the MEMORY.md of this place for everyone with access to it, or the person's own; no secrets.
 *
 * spec: brief/memory.md#файл-памяти-места
 */
final class MemoryFileToolTest extends TestCase {
    public function testAMemberOfAProjectEditsItsFileAndAStrangerCannot(): void {
        $sandbox = new FakeSandbox();
        $projects = new InMemoryProjectRepository();
        $conversations = new InMemoryConversationRepository();
        $project = $projects->create('Job Hunt', '', 1);
        $projects->chats['telegram:-100:'] = $project->id;
        $projects->share($project->id, 7, 'read', 1);
        $tool = new MemoryFileTool(
            new ContextMemory($sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), $projects, static fn(): int => 1),
            $conversations,
            new ConversationAudience($conversations),
        );
        $group = $conversations->openConversation(7, 'telegram', '-100');
        $member = new ToolContext(1, $group, new Member(7, MemberKind::Human, Role::admin(), 'member'), new NullCancellation());
        $stranger = new ToolContext(2, $conversations->openConversation(9, 'telegram', '-100'), new Member(9, MemberKind::Human, Role::admin(), 'stranger'), new NullCancellation());

        $saved = $tool->execute(['action' => 'write', 'content' => "## Правила\nВсё по-английски"], $member);

        self::assertFalse($saved->isError, $saved->content);
        self::assertSame("## Правила\nВсё по-английски", $sandbox->files[1]['projects/' . $project->id . '/MEMORY.md'] ?? null, 'in the folder of the project\'s owner');
        self::assertStringContainsString('Всё по-английски', $tool->execute(['action' => 'read'], $member)->content);
        self::assertTrue($tool->execute(['action' => 'read'], $stranger)->isError);
        self::assertTrue($tool->execute(['action' => 'write', 'content' => 'пароль: sk-or-v1-0123456789abcdef0123456789abcdef'], $member)->isError, 'no secrets');
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'read']));
    }
}
