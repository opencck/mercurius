<?php

declare(strict_types=1);

namespace OpenCCK\App\Mail;

use Amp\Cancellation;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\DecidedByParticipant;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\PreparesInConversation;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Mcp\McpTool;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;

use function Mercurius\I18n\t;

/**
 * A tool of the mail server with the mailbox of the caller: the model names a mailbox or none, the application finds
 * it and sends its address and password to the server in the headers of this one request.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class MailTool implements Tool, ReadsOutside, GrantKeyed, PreparesInConversation, DecidedByParticipant {
    /** the mailbox the application chose; a model cannot set it */
    private const CHOSEN = 'mailbox_id';

    public function __construct(
        private readonly McpTool $inner,
        private readonly string $remoteName,
        private readonly Mailboxes $mailboxes,
    ) {}

    public function definition(): ToolDefinition {
        $definition = $this->inner->definition();
        $schema = $definition->parameters;
        /** @var array<array-key, mixed> $properties */
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $properties['mailbox'] = ['type' => 'string', 'maxLength' => 64, 'description' => t('mail.tool.mailbox_arg')];
        $schema['properties'] = $properties;
        return new ToolDefinition($definition->name, $definition->description, $schema);
    }

    public function prepareIn(array $args, Member $principal, int $conversationId, Cancellation $cancellation): array|string {
        $name = isset($args['mailbox']) && is_string($args['mailbox']) ? $args['mailbox'] : null;
        $mailbox = $this->mailboxes->pick($principal, $conversationId, $name);
        if (is_string($mailbox)) {
            return $mailbox;
        }
        $args['mailbox'] = $mailbox->name;
        $args[self::CHOSEN] = $mailbox->id;
        return $args;
    }

    public function danger(array $args): DangerClass {
        return $this->inner->danger(self::bare($args));
    }

    public function readsOutside(array $args): bool {
        return true;
    }

    /** @return non-empty-string */
    public function grantKey(array $args): string {
        return sprintf('mail:%s:%d', $this->remoteName, self::chosen($args) ?? 0);
    }

    public function describe(array $args): string {
        $id = self::chosen($args);
        $mailbox = $id === null ? null : $this->mailboxes->find($id);
        return $mailbox === null ? $this->remoteName : sprintf(t('mail.tool.describe'), $this->remoteName, $mailbox->name, $mailbox->from);
    }

    public function decider(array $args): ?Member {
        $id = self::chosen($args);
        $mailbox = $id === null ? null : $this->mailboxes->find($id);
        return $mailbox === null ? null : $this->mailboxes->ownerOf($mailbox);
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return $this->inner->timeout();
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $id = self::chosen($args);
        // the right is checked again at the run: a mailbox taken from the project or removed after the card is not used
        $mailbox = $id === null ? null : $this->mailboxes->usable($context->principal, $context->conversationId, $id);
        if ($mailbox === null) {
            return ToolResult::error(t('mail.mailbox.none'));
        }
        $account = $this->mailboxes->account($mailbox);
        if (is_string($account)) {
            return ToolResult::error($account);
        }
        return $this->inner->executeWith(self::bare($args), $context, $account);
    }

    /** @param array<array-key, mixed> $args */
    private static function chosen(array $args): ?int {
        return isset($args[self::CHOSEN]) && is_int($args[self::CHOSEN]) ? $args[self::CHOSEN] : null;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed> what the mail server itself takes
     */
    private static function bare(array $args): array {
        unset($args['mailbox'], $args[self::CHOSEN]);
        return $args;
    }
}
