<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use OpenCCK\App\Agent\Tool\CanonicalJson;

/**
 * Stops a run that repeats itself: the same call with the same result several times in a row, the same cycle of 2–4
 * calls, or the same call with the same result again and again in any order (the model asks what it already knows, the
 * calls shuffled each turn). Warn from the 3rd repeat, halt at the 5th. A tool that watches the outside world (a
 * command, a page, a neighbour) may see no change many times while it waits: it is counted only in a row and in
 * cycles. Its history is kept on the run: a run resumed after an approval or a restart goes on counting.
 *
 * spec: brief/agent-loop.md#запуск-и-цикл — loop guard
 * ref: references/hermes-agent/agent/tool_guardrails.py@e96221fa:L37-L46, L125-L130 — thresholds 3 and 5, period up to 4
 * ref: references/hermes-agent/agent/tool_guardrails.py@e96221fa:L450-L536 (observe_call, _detect_identical_cycle) —
 *      streak and cycle laps; we skip the separate counters for different failing commands
 * ref: references/hermes-agent/agent/tool_guardrails.py@e96221fa:L380-L446 (after_call, _no_progress) — an unchanged
 *      result of the same call counts even with other calls between; pollers are exempt (an unchanged poll is progress)
 */
final class LoopGuard {
    public const WARN_AT = 3;
    public const HALT_AT = 5;
    public const MAX_PERIOD = 4;
    private const HISTORY = 64;
    private const POLLS = [
        'run_command', 'ssh_run', 'sql_query', 'git', 'http_request', 'tcp_probe', 'browser', 'web_read', 'web_search',
        'peer_call', 'peer_delegate', 'peer_orchestrate', 'peer_links', 'checkpoint',
    ];

    public const WARNING = 'agent.loop_guard.warning';

    private ?string $streakSignature = null;
    private int $streak = 0;
    /** @var list<string> */
    private array $history = [];

    /** @param list<string> $history what the guard of this run saw before, oldest first */
    public function __construct(array $history = []) {
        foreach (array_slice($history, -self::HISTORY) as $signature) {
            $this->observe($signature);
        }
    }

    /** @return list<string> */
    public function history(): array {
        return $this->history;
    }

    /** @param array<string, mixed> $args */
    public static function signature(string $name, array $args, string $result): string {
        return sha1($name . "\0" . CanonicalJson::encode($args) . "\0" . $result);
    }

    /** Tools that wait for the outside world: an unchanged answer is not a loop while it is not in a row. */
    public static function isPoll(string $tool): bool {
        return in_array($tool, self::POLLS, true) || str_starts_with($tool, 'mcp_');
    }

    /** @param bool $poll the call watches the outside world (isPoll): only streaks and cycles count */
    public function observe(string $signature, bool $poll = false): GuardVerdict {
        if ($signature === $this->streakSignature) {
            $this->streak++;
        } else {
            $this->streakSignature = $signature;
            $this->streak = 1;
        }
        $this->history[] = $signature;
        if (count($this->history) > self::HISTORY) {
            array_shift($this->history);
        }

        $count = max($this->streak, $this->cycleLaps(), $poll ? 0 : count(array_keys($this->history, $signature, true)));
        return match (true) {
            $count >= self::HALT_AT => GuardVerdict::Halt,
            $count >= self::WARN_AT => GuardVerdict::Warn,
            default => GuardVerdict::Ok,
        };
    }

    /** Most trailing laps of an identical cycle with period 2..MAX_PERIOD ending at the latest call. */
    private function cycleLaps(): int {
        $history = $this->history;
        $length = count($history);
        for ($period = 2; $period <= self::MAX_PERIOD; $period++) {
            if ($length < $period * self::WARN_AT) {
                continue;
            }
            $lastLap = array_slice($history, -$period);
            $laps = 1;
            while (($base = $length - $period * ($laps + 1)) >= 0 && array_slice($history, $base, $period) === $lastLap) {
                $laps++;
            }
            if ($laps >= self::WARN_AT) {
                return $laps;
            }
        }
        return 0;
    }
}
