<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Service\ServerCommands;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryCommandAuditRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\InMemoryServerRepository;
use OpenCCK\Test\Support\ScriptedLlm;

final class ServerCommandsTest extends AsyncTestCase {
    private Vault $vault;
    private AgentStack $stack;

    protected function setUp(): void {
        parent::setUp();
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->stack = new AgentStack(new ScriptedLlm([]));
        $this->stack->useServers(new ServerCommands($this->vault, new InMemoryServerRepository(), new InMemoryCommandAuditRepository()));
    }

    /** spec: brief/skills-mcp.md#подстановка-секретов — the layer of the agent, kept by its owners */
    public function testAnOwnerKeepsASecretOfTheAgentAndAMemberIsRefused(): void {
        $commands = new ServerCommands($this->vault, new InMemoryServerRepository(), new InMemoryCommandAuditRepository());
        $owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $anna = new Member(7, MemberKind::Human, Role::user(), 'Анна');

        $commands->secret($owner, '*: api-key agent value');
        self::assertSame([['name' => 'api-key', 'kind' => 'password']], $this->vault->agentNames());
        self::assertStringContainsString('api-key', $commands->secrets($owner, '*'));
        $refused = $commands->secret($anna, '*: api-key mine');
        self::assertSame(['agent value', Vault::LAYER_AGENT], $this->vault->layered('api-key', null, 1, owner: false, agent: true), $refused);
        self::assertStringNotContainsString('api-key', $commands->secrets($anna, '*'));

        $commands->secret($owner, '*: - api-key');
        self::assertSame([], $this->vault->agentNames());
    }

    /** spec: brief/skills-mcp.md#подстановка-секретов — inheritance is off until turned on by whoever may */
    public function testInheritanceIsTurnedOnByWhoeverMay(): void {
        $projects = new \OpenCCK\Test\Support\InMemoryProjectRepository();
        $inherit = new \OpenCCK\Test\Support\InMemorySecretInheritanceRepository();
        $commands = new ServerCommands($this->vault, new InMemoryServerRepository(), new InMemoryCommandAuditRepository());
        $commands->useProjects($projects);
        $commands->useInheritance($inherit);
        $owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $anna = new Member(7, MemberKind::Human, Role::user(), 'Анна');
        $project = $projects->create('Job Hunt', '', ownerId: 7);

        self::assertSame(['owner' => false, 'agent' => false], $inherit->of('project:' . $project->id), 'off by default');
        $commands->secret($anna, 'Job Hunt: inherit owner on');
        $commands->secret($anna, 'Job Hunt: inherit agent on');
        self::assertSame(['owner' => true, 'agent' => false], $inherit->of('project:' . $project->id), 'the project owner shares their own, not the agent\'s');
        $commands->secret($owner, 'Job Hunt: inherit agent on');
        self::assertTrue($inherit->of('project:' . $project->id)['agent']);

        $commands->secret($anna, 'inherit agent on');
        self::assertFalse($inherit->of('member:7')['agent'], 'a member does not open the agent\'s secrets to themselves');
        $commands->secret($owner, 'inherit agent on 7');
        self::assertTrue($inherit->of('member:7')['agent']);
        $commands->secret($owner, 'inherit agent on');
        self::assertTrue($inherit->of('member:1')['agent'], 'an owner turns it on for themselves');
        $commands->secret($owner, 'inherit agent off 7');
        self::assertFalse($inherit->of('member:7')['agent']);
        self::assertStringContainsString('секреты агента наследуются', $commands->secret($owner, 'inherit'), 'the state of their own context');
    }

    public function testASecretIsStoredAndItsMessageDeleted(): void {
        $this->stack->say('/secret web-pass Sup3r-secret-pass');

        $owner = new Member(1, MemberKind::Human, Role::admin(), null);
        self::assertSame('Sup3r-secret-pass', $this->vault->reveal('web-pass', $owner));
        self::assertCount(1, $this->stack->channel->deleted, 'the message with the secret is gone');
        $replies = implode("\n", $this->stack->channel->texts('send'));
        self::assertStringContainsString('Секрет «web-pass» сохранён (пароль)', $replies);
        self::assertStringNotContainsString('Sup3r-secret-pass', $replies);
        self::assertSame([], $this->stack->llm->requests, 'the model never saw it');
        self::assertSame([], $this->stack->transcript->messages, 'nor the transcript');
    }

    public function testTheOwnerOfAProjectKeepsItsSecretForASiteAndAMemberIsRefused(): void {
        // spec: brief/participants.md#секреты-проекта
        $projects = new \OpenCCK\Test\Support\InMemoryProjectRepository();
        $commands = new ServerCommands($this->vault, new InMemoryServerRepository(), new InMemoryCommandAuditRepository());
        $commands->useProjects($projects);
        $owner = new Member(1, MemberKind::Human, Role::admin(), null);
        $anna = new Member(7, MemberKind::Human, Role::user(), 'Анна');
        $project = $projects->create('Job Hunt', '');
        $projects->share($project->id, $anna->id, 'write', 1);

        self::assertStringContainsString('сохранён, годится для hh.ru', $commands->secret($owner, 'Job Hunt: hh-token value with spaces hh.ru'));
        self::assertSame('value with spaces', $this->vault->revealShared('hh-token', $project->id, 'api.hh.ru'), 'the value is what stands between the name and the site');
        self::assertStringContainsString('hh-token — hh.ru', $commands->secret($owner, 'Job Hunt:'));
        self::assertStringContainsString('только его владелец', $commands->secret($anna, 'Job Hunt: hh-token other hh.ru'));
        self::assertStringContainsString('только его владелец', $commands->secrets($anna, 'Job Hunt'), 'a member sees no list');
        self::assertStringContainsString('не сайт', $commands->secret($owner, 'Job Hunt: hh-token value nosite'));

        $commands->secret($owner, 'web-pass http://a:b@c');
        self::assertSame('http://a:b@c', $this->vault->reveal('web-pass', $owner), 'a colon in a personal value is not a project');
        self::assertStringContainsString('удалён', $commands->secret($owner, 'Job Hunt: - hh-token'));
        self::assertNull($this->vault->revealShared('hh-token', $project->id, 'hh.ru'));
    }

