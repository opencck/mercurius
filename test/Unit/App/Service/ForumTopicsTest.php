<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\ForumTopics;
use OpenCCK\Domain\Agent\ConversationMeta;
use OpenCCK\Domain\Group\Group;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\InMemoryGroupRepository;
use OpenCCK\Test\Support\InMemoryProjectRepository;
use Psr\Log\NullLogger;

/**
 * In a forum every project has a topic: the agent opens, links, closes and reopens them.
 */
final class ForumTopicsTest extends AsyncTestCase {
    private const FORUM = '-100500';

    private FakeChannel $channel;
    private InMemoryGroupRepository $groups;
    private InMemoryProjectRepository $projects;
    private ForumTopics $topics;

    protected function setUp(): void {
        parent::setUp();
        $this->channel = new FakeChannel();
        $this->groups = new InMemoryGroupRepository();
        $this->projects = new InMemoryProjectRepository();
        $this->topics = new ForumTopics($this->channel, $this->groups, $this->projects, new NullLogger());
        $this->groups->create('telegram', self::FORUM, 'Дом');
        $this->groups->decide('telegram', self::FORUM, Group::APPROVED, Group::MENTION);
        $this->groups->setForum('telegram', self::FORUM, true);
    }

    private static function meta(string $thread = '', string $chat = self::FORUM): ConversationMeta {
        return new ConversationMeta(1, 1, 'telegram', $chat, null, null, $thread);
    }

    public function testAProjectStartedInGeneralGetsATopicOfItsOwn(): void {
        $project = $this->projects->create('Сайт', 'Магазин');

        $note = $this->topics->created($project, self::meta());

        self::assertSame([['create', self::FORUM, '500', 'Сайт']], $this->channel->topics);
        self::assertSame($project->id, $this->projects->active('telegram', self::FORUM, '500')?->id);
        self::assertNull($this->projects->active('telegram', self::FORUM), 'General stays without a project');
        self::assertStringContainsString('тему «Сайт»', $note);
        self::assertSame(['500'], array_values(array_filter($this->channel->threads)), 'a greeting goes into the new topic');
    }

    public function testAProjectStartedInATopicWithoutOneTakesThatTopic(): void {
        $project = $this->projects->create('Сайт', '');

        $this->topics->created($project, self::meta('77'));

        self::assertSame([['edit', self::FORUM, '77', 'Сайт']], $this->channel->topics);
        self::assertSame($project->id, $this->projects->active('telegram', self::FORUM, '77')?->id);
    }

    public function testOutsideAForumOrWithoutRightsNothingBreaks(): void {
        $project = $this->projects->create('Сайт', '');
        self::assertSame('', $this->topics->created($project, self::meta('', '42')), 'a private chat');

        $this->channel->topicsFail = true;
        $note = $this->topics->created($project, self::meta());
        self::assertStringContainsString('управлять темами', $note);
        self::assertSame([], $this->projects->topicsOf($project->id));
    }

    public function testARenamedProjectRenamesItsTopics(): void {
        // spec: BRIEF.md#форум-темы--проекты — a project renamed keeps its topics named after it
        $project = $this->projects->create('Сайт', '');
        $this->topics->created($project, self::meta());

        $renamed = $this->projects->rename($project->id, 'Магазин');
        self::assertSame('Магазин', $renamed?->name);
        $this->topics->renamed($project->id, 'Магазин');

        self::assertSame([['edit', self::FORUM, '500', 'Магазин']], array_slice($this->channel->topics, 1), 'after the topic was made');
        $other = $this->projects->create('Блог', '');
        $this->expectException(\DomainException::class);
        $this->projects->rename($other->id, 'магазин');
    }

    public function testAFinishedProjectClosesItsTopicAndAReturnReopensIt(): void {
        $project = $this->projects->create('Сайт', '');
        $this->topics->created($project, self::meta());

        foreach ([Project::DONE, Project::PAUSED, Project::DONE, Project::DONE, Project::ACTIVE] as $status) {
            $this->topics->statusChanged($project, $status);
            $project = $this->projects->update($project->id, null, $status, null) ?? $project;
        }

        self::assertSame(['create', 'close', 'reopen', 'close', 'reopen'], array_column($this->channel->topics, 0), 'a paused project keeps its topic open; a repeated status changes nothing');
    }

    public function testATopicSomeoneOpenedBecomesADraftProject(): void {
        $project = $this->topics->opened('telegram', self::FORUM, '88', 'Ремонт', 7);

        self::assertSame(['Ремонт', 7], [$project?->name, $project?->ownerId]);
        self::assertSame($project?->id, $this->projects->active('telegram', self::FORUM, '88')?->id);
        self::assertStringContainsString('проектом «Ремонт»', implode("
", $this->channel->texts('send')));
        self::assertNull($this->topics->opened('telegram', self::FORUM, '88', 'Ремонт', 7), 'a topic is linked once');
        self::assertNull($this->topics->opened('telegram', '-100600', '5', 'Чужой', 7), 'only an approved forum');
    }
}
