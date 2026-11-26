<?php

declare(strict_types=1);

namespace OpenCCK\App\Mail;

use Closure;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\DecidedByParticipant;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Member;

use function Mercurius\I18n\t;

/**
 * A tool of a mailbox of our mail MCP with the wrapper of the core: a host of an internal network is refused before the
 * process is asked, the keeper of the mailbox (the member whose file holds it, the owner of a project's) decides what is
 * sent, and a letter is text written by others.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов — the host does not lead into an internal network, the card goes to whose secret is in the entry
 */
final class MailEntryTool implements Tool, ReadsOutside, GrantKeyed, DecidedByParticipant {
    /** @param Closure(string): ?Member $keeper who keeps the mailboxes of a context */
    public function __construct(
        private readonly Tool $inner,
        private readonly string $remote,
        public readonly MailTarget $box,
        private readonly AddressGuard $addresses,
        private readonly Closure $keeper,
    ) {}

    public function definition(): ToolDefinition {
        return $this->inner->definition();
    }

    public function danger(array $args): DangerClass {
        return $this->inner->danger($args);
    }

    public function readsOutside(array $args): bool {
        return true;
    }

    /** @return non-empty-string */
    public function grantKey(array $args): string {
        return sprintf('mail:%s:%s', $this->remote, $this->box->key);
    }

    public function describe(array $args): string {
        return sprintf(t('mail.tool.describe'), $this->remote, $this->box->name, $this->box->from);
    }

    public function decider(array $args): ?Member {
        return ($this->keeper)($this->box->context);
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return $this->inner->timeout();
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        // the process in the sandbox reaches the LAN: a mailbox host is checked as any address the agent opens
        $refusal = $this->addresses->refusal($this->box->host);
        if ($refusal !== null) {
            return ToolResult::error(sprintf(t('mail.mailbox.host_refused'), $this->box->host, $refusal));
        }
        return $this->inner->execute($args, $context);
    }
}