    public function testTheSameCommandUpdatesASecret(): void {
        // spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a wrong value is fixed by sending it again
        $this->stack->say('/secret web-pass wrong-value-1');
        $this->stack->say('/secret web-pass right-value-2');

        self::assertSame('right-value-2', $this->vault->reveal('web-pass', new Member(1, MemberKind::Human, Role::admin(), null)));
        self::assertStringContainsString('Секрет «web-pass» обновлён (пароль)', implode("\n", $this->stack->channel->texts('send')));
    }

    public function testEveryCommandLineOfAMessageIsItsOwnCommand(): void {
        // spec: brief/operations.md#команды-в-одном-сообщении — seven secrets in one message are seven secrets
        $this->stack->say("/secret a-pass value-one-1\n/secret b-pass   value-two-2\n/secret c-pass value-three-3");

        $owner = new Member(1, MemberKind::Human, Role::admin(), null);
        self::assertSame('value-one-1', $this->vault->reveal('a-pass', $owner));
        self::assertSame('value-two-2', $this->vault->reveal('b-pass', $owner));
        self::assertSame('value-three-3', $this->vault->reveal('c-pass', $owner));
        self::assertCount(1, $this->stack->channel->deleted, 'the one message is deleted once');
        self::assertStringNotContainsString('удалите его сами', implode("\n", $this->stack->channel->texts('send')));
    }

    public function testLinesWithoutASlashGoToTheAgentAsAMessage(): void {
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('Принял.'), ScriptedLlm::text('Принял и этот.')]));
        $this->stack->useServers(new ServerCommands($this->vault, new InMemoryServerRepository(), new InMemoryCommandAuditRepository()));

        $this->stack->say("/secret fv-pass first-pass-1\nсохранил тебе пароль от сервера\nкоторый firstvps\nпроверь\n\n/secret birma-pass second-pass-2\nи вот ещё\nсекрет от birma");
        $this->stack->scheduler->awaitIdle();

        $owner = new Member(1, MemberKind::Human, Role::admin(), null);
        self::assertSame('first-pass-1', $this->vault->reveal('fv-pass', $owner));
        self::assertSame('second-pass-2', $this->vault->reveal('birma-pass', $owner));
        $seen = implode("\n", array_map(static fn(array $m): string => $m['message']->content, $this->stack->transcript->messages));
        self::assertStringContainsString("сохранил тебе пароль от сервера\nкоторый firstvps\nпроверь", $seen);
        self::assertStringContainsString("и вот ещё\nсекрет от birma", $seen);
        self::assertStringNotContainsString('first-pass-1', $seen);
        self::assertStringNotContainsString('second-pass-2', $seen);
    }

    public function testAPrivateKeyStaysOneCommand(): void {
        $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n/AAAABG5vbmUAAAAEbm9uZQ\n-----END OPENSSH PRIVATE KEY-----";
        $this->stack->say("/secret srv-key $key\n/secret other-pass other-value-1");

        $owner = new Member(1, MemberKind::Human, Role::admin(), null);
        self::assertSame($key, $this->vault->reveal('srv-key', $owner));
        self::assertSame('other-value-1', $this->vault->reveal('other-pass', $owner));
    }

    public function testInAGroupTheSecretIsRefusedButStillDeleted(): void {
        $this->stack->sayInGroup('/secret web-pass Sup3r-secret-pass');

        self::assertSame([], $this->vault->names(new Member(1, MemberKind::Human, Role::admin(), null)));
        self::assertCount(1, $this->stack->channel->deleted);
        self::assertStringContainsString('только в личном чате', implode("\n", $this->stack->channel->texts('send')));
    }

    public function testWhenTheMessageCannotBeDeletedTheOwnerIsTold(): void {
        $this->stack->channel->deleteFails = true;

        $this->stack->say('/secret key2 another-secret-value');

        self::assertStringContainsString('удалите его сами', implode("\n", $this->stack->channel->texts('send')));
    }

    public function testListsShowNamesOnlyAndTheJournalIsForTheOwner(): void {
        $this->stack->say('/secret db-pass value-of-db');
        $this->stack->say('/secrets');
        $this->stack->say('/audit');
        $this->stack->say('/servers');

        $replies = implode("\n", $this->stack->channel->texts('send'));
        self::assertStringContainsString('db-pass — password', $replies);
        self::assertStringNotContainsString('value-of-db', $replies);
        self::assertStringContainsString('Журнал команд пуст', $replies);
        self::assertStringContainsString('Цепочка журнала цела', $replies);
        self::assertStringContainsString('Серверов в реестре нет', $replies);
    }
}
