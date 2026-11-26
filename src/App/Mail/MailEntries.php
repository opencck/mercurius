<?php

declare(strict_types=1);

namespace OpenCCK\App\Mail;

use Closure;
use OpenCCK\Domain\Mcp\McpServer;
use OpenCCK\Domain\Repository\McpServerRepository;

use function Mercurius\I18n\t;

/**
 * The mailboxes: entries of .mcp.json that run our mail MCP, known by the installed code an entry starts (a path under
 * /workspace/mcp/<install>/ of an install from our repository), not by the name of the entry. A call finds its mailbox
 * in the context of the run (a project's mailbox), then among the caller's own.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class MailEntries {
    public const SOURCE = '~^https://github\.com/opencck/mercurius-mail-mcp(?:\.git)?/?$~i';
    private const CODE = '~^/workspace/mcp/([a-z0-9][a-z0-9_]{0,23})/~';

    /** @param Closure(string): ?string $sourceOf the source of an install by its name; null — no such install */
    public function __construct(
        private readonly McpServerRepository $servers,
        private readonly Closure $sourceOf,
    ) {}

    /** The mailbox of a connection that runs our mail MCP; null — another MCP, or no mailbox named. */
    public function target(McpServer $server): ?MailTarget {
        $entry = $server->entry;
        if ($entry === null || $server->context === '' || ($entry['command'] ?? null) !== 'php') {
            return null;
        }
        $values = [];
        $ours = false;
        /** @var mixed $arg */
        foreach (is_array($entry['args'] ?? null) ? $entry['args'] : [] as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (!$ours && preg_match(self::CODE, $arg, $m) === 1) {
                $ours = preg_match(self::SOURCE, ($this->sourceOf)($m[1]) ?? '') === 1;
            }
            if (preg_match('~^--([a-z-]+)=(.*)$~s', $arg, $m) === 1) {
                $values[$m[1]] = $m[2];
            }
        }
        $host = $values['host'] ?? '';
        $user = $values['user'] ?? '';
        if (!$ours || $host === '' || $user === '') {
            return null;
        }
        return new MailTarget($server->key(), $server->context, $server->name, $host, $user, ($values['from'] ?? '') === '' ? $user : $values['from']);
    }

    /** @return list<MailTarget> the mailboxes of one context that are on */
    public function of(string $context): array {
        $found = [];
        foreach ($this->servers->inContext($context) as $server) {
            $target = $server->enabled() ? $this->target($server) : null;
            if ($target !== null) {
                $found[] = $target;
            }
        }
        return $found;
    }

    /**
     * The mailbox of a call: the named one (or `context|name`); with no name — the only one of the context of the run,
     * then the only one of the caller's own. Several and no name — the answer lists them: a mailbox is not guessed.
     */
    public function pick(int $principalId, ?string $context, ?string $name): MailTarget|string {
        $ofContext = $context === null || $context === 'member:' . $principalId ? [] : $this->of($context);
        $own = $this->of('member:' . $principalId);
        $name = mb_strtolower(trim($name ?? ''));
        if ($name !== '') {
            foreach ([...$ofContext, ...$own] as $box) {
                if ($box->name === $name || $box->key === $name) {
                    return $box;
                }
            }
            return sprintf(t('mail.mailbox.unknown'), $name) . self::names([...$ofContext, ...$own]);
        }
        foreach ([$ofContext, $own] as $found) {
            if (count($found) === 1) {
                return $found[0];
            }
            if (count($found) > 1) {
                return t('mail.mailbox.which') . self::names($found);
            }
        }
        return t('mail.mailbox.none');
    }

    /** @param list<MailTarget> $boxes */
    private static function names(array $boxes): string {
        return $boxes === [] ? '' : ' ' . sprintf(t('mail.mailbox.available'), implode(', ', array_map(static fn(MailTarget $m): string => $m->name, $boxes)));
    }
}
