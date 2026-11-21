<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Repository\ProjectRepository;

/**
 * Behaviour every project store must have: the in-memory fake locally, PostgreSQL on the server.
 */
abstract class ProjectRepositoryContract extends AsyncTestCase {
    protected ProjectRepository $projects;
    protected int $principalId = 1;

    public function testProjectsAreFoundByNameWhateverTheCase(): void {
        $project = $this->projects->create('Меркурий', 'Агент на сервере');

        self::assertSame($project->id, $this->projects->byName('меркурий')?->id);
        self::assertSame('Агент на сервере', $this->projects->find($project->id)?->goal);
        self::assertSame(Project::ACTIVE, $project->status);
        $this->expectException(DomainException::class);
        $this->projects->create('МЕРКУРИЙ', '');
    }

    public function testUpdateChangesOnlyWhatIsGiven(): void {
        $project = $this->projects->create('Сайт', 'Магазин');

        $this->projects->update($project->id, null, Project::PAUSED, 'Bitrix, PHP 8.3');
        $updated = $this->projects->find($project->id);

        self::assertSame(['Магазин', Project::PAUSED, 'Bitrix, PHP 8.3'], [$updated?->goal, $updated?->status, $updated?->card]);
        self::assertNull($this->projects->update($project->id + 1000, 'x', null, null));
    }

    public function testActiveProjectsComeFirst(): void {
        $done = $this->projects->create('Архив', '');
        $this->projects->update($done->id, null, Project::DONE, null);
        $this->projects->create('Бета', '');
        $this->projects->create('альфа', '');

        self::assertSame(['альфа', 'Бета', 'Архив'], array_map(static fn(Project $p): string => $p->name, $this->projects->all(10)));
    }

    public function testEachOwnerHasTheirOwnProjectsAndTheAgentOwnerSeesAll(): void {
        $mine = $this->projects->create('Дом', 'мой');
        $theirs = $this->projects->create('дом', 'пользователя', $this->principalId);

        self::assertNull($mine->ownerId);
        self::assertSame($this->principalId, $theirs->ownerId);
        self::assertSame($mine->id, $this->projects->byName('ДОМ')?->id, 'the owner gets their own first');
        self::assertSame($theirs->id, $this->projects->byName('Дом', $this->principalId)?->id);
        self::assertSame([$theirs->id], array_map(static fn(Project $p): int => $p->id, $this->projects->all(10, $this->principalId)));
        self::assertCount(2, $this->projects->all(10));
        try {
            $this->projects->create('ДОМ', 'ещё раз', $this->principalId);
            self::fail('one name per owner');
        } catch (DomainException) {
        }
    }

    public function testAProjectIsSharedWithAParticipantAtALevel(): void {
        // spec: brief/participants.md#доступ-к-проектам-и-передача — the owner of a project gives another participant access to it
        $mine = $this->projects->create('Дом', 'мой');
        $this->projects->create('Дача', 'тоже мой');

        self::assertNull($this->projects->memberLevel($mine->id, $this->principalId));
        self::assertNull($this->projects->byName('Дом', $this->principalId), 'not shared yet');

        $this->projects->share($mine->id, $this->principalId, 'read', null);
        $this->projects->share($mine->id, $this->principalId, 'full', null);

        self::assertSame('full', $this->projects->memberLevel($mine->id, $this->principalId), 'a second share changes the level');
        self::assertSame([['principalId' => $this->principalId, 'access' => 'full']], $this->projects->members($mine->id));
        self::assertSame($mine->id, $this->projects->byName('дом', $this->principalId)?->id, 'a shared project is found by its name');
        self::assertSame([$mine->id], array_map(static fn(Project $p): int => $p->id, $this->projects->all(10, $this->principalId)));
        self::assertNull($this->projects->byName('Дача', $this->principalId), 'only what was shared');

        $own = $this->projects->create('Дом', 'свой', $this->principalId);
        self::assertSame($own->id, $this->projects->byName('Дом', $this->principalId)?->id, 'their own project of that name comes first');

        self::assertTrue($this->projects->unshare($mine->id, $this->principalId));
        self::assertFalse($this->projects->unshare($mine->id, $this->principalId));
        self::assertNull($this->projects->memberLevel($mine->id, $this->principalId));
    }

