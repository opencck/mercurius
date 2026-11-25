<?php

declare(strict_types=1);

namespace OpenCCK\App\Mail;

use Amp\Cancellation;
use Closure;
use DomainException;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Mail\Mailbox;
use OpenCCK\Domain\Project\Project;

use function Mercurius\I18n\t;

/**
 * The mailboxes of the participant: connect one, see them, check a connection, give one to a project, remove one. The
 * password is named by its secret of the vault and never passes through a model.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class MailboxTool implements Tool {
    public const NAME = 'mailbox_edit';
    private const ACTIONS = ['list', 'add', 'check', 'to_project', 'from_project', 'remove'];

    /**
     * @param Closure(string, Member): ?Project $projectByName a project the participant works with, by its name
     * @param Closure(array<non-empty-string, string>, Cancellation): ?string $probe opens the mailbox once; the reason when it did not open
     */
    public function __construct(
        private readonly Mailboxes $mailboxes,
        private readonly Closure $projectByName,
        private readonly Closure $probe,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('mail.tool.mailbox_edit'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'host' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'user' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 254],
                'secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'description' => t('mail.tool.secret_arg')],
                'imap_port' => ['type' => 'integer', 'enum' => Mailbox::IMAP_PORTS],
                'smtp_port' => ['type' => 'integer', 'enum' => Mailbox::SMTP_PORTS],
                'from' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 320],
                'authserv_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'project' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(Args::string($args, 'action'), ['list', 'check'], true) ? DangerClass::Read : DangerClass::Write;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 60;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $who = $context->principal;
        if (!Mailboxes::may($who)) {
            return ToolResult::error(t('mail.mailbox.no_power'));
        }
        $action = Args::string($args, 'action') ?? '';
        $name = trim(Args::string($args, 'name') ?? '');
        if ($action === 'list') {
            return ToolResult::ok($this->list($who, $context->conversationId));
        }
        if ($name === '') {
            return ToolResult::error(t('mail.tool.need_name'));
        }
        try {
            return match ($action) {
                'add' => $this->add($who, $name, $args),
                'check' => $this->check($who, $name, $context),
                'to_project' => $this->toProject($who, $name, Args::string($args, 'project')),
                'from_project' => ToolResult::ok(sprintf(t('mail.tool.taken_back'), $this->mailboxes->toProject($who, $name, null)->name)),
                'remove' => $this->mailboxes->remove($who, $name) ? ToolResult::ok(sprintf(t('mail.tool.removed'), $name)) : ToolResult::error(sprintf(t('mail.mailbox.unknown'), $name)),
                default => ToolResult::error(t('mail.tool.bad_action')),
            };
        } catch (DomainException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    /** @param array<string, mixed> $args */
    private function add(Member $who, string $name, array $args): ToolResult {
        $host = Args::string($args, 'host');
        $user = Args::string($args, 'user');
        $secret = Args::string($args, 'secret');
        if ($host === null || $user === null || $secret === null) {
            return ToolResult::error(t('mail.tool.need_fields'));
        }
        $mailbox = $this->mailboxes->add($who, $name, $host, $user, $secret, Args::int($args, 'imap_port'), Args::int($args, 'smtp_port'), Args::string($args, 'from'), Args::string($args, 'authserv_id'));
        return ToolResult::ok(sprintf(t('mail.tool.added'), $mailbox->name, $mailbox->from, $mailbox->host));
    }

    private function check(Member $who, string $name, ToolContext $context): ToolResult {
        $mailbox = $this->mailboxes->pick($who, $context->conversationId, $name);
        if (is_string($mailbox)) {
            return ToolResult::error($mailbox);
        }
        $account = $this->mailboxes->account($mailbox);
        if (is_string($account)) {
            return ToolResult::error($account);
        }
        $failure = ($this->probe)($account, $context->cancellation);
        return $failure === null ? ToolResult::ok(sprintf(t('mail.tool.opens'), $mailbox->name)) : ToolResult::error(sprintf(t('mail.tool.does_not_open'), $mailbox->name, $failure));
    }

    private function toProject(Member $who, string $name, ?string $projectName): ToolResult {
        $project = $projectName === null ? null : ($this->projectByName)(trim($projectName), $who);
        if ($project === null) {
            return ToolResult::error(t('mail.tool.need_project'));
        }
        $mailbox = $this->mailboxes->toProject($who, $name, $project);
        return ToolResult::ok(sprintf(t('mail.tool.given'), $mailbox->name, $project->name));
    }

    private function list(Member $who, int $conversationId): string {
        $lines = [];
        foreach ($this->mailboxes->own($who) as $mailbox) {
            $lines[] = sprintf(t('mail.tool.line_own'), $mailbox->name, $mailbox->from, $mailbox->host) . ($mailbox->projectId === null ? '' : ' ' . sprintf(t('mail.tool.line_project'), $mailbox->projectId));
        }
        foreach ($this->mailboxes->ofConversation($who, $conversationId) as $mailbox) {
            if ($mailbox->ownerId !== $who->id) {
                $lines[] = sprintf(t('mail.tool.line_of_project'), $mailbox->name, $mailbox->from);
            }
        }
        return $lines === [] ? t('mail.mailbox.none') : implode("\n", $lines);
    }
}
