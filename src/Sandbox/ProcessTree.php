<?php

declare(strict_types=1);

namespace OpenCCK\Sandbox;

/**
 * Kills everything a command left behind: the command runs in its own session (setsid), so every process of that
 * session — background jobs and nohup included — dies with it. A process that starts yet another session escapes;
 * there is no cgroup per command.
 *
 * ref: references/hermes-agent/tools/environments/base.py@e96221f:L117 (kill_live_foreground_processes) — hermes kills
 *      registered foreground processes; we kill by session found through /proc
 */
final class ProcessTree {
    private const SIGKILL = 9;

    public function __construct(private readonly string $proc = '/proc') {}

    public function killSession(int $rootPid): void {
        $table = $this->table();
        $own = posix_getsid(0);
        $sessions = [];
        $queue = [$rootPid];
        $seen = [];
        while ($queue !== []) {
            $pid = array_shift($queue);
            if (isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            if (isset($table[$pid]) && $table[$pid]['sid'] !== $own) {
                $sessions[$table[$pid]['sid']] = true;
            }
            foreach ($table as $child => $row) {
                if ($row['ppid'] === $pid) {
                    $queue[] = $child;
                }
            }
        }
        foreach ($table as $pid => $row) {
            if (isset($sessions[$row['sid']]) || (isset($seen[$pid]) && $pid !== $rootPid)) {
                @posix_kill($pid, self::SIGKILL);
            }
        }
    }

    /** @return array<int, array{ppid: int, sid: int}> */
    private function table(): array {
        $table = [];
        $entries = scandir($this->proc);
        foreach ($entries === false ? [] : $entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $stat = @file_get_contents($this->proc . '/' . $entry . '/stat');
            if ($stat === false) {
                continue;
            }
            // pid (comm) state ppid pgrp session ... — comm may contain spaces and parentheses.
            $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
            if (count($fields) < 4) {
                continue;
            }
            $table[(int) $entry] = ['ppid' => (int) $fields[1], 'sid' => (int) $fields[3]];
        }
        return $table;
    }
}
