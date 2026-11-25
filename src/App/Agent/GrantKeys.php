<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\WideGrant;

use function Mercurius\I18n\t;

/**
 * The keys of the grants that let a call run. Every call has one: its own (the action and its target), else "any
 * call of this tool". Next to it may stand a wider key of the tool and the keys of the areas the run works in
 * (a job, a project): a grant for any of them is enough.
 *
 * spec: brief/agent-loop.md#разрешения-навсегда — everything the owner is asked may be allowed for good
 */
final class GrantKeys {
    /** Keys of what stays apart: a wider grant or a grant of an area never covers them. */
    private const STRICT = ['ssh-never:', 'ssh-sudo:', 'file-write-never:', self::OWNERS_AREA];
    /** a call in the owners' area of the sandbox: it reaches every member's folder, only its own grant lets it run */
    public const OWNERS_AREA = 'owners-area:';

    /**
     * @param string $exact the key of this call
     * @param array{string, string}|null $wide a wider key of the tool and what it allows
     * @param bool $strict only the exact key lets the call run
     */
    private function __construct(
        public readonly string $exact,
        public readonly ?array $wide,
        public readonly bool $strict,
    ) {}

    /** @param array<string, mixed> $args */
    public static function of(?Tool $tool, array $args, string $name): self {
        $own = $tool instanceof GrantKeyed ? $tool->grantKey($args) : null;
        $strict = $own !== null && self::strict($own);
        $wide = $strict || !$tool instanceof WideGrant ? null : $tool->wideGrant($args);
        return new self($own ?? self::any($name), $wide !== null && $wide[0] !== $own ? $wide : null, $strict);
    }

    /** The grant for any call of a tool. */
    public static function any(string $tool): string {
        return 'tool:' . $tool;
    }

    public static function strict(string $key): bool {
        foreach (self::STRICT as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every key a saved grant may have to let the call run.
     *
     * @param list<string> $areas the areas the run works in: "job:5", "project:8"
     * @return list<string>
     */
    public function candidates(string $tool, array $areas): array {
        $keys = [$this->exact];
        if ($this->strict) {
            foreach ($areas as $area) {
                $keys[] = $area . '|' . $this->exact;
            }
            return $keys;
        }
        if ($this->wide !== null) {
            $keys[] = $this->wide[0];
        }
        $keys[] = self::any($tool);
        foreach ($areas as $area) {
            $keys[] = 'area:' . $area;
            $keys[] = $area . '|' . $this->exact;
            if ($this->wide !== null) {
                $keys[] = $area . '|' . $this->wide[0];
            }
        }
        return array_values(array_unique($keys));
    }

    /** What a key allows, in words, for a button and the list of grants. */
    public static function label(string $key): string {
        if (str_starts_with($key, self::OWNERS_AREA)) {
            return sprintf(t('agent.grants.in_owners_area'), self::label(substr($key, strlen(self::OWNERS_AREA))));
        }
        if (str_starts_with($key, 'tool:')) {
            return sprintf(t('agent.grants.any_call'), substr($key, 5));
        }
        if (str_starts_with($key, 'area:')) {
            return sprintf(t('agent.grants.area'), substr($key, 5));
        }
        // a grant of one action inside an area: "job:5|<key>"
        if (preg_match('~^((?:job|project):\d+)\|(.+)$~s', $key, $m) === 1) {
            return sprintf(t('agent.grants.l_in_area'), $m[1], self::label($m[2]));
        }
        return match (true) {
            preg_match('~^ssh-any:(.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.wide_ssh'), $m[1]),
            preg_match('~^ssh(?:-never|-sudo)?:v\d+:([^:]+):~', $key, $m) === 1 => sprintf(t('agent.grants.l_ssh'), $m[1]),
            preg_match('~^model-role-any:(.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.wide_model'), $m[1]),
            preg_match('~^model-role:([^:]+):(.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.l_model'), $m[1], $m[2]),
            $key === 'settings-any' => t('agent.grants.wide_settings'),
            $key === 'settings:restart' => t('agent.grants.l_restart'),
            preg_match('~^settings:set:(.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.l_setting'), $m[1]),
            preg_match('~^http-any:(.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.wide_http'), $m[1]),
            preg_match('~^http:(\S+) (.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.l_http'), $m[1], $m[2]),
            preg_match('~^http-secret:(\S+) (.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.l_http_secret'), $m[1], $m[2]),
            preg_match('~^server-add:(.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.l_server_add'), $m[1]),
            preg_match('~^tool-grant:(.+)$~', $key, $m) === 1 => sprintf(t('agent.grants.l_tool_grant'), $m[1]),
            default => $key,
        };
    }
}
