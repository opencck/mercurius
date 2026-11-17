<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\PHPUnit\AsyncTestCase;
use DomainException;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectTask;
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

    public function testTasksOpenCloseAndNest(): void {
        $project = $this->projects->create('Меркурий', '');
        $parent = $this->projects->addTask($project->id, 'Память', null, ['reasoning' => 6]);
        $child = $this->projects->addTask($project->id, 'HRR', $parent->id, []);
        $this->projects->addTask($project->id, 'Сеть', null, []);

        $this->projects->setTaskStatus($child->id, ProjectTask::DONE, 'Замер записан');
        $closed = $this->projects->task($child->id);

        self::assertSame([$parent->id, ProjectTask::DONE, 'Замер записан'], [$closed?->parentId, $closed?->status, $closed?->result]);
        self::assertSame(['Память', 'Сеть'], array_map(static fn(ProjectTask $t): string => $t->title, $this->projects->openTasks($project->id, 10)));
        self::assertSame(['reasoning' => 6], $this->projects->task($parent->id)?->profile);
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

    public function testDeadlinesOfOpenTasksInActiveProjectsComeEarliestFirst(): void {
        $project = $this->projects->create('Дом', '');
        $paused = $this->projects->create('Архив', '');
        $this->projects->update($paused->id, null, Project::PAUSED, null);
        $late = $this->projects->addTask($project->id, 'Налог', null, [], new \DateTimeImmutable('2026-11-20 10:00 UTC'));
        $soon = $this->projects->addTask($project->id, 'Счёт', null, [], new \DateTimeImmutable('2026-11-18 09:00 UTC'));
        $done = $this->projects->addTask($project->id, 'Готово', null, [], new \DateTimeImmutable('2026-11-17 09:00 UTC'));
        $this->projects->setTaskStatus($done->id, ProjectTask::DONE, 'сделано');
        $this->projects->addTask($paused->id, 'На паузе', null, [], new \DateTimeImmutable('2026-11-17 09:00 UTC'));
        $this->projects->addTask($project->id, 'Без срока', null, []);

        self::assertSame([$soon->id, $late->id], array_map(static fn(ProjectTask $t): int => $t->id, $this->projects->dueTasks(new \DateTimeImmutable('2026-11-30 UTC'), 10)));
        self::assertEquals(new \DateTimeImmutable('2026-11-18 09:00 UTC'), $this->projects->task($soon->id)?->dueAt);

        $this->projects->setTaskDue($soon->id, null);
        self::assertSame([$late->id], array_map(static fn(ProjectTask $t): int => $t->id, $this->projects->dueTasks(new \DateTimeImmutable('2026-11-30 UTC'), 10)));
        self::assertSame([], $this->projects->dueTasks(new \DateTimeImmutable('2026-11-19 UTC'), 10));
    }

    public function testRelationsAreKeptPerParticipant(): void {
        $project = $this->projects->create('Меркурий', '');

        self::assertNull($this->projects->relation($project->id, $this->principalId));
        $this->projects->setRelation($project->id, $this->principalId, 'Главный проект, качество важнее сроков');
        $this->projects->setRelation($project->id, $this->principalId, 'Главный проект');

        self::assertSame('Главный проект', $this->projects->relation($project->id, $this->principalId));
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
