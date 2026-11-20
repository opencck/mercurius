<?php

declare(strict_types=1);

/**
 * Prints the measure of memory search on the sets of test/Fixtures: per language and on all of them in one memory.
 *
 *   php bin/recall-bench.php [hrr weight ...]
 *
 * spec: brief/memory.md#замер-поиска-на-трёх-языках — the numbers of the table of measures come from here
 */

use OpenCCK\Test\Support\RecallBench;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/test/bootstrap.php';

$weights = array_map('floatval', array_slice($argv, 1)) ?: [0.0, 0.3];
$files = array_values(array_filter(array_map(RecallBench::file(...), RecallBench::LANGUAGES), 'is_file'));
foreach ($weights as $weight) {
    $sets = [];
    foreach ($files as $file) {
        $sets[basename($file, '.json')] = RecallBench::measure($weight, $file);
    }
    if (count($files) > 1) {
        $sets['mixed'] = RecallBench::measure($weight, $files[0], $files);
    }
    foreach ($sets as $name => $r) {
        $started = hrtime(true);
        printf(
            "hrr %.2f %-18s recall@5 %.3f  MRR %.3f  extra %.2f  silence %.3f  (%d answered, %d quiet)\n",
            $weight,
            $name,
            $r['recall'],
            $r['mrr'],
            $r['extra'],
            $r['silence'],
            $r['answered'],
            $r['quiet'],
        );
        foreach ($r['kinds'] as $kind => [$share, $count]) {
            printf("    %-11s %.3f of %d\n", $kind, $share, $count);
        }
    }
}
