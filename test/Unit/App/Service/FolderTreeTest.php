<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\NullCancellation;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Service\ConversationPlace;
use OpenCCK\App\Service\FolderTree;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use PHPUnit\Framework\TestCase;

use function Mercurius\I18n\t;

/**
 * spec: brief/participants.md#контекст-беседы-в-личном-чате — /ls: the tree of the folder of the context with sizes
 */
final class FolderTreeTest extends TestCase {
    private const DU = "8493465\t./docs/spec.pdf\n210000\t./docs/notes.md\n8703465\t./docs\n4096\t./main.py\n0\t./empty\n8707561\t.\n"
        . FolderTree::DIRS . "\n./docs\n./empty\n";

    public function testTheTreeShowsFoldersFirstWithTheirTotalSizes(): void {
        self::assertSame(
            "├─ docs/ — 8.3 МБ\n│  ├─ notes.md — 205.1 КБ\n│  └─ spec.pdf — 8.1 МБ\n├─ empty/ — 0 Б\n└─ main.py — 4 КБ",
            FolderTree::format(self::DU, 2)[0],
        );
        self::assertSame(8707561, FolderTree::format(self::DU, 2)[1]);
    }

    public function testALongTreeIsCut(): void {
        $du = '';
        for ($i = 0; $i < 300; $i++) {
            $du .= sprintf("1\t./f%03d\n", $i);
        }
        [$tree] = FolderTree::format($du . "300\t.\n" . FolderTree::DIRS . "\n", 1);
        self::assertCount(FolderTree::LIMIT + 1, explode("\n", $tree));
        self::assertStringContainsString(sprintf(t('ls.cut'), FolderTree::LIMIT, 300), $tree);
    }

    public function testTheFolderOfAProjectIsInTheHomeOfItsOwnerAndTheHeaderNamesTheContext(): void {
        $sandbox = new FakeSandbox();
        $sandbox->outcome = new CommandOutcome(0, self::DU, '', false, false, 1);
        $projects = new InMemoryProjectRepository();
        $shop = $projects->create('Shop', 'goal', 5);
        $projects->share($shop->id, 2, 'write', 5);
        $member = new Member(2, MemberKind::Human, Role::user(), 'User');
        $tree = new FolderTree($sandbox, $projects, static fn(): int => 1);

        $text = $tree->show($member, ConversationPlace::project($shop), new SandboxLimits(100, 10, 5, 1000, 1), 2, new NullCancellation());

        self::assertStringContainsString(sprintf(t('ls.context'), ConversationPlace::project($shop)->title()), $text);
        self::assertStringContainsString(sprintf(t('ls.folder'), '/workspace/p5/projects/' . $shop->id, '8.3 МБ'), $text);
        self::assertStringContainsString(sprintf(t('ls.level'), 'write'), $text);
        self::assertStringContainsString('└─ main.py — 4 КБ', $text);
        self::assertStringStartsWith('exec ' . \OpenCCK\Sandbox\Workspace::projectHolder($shop->id, 5) . ' ', $sandbox->calls[0], 'by the project\'s own user');
        self::assertStringContainsString('--max-depth=2', $sandbox->calls[0]);
    }

    public function testTheSharedContextListsTheRootAndAMissingFolderIsSaid(): void {
        $sandbox = new FakeSandbox();
        $sandbox->outcome = new CommandOutcome(0, FolderTree::MISSING . "\n", '', false, false, 1);
        $owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $tree = new FolderTree($sandbox, new InMemoryProjectRepository(), static fn(): int => 1);

        $text = $tree->show($owner, ConversationPlace::owners(), new SandboxLimits(100, 10, 5, 1000, 1), 3, new NullCancellation());

        self::assertStringStartsWith('exec 0 ', $sandbox->calls[0]);
        self::assertStringContainsString(sprintf(t('ls.missing'), '/workspace'), $text);
    }
}
