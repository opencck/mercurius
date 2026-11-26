<?php

declare(strict_types=1);

namespace OpenCCK\App\Mail;

use Amp\Cancellation;
use Closure;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\DecidedByParticipant;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\PreparesInConversation;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;

use function Mercurius\I18n\t;

/**
 * A mail tool of the core (`mcp_mail_mail_send` and the others): the model names a mailbox or none, the application
 * finds the entry — of the context of the conversation (a project's mailbox), then the caller's own — and calls the
 * tool of that entry, with its wrapper. A mailbox of another member is not reached; the choice is made again at the run.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class MailTool implements Tool, ReadsOutside, GrantKeyed, PreparesInConversation, DecidedByParticipant {
    /** the mailbox the application chose: context|name; a model cannot set it */
    private const CHOSEN = 'mailbox_key';

    /**
     * @param Closure(int, Member): ?string $contextOf the context of a conversation for the one who talks in it
     * @param Closure(string, string): ?Tool $toolOf the tool of a mailbox entry: its context and tool name
     */
    public function __construct(
        private readonly string $remote,
        private readonly ToolDefinition $sample,
        private readonly MailEntries $entries,
        private readonly Closure $contextOf,
        private readonly Closure $toolOf,
    ) {}

    public function name(): string {
        return 'mcp_mail_' . $this->remote;
    }

    public function definition(): ToolDefinition {
        $schema = $this->sample->parameters;
        /** @var array<array-key, mixed> $properties */
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $properties['mailbox'] = ['type' => 'string', 'maxLength' => 64, 'description' => t('mail.tool.mailbox_arg')];
        $schema['properties'] = $properties;
        $about = preg_replace('~^\[MCP [^\]]*\]\s*~', '', $this->sample->description) ?? $this->sample->description;
        return new ToolDefinition($this->name(), '[MCP mail] ' . $about, $schema);
    }

    public function prepareIn(array $args, Member $principal, int $conversationId, Cancellation $cancellation): array|string {
        $name = isset($args['mailbox']) && is_string($args['mailbox']) ? $args['mailbox'] : null;
        $box = $this->entries->pick($principal->id, ($this->contextOf)($conversationId, $principal), $name);
        if (is_string($box)) {
            return $box;
        }
        $args['mailbox'] = $box->name;
        $args[self::CHOSEN] = $box->key;
        return $args;
    }

    public function danger(array $args): DangerClass {
        return $this->tool($args)?->danger(self::bare($args)) ?? DangerClass::Irreversible;
    }

    public function readsOutside(array $args): bool {
        return true;
    }

    /** @return non-empty-string */
    public function grantKey(array $args): string {
        return sprintf('mail:%s:%s', $this->remote, self::chosen($args) ?? '-');
    }

    public function describe(array $args): string {
        $tool = $this->tool($args);
        return $tool instanceof MailEntryTool ? $tool->describe(self::bare($args)) : $this->remote;
    }

    public function decider(array $args): ?Member {
        $tool = $this->tool($args);
        return $tool instanceof DecidedByParticipant ? $tool->decider(self::bare($args)) : null;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 120;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        // the choice is checked again at the run: a mailbox removed or turned off after the card is not used
        $chosen = self::chosen($args);
        $box = $chosen === null ? null : $this->entries->pick($context->principal->id, ($this->contextOf)($context->conversationId, $context->principal), $chosen);
        $tool = $box instanceof MailTarget ? ($this->toolOf)($box->context, \OpenCCK\App\Mcp\McpManager::toolName($box->name, $this->remote)) : null;
        if ($tool === null) {
            return ToolResult::error(t('mail.mailbox.none'));
        }
        return $tool->execute(self::bare($args), $context);
    }

    /** @param array<array-key, mixed> $args */
    private function tool(array $args): ?Tool {
        $chosen = self::chosen($args);
        $parts = $chosen === null ? [] : explode('|', $chosen, 2);
        return count($parts) === 2 ? ($this->toolOf)($parts[0], \OpenCCK\App\Mcp\McpManager::toolName($parts[1], $this->remote)) : null;
    }

    /** @param array<array-key, mixed> $args */
    private static function chosen(array $args): ?string {
        return isset($args[self::CHOSEN]) && is_string($args[self::CHOSEN]) ? $args[self::CHOSEN] : null;
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
