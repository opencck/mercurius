<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mail;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DomainException;
use Mercurius\Llm\ToolCall;
use Mercurius\Mcp\McpClient;
use Mercurius\Mcp\McpEndpoint;
use Mercurius\Messenger\IncomingCallback;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Mail\Mailboxes;
use OpenCCK\App\Mail\MailboxTool;
use OpenCCK\App\Mail\MailTool;
use OpenCCK\App\Mcp\McpTool;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Project\Project;
use OpenCCK\Domain\Project\ProjectLevel;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryMailboxRepository;
use OpenCCK\Test\Support\InMemorySecretRepository;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * The mailboxes of participants: who connects one, which one a call uses, what reaches the mail server and who
 * confirms a letter.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class MailboxesTest extends AsyncTestCase {
    private AgentStack $stack;
    private InMemoryMailboxRepository $repository;
    private Vault $vault;
    private Mailboxes $mailboxes;
    private Member $owner;
    private Member $mailer;
    private Member $plain;
    /** the project of the conversation, by its id */
    private ?Project $project = null;
    /** @var array<int, string> the level of a participant in the project, by their id */
    private array $levels = [];
    /** @var list<array{headers: array<string, string>, tool: string, args: array<string, mixed>}> */
    public array $sent = [];

    protected function setUp(): void {
        parent::setUp();
        Role::reset();
        $this->stack = new AgentStack(new ScriptedLlm([
            ScriptedLlm::calls(new ToolCall('c1', 'mcp_mail_mail_send', '{"to":["hr@example.com"],"subject":"Hi","text":"Hello"}')),
            ScriptedLlm::text('Отправлено.'),
        ]));
        $this->stack->approvalService->useParticipantCards('telegram');
        $principals = $this->stack->principals;
        $this->owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $role = Role::define('mailer', 'Почта', 15, [Permission::Mail]);
        $this->mailer = $principals->decideVisitor($principals->createVisitor('telegram', '7', 'Mailer')->id, $role, MemberStatus::Active) ?? self::fail('no participant');
        $this->plain = $principals->decideVisitor($principals->createVisitor('telegram', '8', 'Plain')->id, Role::user(), MemberStatus::Active) ?? self::fail('no participant');

        $this->repository = new InMemoryMailboxRepository();
        $this->vault = new Vault(new InMemorySecretRepository(), str_repeat('k', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        $this->vault->put('mail-pass', 'password', 'app-password-1', $this->mailer);
        $this->vault->put('owner-pass', 'password', 'owner-password', $this->owner);
        $this->mailboxes = new Mailboxes(
            $this->repository,
            $this->vault,
            $principals,
            new AddressGuard(static fn(string $host): array => $host === 'inner.example.com' ? ['10.0.0.5'] : ['93.184.216.34']),
            fn(int $conversationId, Member $who): ?Project => $this->project,
            fn(Project $project, Member $who): ?ProjectLevel => $project->levelOf($who, $this->levels[$who->id] ?? null),
        );
    }

    protected function tearDown(): void {
        Role::reset();
        parent::tearDown();
    }

    private function mailTool(string $remote, bool $destructive): MailTool {
        $test = $this;
        $client = new class($test) implements McpClient {
            public function __construct(private readonly MailboxesTest $test) {}

            public function tools(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [];
            }

            public function resources(McpEndpoint $server, ?string $token, Cancellation $cancellation): array {
                return [];
            }

            public function readResource(McpEndpoint $server, ?string $token, string $uri, Cancellation $cancellation): string {
                return '';
            }

            public function call(McpEndpoint $server, ?string $token, string $tool, array $arguments, Cancellation $cancellation): array {
                $this->test->sent[] = ['headers' => $server->endpointHeaders(), 'tool' => $tool, 'args' => $arguments];
                return ['ok', false];
            }
        };
        $schema = ['type' => 'object', 'properties' => ['to' => ['type' => 'array', 'items' => ['type' => 'string']], 'subject' => ['type' => 'string'], 'text' => ['type' => 'string']]];
        $inner = new McpTool(new McpServer(0, 'mail', 'http://mail-mcp:8090/mcp', null, McpServer::ACTIVE, null), $remote, 'mcp_mail_' . $remote, 'x', $schema, $destructive ? ['destructiveHint' => true] : ['readOnlyHint' => true], $client, static fn(): string => 'token');
        return new MailTool($inner, $remote, $this->mailboxes);
    }

    private function context(Member $who): ToolContext {
        return new ToolContext(1, 1, $who, new NullCancellation(), $this->stack->policies->forPrincipal($who));
    }

    public function testAMailboxIsConnectedByTheOneWhoHasThePowerAndTheSecret(): void {
        $mailbox = $this->mailboxes->add($this->mailer, 'work', 'IMAP.Example.com', 'me@example.com', 'mail-pass');
        self::assertSame('imap.example.com', $mailbox->host);
        self::assertSame([993, 465, 'me@example.com'], [$mailbox->imapPort, $mailbox->smtpPort, $mailbox->from]);

        foreach ([
            'no permission' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->plain, 'work', 'imap.example.com', 'me@example.com', 'mail-pass'),
            'the secret of another participant' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->mailer, 'second', 'imap.example.com', 'me@example.com', 'owner-pass'),
            'no such secret' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->mailer, 'second', 'imap.example.com', 'me@example.com', 'nothing'),
            'an internal host' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->mailer, 'second', 'inner.example.com', 'me@example.com', 'mail-pass'),
            'an address instead of a name' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->mailer, 'second', '10.0.0.5', 'me@example.com', 'mail-pass'),
            'a port of another service' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->mailer, 'second', 'imap.example.com', 'me@example.com', 'mail-pass', 993, 25),
            'a login with a line break' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->mailer, 'second', 'imap.example.com', "me\r\nX-Mail-Host: evil", 'mail-pass'),
            'the same name' => fn(): \OpenCCK\Domain\Mail\Mailbox => $this->mailboxes->add($this->mailer, 'WORK', 'imap.example.com', 'me@example.com', 'mail-pass'),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail($case . ' was accepted');
            } catch (DomainException) {
                // refused, in words for the participant
            }
        }
        self::assertCount(1, $this->repository->mailboxes);
    }

    public function testACallTakesTheMailboxOfTheProjectThenTheOwnOne(): void {
        self::assertIsString($this->mailboxes->pick($this->mailer, 1, null), 'nothing is connected');
        self::assertIsString($this->mailboxes->pick($this->plain, 1, null), 'no permission, no mail');

        $work = $this->mailboxes->add($this->mailer, 'work', 'imap.example.com', 'me@example.com', 'mail-pass');
        self::assertSame($work->id, self::id($this->mailboxes->pick($this->mailer, 1, null)));

        $this->mailboxes->add($this->mailer, 'home', 'imap.example.org', 'me@example.org', 'mail-pass');
        $which = $this->mailboxes->pick($this->mailer, 1, null);
        self::assertIsString($which, 'two mailboxes and no name: not guessed');
        self::assertStringContainsString('home', $which);
        self::assertSame($work->id, self::id($this->mailboxes->pick($this->mailer, 1, 'Work')));
        self::assertIsString($this->mailboxes->pick($this->mailer, 1, 'other'));

        // the owner of the agent gives a mailbox to their project; a participant with write access uses it there
        $shared = $this->mailboxes->add($this->owner, 'jobs', 'imap.example.net', 'jobs@example.net', 'owner-pass');
        $this->project = new Project(3, 'Job Hunt', '', Project::ACTIVE, '', 0);
        self::assertSame($shared->id, $this->mailboxes->toProject($this->owner, 'jobs', $this->project)->id);
        self::assertIsString($this->mailboxes->pick($this->mailer, 1, 'jobs'), 'the project is not theirs');
        self::assertNull($this->mailboxes->usable($this->mailer, 1, $shared->id));

        $this->levels[$this->mailer->id] = 'write';
        self::assertSame($shared->id, self::id($this->mailboxes->pick($this->mailer, 1, null)), 'in the project its mailbox comes first');
        self::assertNotNull($this->mailboxes->usable($this->mailer, 1, $shared->id));
        self::assertSame($this->owner->id, $this->mailboxes->ownerOf($shared)?->id, 'the one who connected it decides what is sent');

        $this->levels[$this->mailer->id] = 'read';
        self::assertNull($this->mailboxes->usable($this->mailer, 1, $shared->id), 'reading a project does not open its mail');

        try {
            $this->mailboxes->toProject($this->mailer, 'work', $this->project);
            self::fail('a mailbox was given to a project by someone without full access');
        } catch (DomainException) {
            self::assertNull($this->repository->find($work->id)?->projectId);
        }
    }

    public function testInAProjectAMailboxOfAnotherParticipantNotGivenToItIsNotThere(): void {
        // spec: brief/skills-mcp.md#ящики-участников-и-проектов — the mailboxes of the project and one's own, nobody else's
        $this->project = new Project(3, 'Job Hunt', '', Project::ACTIVE, '', 0);
        $this->levels[$this->mailer->id] = 'write';
        $given = $this->mailboxes->add($this->owner, 'jobs', 'imap.example.net', 'jobs@example.net', 'owner-pass');
        $this->mailboxes->toProject($this->owner, 'jobs', $this->project);
        $kept = $this->mailboxes->add($this->owner, 'private', 'imap.example.net', 'me@example.net', 'owner-pass');
        $own = $this->mailboxes->add($this->mailer, 'work', 'imap.example.com', 'me@example.com', 'mail-pass');

        self::assertSame($given->id, self::id($this->mailboxes->pick($this->mailer, 1, 'jobs')), 'given to the project');
        self::assertSame($own->id, self::id($this->mailboxes->pick($this->mailer, 1, 'work')), 'their own, for their own runs');
        self::assertIsString($this->mailboxes->pick($this->mailer, 1, 'private'), 'the owner\'s mailbox not given to the project');
        self::assertNull($this->mailboxes->usable($this->mailer, 1, $kept->id));
        $unknown = $this->mailboxes->pick($this->mailer, 1, 'nothing');
        self::assertIsString($unknown);
        self::assertStringNotContainsString('private', $unknown, 'not even named in the list');
    }

    /** @return list<array{headers: array<string, string>, tool: string, args: array<string, mixed>}> what reached the mail server */
    private function sentCalls(): array {
        return $this->sent;
    }

    private static function id(mixed $picked): ?int {
        return $picked instanceof \OpenCCK\Domain\Mail\Mailbox ? $picked->id : null;
    }

    public function testTheServerGetsTheMailboxOfTheCallerInTheHeadersOfTheRequest(): void {
        $work = $this->mailboxes->add($this->mailer, 'work', 'imap.example.com', 'me@example.com', 'mail-pass', null, 587, 'Me@Example.com', 'mx.example.com');
        $tool = $this->mailTool('mail_list', false);
        /** @var mixed $properties */
        $properties = $tool->definition()->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('mailbox', $properties);

        // what a model writes into the place of the chosen mailbox is replaced
        $prepared = $tool->prepareIn(['subject' => 'code', 'mailbox_id' => 999], $this->mailer, 1, new NullCancellation());
        self::assertIsArray($prepared);
        self::assertSame(['work', $work->id], [$prepared['mailbox'] ?? null, $prepared['mailbox_id'] ?? null]);
        self::assertSame(DangerClass::Read, $tool->danger($prepared));

        $result = $tool->execute($prepared, $this->context($this->mailer));
        self::assertFalse($result->isError, $result->content);
        self::assertSame(['subject' => 'code'], $this->sent[0]['args'], 'the mail server gets only its own arguments');
        self::assertSame([
            'x-mail-host' => 'imap.example.com',
            'x-mail-user' => 'me@example.com',
            'x-mail-password' => 'app-password-1',
            'x-mail-imap-port' => '993',
            'x-mail-smtp-port' => '587',
            'x-mail-from' => 'Me@Example.com',
            'x-mail-authserv-id' => 'mx.example.com',
        ], $this->sent[0]['headers']);
        self::assertStringNotContainsString('app-password-1', json_encode($prepared, JSON_THROW_ON_ERROR), 'the password is not among the arguments');

        // another participant with the same arguments does not get into the mailbox
        self::assertTrue($tool->execute($prepared, $this->context($this->owner))->isError);
        self::assertIsString($tool->prepareIn([], $this->plain, 1, new NullCancellation()));
        // a removed secret: the mailbox does not open, the reason is told
        $this->vault->remove('mail-pass', $this->mailer);
        self::assertTrue($tool->execute($prepared, $this->context($this->mailer))->isError);
        self::assertCount(1, $this->sent);
    }

    public function testThePowerOfMailBringsTheMailTools(): void {
        $policy = $this->stack->policies->forPrincipal($this->mailer);
        self::assertTrue($policy->allowsTool('mcp_mail_mail_send'));
        self::assertTrue($policy->allowsTool(MailboxTool::NAME));
        self::assertFalse($this->stack->policies->forPrincipal($this->plain)->allowsTool('mcp_mail_mail_list'), 'no permission, no mail');
    }

    public function testALetterIsConfirmedByTheOneWhoConnectedTheMailbox(): void {
        $work = $this->mailboxes->add($this->mailer, 'work', 'imap.example.com', 'me@example.com', 'mail-pass');
        $this->stack->registry->register($this->mailTool('mail_send', true));

        $this->stack->say('отправь письмо', '7');
        $this->stack->scheduler->awaitIdle();

        $run = $this->stack->runs->find(1);
        self::assertSame(RunStatus::WaitingApproval, $run?->status, 'a participant who may not ask the owner still gets a card about their own mailbox');
        $approval = $this->stack->approvals->find(1) ?? self::fail('no card');
        self::assertSame('7', $approval->cardChatId, 'the card is in the private chat of the owner of the mailbox');
        self::assertSame(sprintf('mail:mail_send:%d', $work->id), $approval->grantKey);
        self::assertCount(0, $this->sentCalls());

        // someone else presses: not theirs to decide
        $this->stack->callbacks->handleCallback(new IncomingCallback('telegram', 'cb1', '8', '8', 'Plain', '1', 'ap:1:a', '900'));
        self::assertCount(0, $this->sentCalls(), 'a press of someone else changes nothing');

        $this->stack->callbacks->handleCallback(new IncomingCallback('telegram', 'cb2', '7', '7', 'Mailer', '1', 'ap:1:a', '901'));
        \Amp\delay(0.01);
        $this->stack->scheduler->awaitIdle();

        $sent = $this->sentCalls();
        self::assertCount(1, $sent);
        self::assertSame('mail_send', $sent[0]['tool']);
        self::assertSame('app-password-1', $sent[0]['headers']['x-mail-password'] ?? null);
        self::assertSame(RunStatus::Done, $this->stack->runs->find(1)?->status);
    }

    public function testTheToolOfMailboxesConnectsListsAndChecks(): void {
        $probed = [];
        $tool = new MailboxTool(
            $this->mailboxes,
            fn(string $name, Member $who): ?Project => null,
            /** @param array<non-empty-string, string> $account */
            static function (array $account, Cancellation $cancellation) use (&$probed): ?string {
                $probed[] = $account['x-mail-user'];
                return $account['x-mail-host'] === 'imap.example.org' ? 'AUTHENTICATIONFAILED' : null;
            },
        );
        $context = $this->context($this->mailer);

        self::assertTrue($tool->execute(['action' => 'list'], $this->context($this->plain))->isError);
        $added = $tool->execute(['action' => 'add', 'name' => 'work', 'host' => 'imap.example.com', 'user' => 'me@example.com', 'secret' => 'mail-pass'], $context);
        self::assertFalse($added->isError, $added->content);
        self::assertStringNotContainsString('app-password-1', $added->content);
        self::assertTrue($tool->execute(['action' => 'add', 'name' => 'x', 'host' => 'imap.example.com'], $context)->isError, 'no login and no secret');

        self::assertStringContainsString('me@example.com', $tool->execute(['action' => 'list'], $context)->content);
        self::assertFalse($tool->execute(['action' => 'check', 'name' => 'work'], $context)->isError);
        $tool->execute(['action' => 'add', 'name' => 'home', 'host' => 'imap.example.org', 'user' => 'me@example.org', 'secret' => 'mail-pass'], $context);
        $failed = $tool->execute(['action' => 'check', 'name' => 'home'], $context);
        self::assertTrue($failed->isError);
        self::assertStringContainsString('AUTHENTICATIONFAILED', $failed->content);
        self::assertSame(['me@example.com', 'me@example.org'], $probed);

        self::assertFalse($tool->execute(['action' => 'remove', 'name' => 'home'], $context)->isError);
        self::assertCount(1, $this->repository->mailboxes);
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'list']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'add']));
    }
}
