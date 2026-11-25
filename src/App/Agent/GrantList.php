<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Closure;
use DateTimeImmutable;
use Mercurius\Messenger\Button;
use OpenCCK\Domain\Agent\Grant;
use OpenCCK\Domain\Repository\ApprovalRepository;
use OpenCCK\Domain\Repository\MemberRepository;

use function Mercurius\I18n\t;

/**
 * The owner's list of saved grants, readable: a summary by groups, a group or a selection by pages with what each
 * grant allows in words, revoking one or all of a selection, and tidying up — grants never used and many exact grants
 * of one server replaced by one wide.
 *
 * A view is addressed by a selector: "" — the summary, "g.<group>", "s.<server>", "j.<job>", "p.<project>", "u" —
 * never used. Presses: "pg:l:<selector>:<page>" a page, "pg:r:<id>:<selector>:<page>" revoke one,
 * "pg:a:<selector>" ask to revoke all, "pg:A:<selector>" do it, "pg:w:<server>" ask to replace by a wide grant,
 * "pg:W:<server>" do it. The old "pg:<id>:x" still revokes one.
 *
 * spec: brief/agent-loop.md#список-разрешений
 */
final class GrantList {
    public const PAGE = 8;
    /** A grant that never ran is shown as unused after this many days. */
    public const UNUSED_DAYS = 30;
    /** As many exact grants of one server make the offer to replace them by one wide. */
    private const WIDEN_FROM = 3;
    private const GROUPS = ['servers', 'secrets', 'http', 'models', 'settings', 'messages', 'areas', 'other'];
    private const ALIASES = [
        'серверы' => 'servers', 'секреты' => 'secrets', 'запросы' => 'http', 'модели' => 'models', 'настройки' => 'settings',
        'сообщения' => 'messages', 'области' => 'areas', 'остальное' => 'other', 'unused' => 'unused', 'неиспользуемые' => 'unused',
    ];

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /** @param (Closure(): DateTimeImmutable)|null $now */
    public function __construct(
        private readonly ApprovalRepository $approvals,
        private readonly MemberRepository $principals,
        ?Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /** The group a grant is listed in, by its key. */
    public static function group(string $key): string {
        return match (true) {
            str_starts_with($key, 'area:') || preg_match('~^(job|project):\d+\|~', $key) === 1 => 'areas',
            preg_match('~^(ssh|ssh-any|ssh-never|ssh-sudo|server-add|server-edit|server-remove|file-write|file-write-never):~', $key) === 1 => 'servers',
            preg_match('~^(http-secret|git-secret|sql-read|sql-write):~', $key) === 1 => 'secrets',
            preg_match('~^(http|http-any|addr|git-push|exec-outward|cmd):~', $key) === 1 => 'http',
            str_starts_with($key, 'model-role') => 'models',
            str_starts_with($key, 'settings') => 'settings',
            preg_match('~^(message|channel|forum):~', $key) === 1 => 'messages',
            default => 'other',
        };
    }

    /** The server a grant belongs to; null — not a grant of a server. */
    public static function server(string $key): ?string {
        foreach (['~^(?:ssh|ssh-never|ssh-sudo):v\d+:([^:]+):~', '~^(?:ssh-any|server-add|server-edit|server-remove):(.+)$~', '~^file-write(?:-never)?:([^:]+):~'] as $pattern) {
            if (preg_match($pattern, $key, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * The selector a command argument names; "" — the summary; null — not understood.
     *
     * "/permissions servers", "/permissions server base", "/permissions job 5", "/permissions project 8".
     */
    public static function selectorOf(string $argument): ?string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $first = mb_strtolower($parts[0] ?? '');
        $rest = trim(implode(' ', array_slice($parts, 1)));
        if ($first === '') {
            return '';
        }
        $group = self::ALIASES[$first] ?? $first;
        return match (true) {
            $group === 'unused' && $rest === '' => 'u',
            in_array($group, self::GROUPS, true) && $rest === '' => 'g.' . $group,
            in_array($first, ['server', 'сервер'], true) && $rest !== '' => 's.' . mb_strtolower($rest),
            in_array($first, ['job', 'задание'], true) && ctype_digit(ltrim($rest, '#')) => 'j.' . (int) ltrim($rest, '#'),
            in_array($first, ['project', 'проект'], true) && ctype_digit(ltrim($rest, '#')) => 'p.' . (int) ltrim($rest, '#'),
            default => null,
        };
    }

    /**
     * What the owner sees for a selector: the summary when the list is long, else the grants themselves.
     *
     * @return array{string, list<list<Button>>} the text and the buttons
     */
    public function view(string $selector, int $page = 0): array {
        $all = $this->approvals->grants();
        if ($selector === '' && count($all) > self::PAGE) {
            return $this->summary($all);
        }
        return $this->page($selector, $this->select($all, $selector), $page, count($all));
    }

    /**
     * A press on the list; null — not a press of the list.
     *
     * @return array{string, array{string, list<list<Button>>}}|null what to tell under the button and the new view
     */
    public function press(string $data): ?array {
        if (preg_match('~^pg:(\d+):x$~', $data, $m) === 1) {
            return [$this->revoke((int) $m[1]), $this->view('')];
        }
        if (preg_match('~^pg:l:([^:]*):(\d+)$~', $data, $m) === 1) {
            return ['', $this->view($m[1], (int) $m[2])];
        }
        if (preg_match('~^pg:r:(\d+):([^:]*):(\d+)$~', $data, $m) === 1) {
            return [$this->revoke((int) $m[1]), $this->view($m[2], (int) $m[3])];
        }
        if (preg_match('~^pg:a:([^:]+)$~', $data, $m) === 1) {
            $count = count($this->select($this->approvals->grants(), $m[1]));
            return ['', [sprintf(t('agent.grants.confirm_all'), self::title($m[1]), $count), [[
                new Button(sprintf(t('agent.grants.btn_confirm_all'), $count), 'pg:A:' . $m[1]),
                new Button(t('agent.grants.btn_cancel'), 'pg:l:' . $m[1] . ':0'),
            ]]]];
        }
        if (preg_match('~^pg:A:([^:]+)$~', $data, $m) === 1) {
            $count = 0;
            foreach ($this->select($this->approvals->grants(), $m[1]) as $grant) {
                $count += $this->approvals->revokeGrant($grant->id) === null ? 0 : 1;
            }
            return [sprintf(t('agent.grants.revoked_all'), $count), $this->view('')];
        }
        if (preg_match('~^pg:w:(.+)$~', $data, $m) === 1) {
            $exact = $this->exactOf($m[1]);
            return ['', [sprintf(t('agent.grants.widen_confirm'), count($exact), GrantKeys::label('ssh-any:' . $m[1])), [[
                new Button(t('agent.grants.widen_yes'), 'pg:W:' . $m[1]),
                new Button(t('agent.grants.btn_cancel'), 'pg:l:s.' . $m[1] . ':0'),
            ]]]];
        }
        if (preg_match('~^pg:W:(.+)$~', $data, $m) === 1) {
            $exact = $this->exactOf($m[1]);
            if ($exact === []) {
                return [t('agent.approval_service.uzhe_otozvano'), $this->view('s.' . $m[1])];
            }
            // the wide grant keeps the widest level of what it replaces: no grant given for outside data is lost
            $outside = array_filter($exact, static fn(Grant $g): bool => $g->outside) !== [];
            $this->approvals->addGrant($exact[0]->principalId, 'ssh-any:' . $m[1], null, $outside);
            foreach ($exact as $grant) {
                $this->approvals->revokeGrant($grant->id);
            }
            return [sprintf(t('agent.grants.widened'), count($exact), GrantKeys::label('ssh-any:' . $m[1])), $this->view('s.' . $m[1])];
        }
        return null;
    }

    private function revoke(int $id): string {
        return $this->approvals->revokeGrant($id) === null ? t('agent.approval_service.uzhe_otozvano') : t('agent.approval_service.otozvano');
    }

    /**
     * @param list<Grant> $all
     * @return array{string, list<list<Button>>}
     */
    private function summary(array $all): array {
        $lines = [sprintf(t('agent.grants.summary_head'), count($all)), ''];
        $buttons = [];
        foreach (self::GROUPS as $group) {
            $in = array_values(array_filter($all, static fn(Grant $g): bool => self::group($g->key) === $group));
            if ($in === []) {
                continue;
            }
            $extra = '';
            if ($group === 'servers') {
                $servers = array_unique(array_filter(array_map(static fn(Grant $g): ?string => self::server($g->key), $in)));
                $extra = sprintf(t('agent.grants.summary_servers'), count($servers));
            }
            $lines[] = sprintf('%s — %d%s', t('agent.grants.g_' . $group), count($in), $extra);
            $buttons[] = new Button(sprintf('%s (%d)', t('agent.grants.g_' . $group), count($in)), 'pg:l:g.' . $group . ':0');
        }
        $rows = array_chunk($buttons, 2);
        $unused = count($this->select($all, 'u'));
        if ($unused > 0) {
            $lines[] = '';
            $lines[] = sprintf(t('agent.grants.summary_unused'), $unused, self::UNUSED_DAYS);
            $rows[] = [new Button(sprintf('%s (%d)', t('agent.grants.g_unused'), $unused), 'pg:l:u:0')];
        }
        $lines[] = '';
        $lines[] = t('agent.grants.summary_hint');
        return [implode("\n", $lines), $rows];
    }

    /**
     * @param list<Grant> $grants
     * @return array{string, list<list<Button>>}
     */
    private function page(string $selector, array $grants, int $page, int $total): array {
        if ($grants === []) {
            return [$total === 0 ? t('agent.approval_service.no_grants_left') : t('agent.grants.empty'), $selector === '' ? [] : [[new Button(t('agent.grants.btn_summary'), 'pg:l::0')]]];
        }
        $pages = (int) ceil(count($grants) / self::PAGE);
        $page = max(0, min($page, $pages - 1));
        $shown = array_slice($grants, $page * self::PAGE, self::PAGE);
        $several = count(array_unique(array_map(static fn(Grant $g): int => $g->principalId, $this->approvals->grants()))) > 1;
        $lines = [sprintf(t('agent.grants.list_head'), self::title($selector), count($grants)) . ($pages > 1 ? sprintf(t('agent.grants.list_page'), $page + 1, $pages) : ''), ''];
        $revoke = [];
        foreach ($shown as $grant) {
            $lines[] = sprintf('%d. %s%s', $grant->id, $this->describe($grant), $several ? ' — ' . $this->who($grant->principalId) : '');
            $revoke[] = new Button('✖ ' . $grant->id, sprintf('pg:r:%d:%s:%d', $grant->id, $selector, $page));
        }
        $lines[] = '';
        $lines[] = t('agent.grants.list_hint');
        $rows = array_chunk($revoke, 4);
        $nav = [];
        if ($page > 0) {
            $nav[] = new Button(t('agent.grants.btn_back'), sprintf('pg:l:%s:%d', $selector, $page - 1));
        }
        if ($page < $pages - 1) {
            $nav[] = new Button(t('agent.grants.btn_next'), sprintf('pg:l:%s:%d', $selector, $page + 1));
        }
        if ($selector !== '') {
            $nav[] = new Button(t('agent.grants.btn_summary'), 'pg:l::0');
        }
        if ($nav !== []) {
            $rows[] = $nav;
        }
        if ($selector !== '' && count($grants) > 1) {
            $rows[] = [new Button(sprintf(t('agent.grants.btn_revoke_all'), count($grants)), 'pg:a:' . $selector)];
        }
        if (str_starts_with($selector, 's.')) {
            $server = substr($selector, 2);
            $exact = $this->exactOf($server);
            if (count($exact) >= self::WIDEN_FROM) {
                $rows[] = [new Button(mb_substr(sprintf(t('agent.grants.widen_btn'), count($exact)), 0, 60), 'pg:w:' . $server)];
            }
        }
        return [implode("\n", $lines), $rows];
    }

    /** What a grant allows, its term, level and use. */
    private function describe(Grant $grant): string {
        $text = $grant->label !== null && $grant->label !== '' ? $grant->label : GrantKeys::label($grant->key);
        if (preg_match('~^message:(\d+)$~', $grant->key, $m) === 1) {
            $text = sprintf(t('agent.grants.l_message'), $this->who((int) $m[1]));
        }
        $text .= $grant->conversationId === null ? '' : t('agent.grants.list_session');
        $text .= $grant->outside ? t('agent.grants.list_outside') : '';
        if ($grant->uses > 0) {
            return $text . sprintf(t('agent.grants.list_uses'), $grant->uses);
        }
        $days = $this->days($grant);
        return $text . ($days >= self::UNUSED_DAYS ? sprintf(t('agent.grants.unused_mark'), $days) : '');
    }

    private function days(Grant $grant): int {
        return $grant->createdAt === null ? 0 : (int) floor((($this->now)()->getTimestamp() - $grant->createdAt->getTimestamp()) / 86400);
    }

    private function who(int $principalId): string {
        return $this->principals->find($principalId)->displayName ?? ('#' . $principalId);
    }

    /**
     * @param list<Grant> $all
     * @return list<Grant>
     */
    private function select(array $all, string $selector): array {
        $value = substr($selector, 2);
        return array_values(array_filter($all, fn(Grant $g): bool => match (true) {
            $selector === '' => true,
            $selector === 'u' => $g->uses === 0 && $this->days($g) >= self::UNUSED_DAYS,
            str_starts_with($selector, 'g.') => self::group($g->key) === $value,
            str_starts_with($selector, 's.') => self::server($g->key) === $value,
            str_starts_with($selector, 'j.') => $g->key === 'area:job:' . $value || str_starts_with($g->key, 'job:' . $value . '|'),
            str_starts_with($selector, 'p.') => $g->key === 'area:project:' . $value || str_starts_with($g->key, 'project:' . $value . '|'),
            default => false,
        }));
    }

    /**
     * The grants for exact changing commands of a server that one wide grant would replace.
     *
     * @return list<Grant>
     */
    private function exactOf(string $server): array {
        $grants = $this->approvals->grants();
        foreach ($grants as $grant) {
            if ($grant->key === 'ssh-any:' . $server) {
                return [];
            }
        }
        return array_values(array_filter($grants, static fn(Grant $g): bool => $g->conversationId === null && preg_match('~^ssh:v\d+:' . preg_quote($server, '~') . ':~', $g->key) === 1));
    }

    private static function title(string $selector): string {
        $value = substr($selector, 2);
        return match (true) {
            $selector === '' => t('agent.grants.t_all'),
            $selector === 'u' => t('agent.grants.g_unused'),
            str_starts_with($selector, 'g.') => t('agent.grants.g_' . (in_array($value, self::GROUPS, true) ? $value : 'other')),
            str_starts_with($selector, 's.') => sprintf(t('agent.grants.t_server'), $value),
            str_starts_with($selector, 'j.') => sprintf(t('agent.grants.t_job'), $value),
            str_starts_with($selector, 'p.') => sprintf(t('agent.grants.t_project'), $value),
            default => $selector,
        };
    }
}
