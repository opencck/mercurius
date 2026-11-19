<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Tool\Files\SearchFilesTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;

/**
 * The agent searches the files of the participant by content or by name, as a read: one quoted command in the
 * sandbox, the folder of snapshots left out, the number of lines bounded.
 *
 * spec: BRIEF.md#решения-по-доработкам-2026-11-18
 */
final class SearchFilesToolTest extends AsyncTestCase {
    private static function context(): ToolContext {
        return new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::Owner, null), new NullCancellation(), new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4), true));
    }

    public function testContentAndNamesAreSearchedAsAReadWithQuotedArguments(): void {
        $sandbox = new FakeSandbox();
        $sandbox->outcome = new CommandOutcome(0, "notes.md:3:кофе без сахара\n", '', false, false, 1);
        $tool = new SearchFilesTool($sandbox);

        self::assertSame(DangerClass::Read, $tool->danger(['pattern' => 'x']));
        $found = $tool->execute(['pattern' => "кофе'; rm -rf ~", 'path' => 'docs', 'file_glob' => '*.md', 'limit' => 20], self::context());
        self::assertStringContainsString('notes.md:3:кофе без сахара', $found->content);
        $command = $sandbox->calls[0] ?? '';
        self::assertStringContainsString("grep -rInE --exclude-dir=.checkpoints --include='*.md' -e 'кофе'\\''; rm -rf ~' -- 'docs'", $command, 'every argument is quoted');
        self::assertStringContainsString('head -n 20', $command);

        $tool->execute(['pattern' => '*.php', 'target' => 'files'], self::context());
        self::assertStringContainsString("find -- '.' -name '*.php' -not -path '*/.checkpoints/*'", $sandbox->calls[1] ?? '');
        self::assertStringContainsString('head -n 50', $sandbox->calls[1] ?? '', 'fifty by default');
    }

    public function testNothingFoundIsSaidPlainly(): void {
        $sandbox = new FakeSandbox();
        $sandbox->outcome = new CommandOutcome(1, '', '', false, false, 1);

        $result = (new SearchFilesTool($sandbox))->execute(['pattern' => 'нет такого'], self::context());

        self::assertFalse($result->isError, 'grep says 1 when nothing matched: that is an answer, not a failure');
        self::assertStringContainsString('Ничего не найдено', $result->content);
    }
}
