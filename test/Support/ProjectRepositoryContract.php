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
