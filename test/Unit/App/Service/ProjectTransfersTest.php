<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DomainException;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\ProjectTransfers;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;

/**
 * spec: brief/participants.md#доступ-к-проектам-и-передача
 */
final class ProjectTransfersTest extends AsyncTestCase {
    private InMemoryMemberRepository $principals;
    private InMemoryProjectRepository $projects;
    private InMemoryConversationRepository $conversations;
    private Member $owner;
    private Member $agent;
    private Member $user;
    /** @var list<array{int, int, int}> */
    private array $moved = [];
    /** @var list<array{int, string, int}> who was told, what, how many buttons */
    private array $told = [];
    private ProjectTransfers $transfers;
    private ProjectEditTool $edit;

    protected function setUp(): void {
        parent::setUp();
        $this->principals = new InMemoryMemberRepository();
        $this->owner = $this->principals->ensureOwner('telegram', '42', 'Owner');
        $this->agent = $this->principals->createAgent('helper', Role::user());
        $visitor = $this->principals->createVisitor('telegram', '7', 'Анна');
        $this->user = $this->principals->decideVisitor($visitor->id, Role::user(), MemberStatus::Active) ?? self::fail('no user');
        $this->projects = new InMemoryProjectRepository();
        $this->conversations = new InMemoryConversationRepository();
        $this->transfers = new ProjectTransfers(
            $this->projects,
            $this->principals,
            function (int $from, int $to, int $project): void {
                $this->moved[] = [$from, $to, $project];
            },
            function (Member $to, string $text, array $buttons): void {
                $this->told[] = [$to->id, $text, count($buttons)];
            },
        );
        $this->edit = new ProjectEditTool($this->projects, $this->conversations, principals: $this->principals, transfers: $this->transfers);
    }

    private function as(Member $who): ToolContext {
        return new ToolContext($who->id, $this->conversations->openConversation($who->id, 'test', 'chat-' . $who->id), $who, new NullCancellation());
    }

    private function project(string $name, ?Member $of): Project {
        return $this->projects->create($name, 'цель', $of?->id);
    }

    public function testAProjectBecomesTheTakersOnlyAfterTheirYes(): void {
        $project = $this->project('Поиск', $this->agent);

        $offered = $this->edit->execute(['action' => 'transfer', 'project' => 'Поиск', 'participant' => 'Анна'], $this->as($this->agent));
        self::assertFalse($offered->isError, $offered->content);
        self::assertSame($this->agent->id, $this->projects->find($project->id)?->ownerId, 'an offer changes nothing');
        self::assertSame([$this->user->id, 1], [$this->told[0][0], $this->told[0][2]], 'the taker is told, with buttons');

        $taken = $this->edit->execute(['action' => 'accept', 'project' => 'Поиск'], $this->as($this->user));
        self::assertFalse($taken->isError, $taken->content);

        self::assertSame($this->user->id, $this->projects->find($project->id)?->ownerId);
        self::assertSame([[$this->agent->id, $this->user->id, $project->id]], $this->moved, 'the folder follows the project');
        self::assertSame('full', $this->projects->memberLevel($project->id, $this->agent->id), 'the former owner goes on working with it');
        self::assertSame($this->agent->id, $this->told[1][0], 'the former owner learns the answer');
    }

    public function testOnlyTheOneItIsOfferedToDecides(): void {
        $project = $this->project('Поиск', $this->agent);
        $offer = $this->transfers->offer($project, $this->user);

        try {
            $this->transfers->decide($offer->id, $this->agent, true);
            self::fail('the giver cannot accept for the taker');
        } catch (DomainException) {
        }
        self::assertSame($this->agent->id, $this->projects->find($project->id)?->ownerId);
        self::assertTrue($this->edit->execute(['action' => 'accept', 'project' => 'Поиск'], $this->as($this->agent))->isError);
    }

    public function testARefusalLeavesTheProjectWhereItWas(): void {
        $project = $this->project('Поиск', $this->agent);
        $this->transfers->offer($project, $this->user);

        self::assertFalse($this->edit->execute(['action' => 'decline', 'project' => 'Поиск'], $this->as($this->user))->isError);

        self::assertSame($this->agent->id, $this->projects->find($project->id)?->ownerId);
        self::assertSame([], $this->moved);
        self::assertTrue($this->edit->execute(['action' => 'accept', 'project' => 'Поиск'], $this->as($this->user))->isError, 'nothing waits any more');
    }

    public function testAProjectGivenToTheOwnerOfTheAgentTakesThePlaceOfTheirEmptyOne(): void {
        $empty = $this->project('Поиск', null);
        $this->projects->update($empty->id, '', null, null);
        $this->projects->activate('telegram', '-100', $empty->id, '4');
        $project = $this->project('Поиск', $this->agent);
        $offer = $this->transfers->offer($project, $this->owner);

        $this->transfers->decide($offer->id, $this->owner, true);

        self::assertNull($this->projects->find($project->id)?->ownerId, 'the owner of the agent owns it now');
        self::assertNull($this->projects->find($empty->id), 'the empty project of that name is gone');
        self::assertSame($project->id, $this->projects->active('telegram', '-100', '4')?->id, 'its topic is the topic of the project that came');
    }

    public function testAProjectOfTheSameNameWithContentStopsTheHandover(): void {
        $this->project('Поиск', $this->user);
        $project = $this->project('Поиск', $this->agent);
        $offer = $this->transfers->offer($project, $this->user);

        try {
            $this->transfers->decide($offer->id, $this->user, true);
            self::fail('two projects of one name with content');
        } catch (DomainException) {
        }
        self::assertSame($this->agent->id, $this->projects->find($project->id)?->ownerId);
        self::assertCount(1, $this->transfers->waitingFor($this->user), 'the offer waits: rename one and say yes again');
    }

    public function testAFolderThatDidNotMoveLeavesTheProjectWithItsOwner(): void {
        $project = $this->project('Поиск', $this->agent);
        $failing = new ProjectTransfers($this->projects, $this->principals, static function (): void {
            throw new \RuntimeException('disk is full');
        });
        $offer = $failing->offer($project, $this->user);

        try {
            $failing->decide($offer->id, $this->user, true);
            self::fail('the folder did not move');
        } catch (DomainException $e) {
            self::assertStringContainsString('disk is full', $e->getMessage());
        }
        self::assertSame($this->agent->id, $this->projects->find($project->id)?->ownerId);
    }

    public function testOnlyTheOwnerOfAProjectHandsItOver(): void {
        $this->project('Поиск', $this->agent);
        $this->projects->share(1, $this->user->id, 'full', $this->agent->id);

        self::assertTrue($this->edit->execute(['action' => 'transfer', 'project' => 'Поиск', 'participant' => 'owner'], $this->as($this->user))->isError, 'the full access shares, it does not give the project away');
        self::assertFalse($this->edit->execute(['action' => 'transfer', 'project' => 'Поиск', 'participant' => 'Анна'], $this->as($this->owner))->isError, 'the owner of the agent hands any project over');
    }
}
