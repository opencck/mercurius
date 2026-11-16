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

    /**
     * Kills the root process, its descendants and every process of their sessions. The command chain execs
     * (prlimit → setpriv → env → setsid → bash), so the session id is usually the root pid itself: that session is
     * killed even after the root has exited and its background jobs were reparented. A few rounds catch processes
     * forked while we were scanning.
     */
    public function killSession(int $rootPid): void {
        $own = posix_getsid(0);
        for ($round = 0; $round < 3; $round++) {
            $table = $this->table();
            $sessions = $rootPid !== $own ? [$rootPid => true] : [];
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
            $killed = 0;
            foreach ($table as $pid => $row) {
                if ($pid !== getmypid() && (isset($sessions[$row['sid']]) || isset($seen[$pid]))) {
                    @posix_kill($pid, self::SIGKILL);
                    $killed++;
                }
            }
            if ($killed === 0) {
                return;
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
