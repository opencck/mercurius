<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Mercurius\Messenger\Button;
use OpenCCK\App\Agent\GrantList;
use OpenCCK\Test\Support\InMemoryApprovalRepository;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use PHPUnit\Framework\TestCase;

/**
 * The owner's list of saved grants: a summary, pages, selections, revoking and tidying up.
 *
 * spec: brief/agent-loop.md#список-разрешений
 */
final class GrantListTest extends TestCase {
    private InMemoryApprovalRepository $approvals;
    private GrantList $list;

    protected function setUp(): void {
        $this->approvals = new InMemoryApprovalRepository();
        $principals = new InMemoryMemberRepository();
        $principals->ensureOwner('telegram', '42', 'Owner');
        $this->list = new GrantList($this->approvals, $principals);
    }

    /** Twelve grants of several groups. */
    private function many(): void {
        foreach (['aaa', 'bbb', 'ccc', 'ddd'] as $hash) {
            $this->approvals->addGrant(1, 'ssh:v2:base:' . $hash, null, false, 'ssh base: docker restart ' . $hash);
        }
        $this->approvals->addGrant(1, 'ssh:v2:edge:eee');
        $this->approvals->addGrant(1, 'server-add:edge');
        $this->approvals->addGrant(1, 'http-secret:gh-token api.example.com');
        $this->approvals->addGrant(1, 'http:POST a.example.com');
        $this->approvals->addGrant(1, 'model-role:cheap:a/b');
        $this->approvals->addGrant(1, 'settings:restart');
        $this->approvals->addGrant(1, 'area:job:5', null, true);
        $this->approvals->addGrant(1, 'job:5|http:POST a.example.com', null, true);
    }

    /**
     * @param list<list<Button>> $rows
     * @return list<string>
     */
    private static function data(array $rows): array {
        return array_map(static fn(Button $b): string => $b->data, array_merge(...$rows));
    }

    public function testAShortListIsShownAsItIs(): void {
        $this->approvals->addGrant(1, 'git-push:origin');

        [$text, $rows] = $this->list->view('');

        self::assertStringContainsString('git-push:origin', $text);
        self::assertSame(['pg:r:1::0'], self::data($rows));
    }

    public function testALongListIsASummaryByGroups(): void {
        $this->many();

        [$text, $rows] = $this->list->view('');

        self::assertStringContainsString('Сохранённые разрешения: 12', $text);
        self::assertStringContainsString('Серверы — 6 (серверов: 2)', $text);
        self::assertStringContainsString('Задания и проекты — 2', $text);
        self::assertStringNotContainsString('ssh:v2', $text, 'no keys in the summary');
        self::assertContains('pg:l:g.servers:0', self::data($rows));
        self::assertNotContains('pg:l:g.messages:0', self::data($rows), 'an empty group has no button');
    }

    public function testAGroupIsShownByPagesWithWordsInsteadOfKeys(): void {
        $this->many();
        for ($i = 0; $i < 6; $i++) {
            $this->approvals->addGrant(1, 'ssh:v2:base:x' . $i);
        }

        [$first, $rows] = $this->list->view('g.servers');
        [$second] = $this->list->view('g.servers', 1);

        self::assertStringContainsString('Серверы: 12 · стр. 1 из 2', $first);
        self::assertStringContainsString('ssh base: docker restart aaa', $first, 'the words kept with the grant');
        self::assertStringContainsString('команда на сервере edge (текст не сохранён)', $first, 'an old grant is said by its key');
        self::assertSame(GrantList::PAGE, substr_count($first, "\n") - 3, 'no more than a page of lines');
        self::assertContains('pg:l:g.servers:1', self::data($rows));
        self::assertStringContainsString('стр. 2 из 2', $second);
    }

    public function testASelectionByServerJobAndCommandWords(): void {
        $this->many();

        self::assertSame('s.base', GrantList::selectorOf('server Base'));
        self::assertSame('j.5', GrantList::selectorOf('job #5'));
        self::assertSame('g.servers', GrantList::selectorOf('серверы'));
        self::assertSame('u', GrantList::selectorOf('unused'));
        self::assertNull(GrantList::selectorOf('что-то не то'));
        [$base] = $this->list->view('s.base');
        self::assertStringContainsString('Сервер base: 4', $base);
        [$job] = $this->list->view('j.5');
        self::assertStringContainsString('Задание #5: 2', $job);
        self::assertStringContainsString('и после внешних данных', $job);
    }

    public function testOneGrantIsRevokedAndTheSameViewStays(): void {
        $this->many();

        $pressed = $this->list->press('pg:r:1:s.base:0');

        self::assertNotNull($pressed);
        self::assertSame('Отозвано.', $pressed[0]);
        self::assertStringContainsString('Сервер base: 3', $pressed[1][0]);
        self::assertFalse($this->approvals->hasGrant(1, 'ssh:v2:base:aaa'));
    }

    public function testAllOfASelectionIsRevokedOnlyAfterTheSecondPress(): void {
        $this->many();

        $asked = $this->list->press('pg:a:s.base');
        self::assertNotNull($asked);
        self::assertStringContainsString('4 шт.', $asked[1][0]);
        self::assertCount(12, $this->approvals->grants(), 'asking revokes nothing');
        self::assertSame(['pg:A:s.base', 'pg:l:s.base:0'], self::data($asked[1][1]));

        $done = $this->list->press('pg:A:s.base');
        self::assertNotNull($done);
        self::assertSame('Отозвано: 4.', $done[0]);
        self::assertCount(8, $this->approvals->grants(), 'only that selection is gone');
    }

    public function testGrantsNeverUsedAreMarkedAndListed(): void {
        $this->many();
        $this->approvals->ageGrant(8, 45);
        $this->approvals->ageGrant(9, 45);
        $this->approvals->useGrant(9);

        [$summary] = $this->list->view('');
        [$unused] = $this->list->view('u');

        self::assertStringContainsString('Ни разу не сработали: 1', $summary);
        self::assertStringContainsString('POST на a.example.com', $unused);
        self::assertStringContainsString('не сработало ни разу за 45 дн.', $unused);
        self::assertStringNotContainsString('модель', $unused, 'a grant that ran is not unused');
    }

    public function testManyExactGrantsOfAServerAreReplacedByOneWideOnlyWhenTheOwnerAgrees(): void {
        $this->many();

        [, $rows] = $this->list->view('s.base');
        self::assertContains('pg:w:base', self::data($rows), 'the offer stands under the server');
        [, $edge] = $this->list->view('s.edge');
        self::assertNotContains('pg:w:edge', self::data($edge), 'one exact grant is not worth replacing');

        $asked = $this->list->press('pg:w:base');
        self::assertNotNull($asked);
        self::assertStringContainsString('шире', $asked[1][0]);
        self::assertFalse($this->approvals->hasGrant(1, 'ssh-any:base'), 'nothing is widened by the offer');

        $done = $this->list->press('pg:W:base');
        self::assertNotNull($done);
        self::assertTrue($this->approvals->hasGrant(1, 'ssh-any:base'));
        self::assertFalse($this->approvals->hasGrant(1, 'ssh:v2:base:aaa'));
        self::assertStringContainsString('любые изменяющие команды на сервере base', $done[1][0]);
    }
}
