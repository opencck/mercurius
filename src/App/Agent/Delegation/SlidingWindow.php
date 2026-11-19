<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Delegation;

use Amp\Future;

use function Amp\async;

/**
 * Runs jobs side by side, at most `width` at once: a slot that frees takes the next job at once (a sliding window, not
 * batches that wait for their slowest). Results keep the keys of the jobs.
 *
 * spec: BRIEF.md#часть-1--синхронное-делегирование — parallel reading parts, up to three at once
 * ref: references/hermes-agent/tools/delegate_tool_dispatch.py@e96221fa:L157-L176 — hermes runs children through a
 *      thread pool of max_children, where a free thread takes the next child; here fibers pull from one queue
 */
final class SlidingWindow {
    /**
     * @template T
     * @param array<int, \Closure(): T> $jobs
     * @param positive-int $width
     * @return array<int, T> by the keys of the jobs, in their order
     */
    public static function run(array $jobs, int $width): array {
        $queue = $jobs;
        $results = [];
        $lanes = [];
        for ($i = 0, $n = min($width, count($jobs)); $i < $n; $i++) {
            $lanes[] = async(static function () use (&$queue, &$results): void {
                // Fibers switch only at an await: taking the next job is never shared by two lanes.
                while (($key = array_key_first($queue)) !== null) {
                    $job = $queue[$key];
                    unset($queue[$key]);
                    $results[$key] = $job();
                }
            });
        }
        Future\await($lanes);
        $ordered = [];
        foreach (array_keys($jobs) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }
}
