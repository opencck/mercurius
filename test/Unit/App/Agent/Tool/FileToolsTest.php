<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Tool\Files\ListFilesTool;
use OpenCCK\App\Agent\Tool\Files\ReadFileTool;
use OpenCCK\App\Agent\Tool\Files\WriteFileTool;
use OpenCCK\App\Agent\Tool\Sandbox\RunCommandTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\Unspillable;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;
use PHPUnit\Framework\TestCase;

final class FileToolsTest extends TestCase {
    private FakeSandbox $sandbox;

    protected function setUp(): void {
        $this->sandbox = new FakeSandbox();
    }

    private static function context(bool $withSandbox = true): ToolContext {
        $sandbox = $withSandbox ? new SandboxLimits(100, 10, 5, 1000, 1) : null;
        return new ToolContext(
            3,
            1,
            new Member(7, MemberKind::Human, Role::admin(), null),
            new NullCancellation(),
            new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), $sandbox, true),
            'c1',
        );
    }

    public function testReadShowsNumberedLinesAndRange(): void {
        $this->sandbox->files[7]['a.txt'] = "one\ntwo\nthree\n";
        $tool = new ReadFileTool($this->sandbox);

        $result = $tool->execute(['path' => 'a.txt', 'start_line' => 2, 'max_lines' => 1], self::context());

        self::assertSame("2| two\nПоказаны строки 2–2 из 3.", $result->content);
        self::assertInstanceOf(Unspillable::class, $tool);
        self::assertSame(DangerClass::Read, $tool->danger([]));
    }

    public function testReadWholeFileHasNoRangeNote(): void {
        $this->sandbox->files[7]['a.txt'] = "one\ntwo\n";

        self::assertSame("1| one\n2| two", (new ReadFileTool($this->sandbox))->execute(['path' => 'a.txt'], self::context())->content);
    }

    public function testListFormatsEntries(): void {
        $this->sandbox->files[7]['b.txt'] = 'xyz';

        self::assertSame('file 3 b.txt', (new ListFilesTool($this->sandbox))->execute([], self::context())->content);
        self::assertSame(['list 7 . 1'], $this->sandbox->calls);
    }

    public function testASkillFileIsNotOverwrittenWithWhatIsNotASkill(): void {
        // spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — a SKILL.md written with its line breaks
        //       as backslash-n stopped being a skill and failed the runs of its project
        $write = new WriteFileTool($this->sandbox);
        $path = 'projects/8/.mercurius/skills/news-digest/SKILL.md';

        $spoiled = $write->execute(['path' => $path, 'content' => '---\nname: news-digest\ndescription: Дайджест\n---\n\n# Текст'], self::context());
        self::assertTrue($spoiled->isError);
        self::assertStringContainsString('skill_save', $spoiled->content);
        self::assertArrayNotHasKey($path, $this->sandbox->files[7] ?? []);

        $good = $write->execute(['path' => $path, 'content' => "---
name: news-digest
description: Дайджест
---

# Текст"], self::context());
        self::assertFalse($good->isError, $good->content);
        self::assertTrue($write->execute(['path' => $path, 'content' => "---
name: other
description: Дайджест
---

# Текст"], self::context())->isError, 'the name must agree with the folder');
    }

    public function testWriteNeedsContentOrPair(): void {
        $result = (new WriteFileTool($this->sandbox))->execute(['path' => 'x'], self::context());

        self::assertTrue($result->isError);
        self::assertSame('Укажи content или пару old/new.', $result->content);
    }

    public function testWriteAndReplaceCallSandbox(): void {
        $tool = new WriteFileTool($this->sandbox);
        $tool->execute(['path' => 'x', 'content' => 'a b'], self::context());
        $result = $tool->execute(['path' => 'x', 'old' => 'b', 'new' => 'c'], self::context());

        self::assertSame('a c', $this->sandbox->files[7]['x']);
        self::assertSame(['write 7 x', 'replace 7 x'], $this->sandbox->calls);
        self::assertSame(DangerClass::Write, $tool->danger([]));
        self::assertSame('Файл x изменён.', $result->content);
    }

    public function testRunCommandFormatsOutcome(): void {
        $this->sandbox->outcome = new CommandOutcome(2, "out\n", "err\n", false, false, 15);

        $result = (new RunCommandTool($this->sandbox, 120))->execute(['command' => 'make', 'cwd' => 'src', 'timeout' => 30], self::context());

        self::assertSame("Код выхода: 2\n\nstdout:\nout\n\nstderr:\nerr", $result->content);
        self::assertSame(['exec 7 make @src t30'], $this->sandbox->calls);
    }

    public function testTimeoutIsMarked(): void {
        $this->sandbox->outcome = new CommandOutcome(137, '', '', true, false, 5000);

        self::assertStringContainsString('Команда не уложилась в отведённое время и остановлена.', (new RunCommandTool($this->sandbox, 120))->execute(['command' => 'sleep 9'], self::context())->content);
    }

    public function testOverQuotaIsMarked(): void {
        $this->sandbox->outcome = new CommandOutcome(0, '', '', false, true, 5);

        self::assertStringContainsString('Рабочий каталог превысил лимит — освободи место.', (new RunCommandTool($this->sandbox, 120))->execute(['command' => 'x'], self::context())->content);
    }

    public function testNoSandboxPolicyIsAnError(): void {
        $result = (new ReadFileTool($this->sandbox))->execute(['path' => 'a'], self::context(false));

        self::assertTrue($result->isError);
        self::assertSame('Песочница недоступна для участника.', $result->content);
        self::assertSame([], $this->sandbox->calls);
    }

    public function testSandboxFailureIsResult(): void {
        $this->sandbox->failure = 'Путь /etc — вне рабочего каталога участника.';

        $result = (new ListFilesTool($this->sandbox))->execute(['path' => '/etc'], self::context());

        self::assertTrue($result->isError);
        self::assertSame('Путь /etc — вне рабочего каталога участника.', $result->content);
    }

    public function testRunCommandDangerFollowsTheClassifier(): void {
        $tool = new RunCommandTool($this->sandbox, 120);

        self::assertSame(DangerClass::Write, $tool->danger(['command' => 'ls']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['command' => 'git push origin master']));
        self::assertSame('git-push:github.com/opencck/x.git', $tool->grantKey(['command' => 'git push https://github.com/opencck/x.git master']));
        self::assertNull($tool->grantKey(['command' => 'ls']));
    }

    public function testDefaultTimeoutComesFromSettings(): void {
        (new RunCommandTool($this->sandbox, 90))->execute(['command' => 'ls'], self::context());

        self::assertSame(['exec 7 ls @. t90'], $this->sandbox->calls);
    }
}