    public function testAProjectPassesToANewOwnerAndTakesThePlaceOfAnEmptyOne(): void {
        // spec: brief/participants.md#доступ-к-проектам-и-передача — a project is handed over with the consent of the one who takes it
        $empty = $this->projects->create('Поиск', '');
        $this->projects->activate('telegram', '-100', $empty->id, '4');
        $theirs = $this->projects->create('Поиск', 'работа', $this->principalId);

        self::assertTrue($this->projects->isEmpty($empty->id));
        self::assertFalse($this->projects->isEmpty($theirs->id));

        $first = $this->projects->offer($theirs->id, $this->principalId, null);
        $offer = $this->projects->offer($theirs->id, $this->principalId, null);
        self::assertSame('cancelled', $this->projects->transfer($first->id)?->status, 'a new offer replaces the one that waited');
        self::assertSame([$offer->id], array_map(static fn($t): int => $t->id, $this->projects->offersTo(null)));
        self::assertSame([], $this->projects->offersTo($this->principalId));

        try {
            $this->projects->setOwner($theirs->id, null);
            self::fail('the new owner has a project of that name');
        } catch (DomainException) {
        }
        $this->projects->absorb($theirs->id, $empty->id);
        self::assertNull($this->projects->find($empty->id));
        self::assertSame($theirs->id, $this->projects->active('telegram', '-100', '4')?->id, 'the topic of the empty project is now the topic of this one');

        self::assertNull($this->projects->setOwner($theirs->id, null)?->ownerId);
        self::assertTrue($this->projects->settle($offer->id, 'accepted'));
        self::assertFalse($this->projects->settle($offer->id, 'declined'), 'settled once');
        self::assertSame([], $this->projects->offersTo(null));
    }

    public function testRelationsAreKeptPerParticipant(): void {
        $project = $this->projects->create('Меркурий', '');

        self::assertNull($this->projects->relation($project->id, $this->principalId));
        $this->projects->setRelation($project->id, $this->principalId, 'Главный проект, качество важнее сроков');
        $this->projects->setRelation($project->id, $this->principalId, 'Главный проект');

        self::assertSame('Главный проект', $this->projects->relation($project->id, $this->principalId));
    }

    public function testEachForumTopicHasItsOwnProjectAndTheOwnerIsKept(): void {
        $chat = $this->projects->create('Чат', '');
        $topic = $this->projects->create('Тема', '', $this->principalId);

        $this->projects->activate('telegram', '-100', $chat->id);
        $this->projects->activate('telegram', '-100', $topic->id, '12');

        self::assertSame($chat->id, $this->projects->active('telegram', '-100')?->id);
        $active = $this->projects->active('telegram', '-100', '12');
        self::assertSame([$topic->id, $this->principalId], [$active?->id, $active?->ownerId], 'the owner comes with the active project');
        self::assertNull($this->projects->active('telegram', '-100', '13'));
        $this->projects->activate('telegram', '-100', null, '12');
        self::assertNull($this->projects->active('telegram', '-100', '12'));
        self::assertSame($chat->id, $this->projects->active('telegram', '-100')?->id);
    }

    public function testAChatWorksOnOneProject(): void {
        $a = $this->projects->create('А', '');
        $b = $this->projects->create('Б', '');

        $this->projects->activate('telegram', '42', $a->id);
        $this->projects->activate('telegram', '42', $b->id);

        self::assertSame($b->id, $this->projects->active('telegram', '42')?->id);
        self::assertNull($this->projects->active('telegram', '43'));
        $this->projects->activate('telegram', '42', null);
        self::assertNull($this->projects->active('telegram', '42'));
    }
}
