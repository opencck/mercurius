<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\NullCancellation;
use OpenCCK\App\Agent\ContextMemory;
use OpenCCK\App\Agent\MemoryPlace;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

/**
 * The MEMORY.md of a context lies at the root of its folder: the person's own in a private chat, the project's in the
 * folder of the project's owner, a place's in the folder of the agent's owner; no file is an empty one.
 *
 * spec: brief/memory.md#файл-памяти-места
 */
final class ContextMemoryTest extends TestCase {
    private FakeSandbox $sandbox;
    private InMemoryProjectRepository $projects;
    private ContextMemory $memory;
    private Member $owner;
    private Member $member;
    private Member $stranger;

    protected function setUp(): void {
        $this->sandbox = new FakeSandbox();
        $this->projects = new InMemoryProjectRepository();
        $this->memory = new ContextMemory($this->sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), $this->projects, static fn(): int => 1);
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'owner');
        $this->member = new Member(7, MemberKind::Human, Role::admin(), 'member');
        $this->stranger = new Member(9, MemberKind::Human, Role::user(), 'stranger');
    }

    public function testEachContextHasItsFile(): void {
        $project = $this->projects->create('Job Hunt', '', 7);
        $this->projects->chats['telegram:-100:5'] = $project->id;

        $personal = $this->memory->personal($this->member);
        $inProject = $this->memory->shared($this->member, 'telegram', '-100', '5', false);
        $inGroup = $this->memory->shared($this->member, 'telegram', '-200', '', false);

        self::assertSame([7, 'MEMORY.md', MemoryPlace::PERSON], [$personal?->holder, $personal?->path, $personal?->kind]);
        self::assertSame([7, 'projects/' . $project->id . '/MEMORY.md', MemoryPlace::PROJECT], [$inProject?->holder, $inProject?->path, $inProject?->kind], 'in the folder of the project\'s owner');
        self::assertSame([1, 'places/telegram_-200/MEMORY.md', MemoryPlace::PLACE], [$inGroup?->holder, $inGroup?->path, $inGroup?->kind], 'a place without a project: in the folder of the agent\'s owner');
        self::assertNull($this->memory->shared($this->member, 'telegram', '42', '', true), 'a private chat without a project has only the person\'s own');
        self::assertSame('places/telegram_-200_17/MEMORY.md', $this->memory->shared($this->member, 'telegram', '-200', '17', false)?->path, 'a forum topic is a place of its own');
    }

    public function testAProjectFileIsForThoseWithAccessToTheProject(): void {
        $project = $this->projects->create('Job Hunt', '', 7);
        $this->projects->chats['a2a:a2a-agent-1:'] = $project->id;
        $this->projects->share($project->id, 12, 'read', 7);
        $reader = new Member(12, MemberKind::Agent, Role::admin(), 'agent');

        self::assertNotNull($this->memory->shared($reader, 'a2a', 'a2a-agent-1', '', false), 'an agent of the network is a participant like a person');
        self::assertNotNull($this->memory->shared($this->owner, 'a2a', 'a2a-agent-1', '', false), 'the owner of the agent owns every project');
        self::assertNull($this->memory->shared($this->stranger, 'a2a', 'a2a-agent-1', '', false));
    }

    public function testNoFileReadsAsEmptyAndWritesGoToTheHolder(): void {
        $place = $this->memory->shared($this->member, 'telegram', '-200', '', false);
        self::assertNotNull($place);
        self::assertSame('', $this->memory->read($place));

        $this->memory->write($place, "## Правила\nотвечать коротко", new NullCancellation());

        self::assertSame("## Правила\nотвечать коротко", $this->sandbox->files[1]['places/telegram_-200/MEMORY.md'] ?? null);
        self::assertSame("## Правила\nотвечать коротко", $this->memory->read($place));
    }
}
