<?php

declare(strict_types=1);

/**
 * Prints the measure of the gray zone of sleep on the pair sets of test/Fixtures.
 *
 *   php bin/pairs-bench.php
 *
 * spec: brief/memory.md#серая-зона — the numbers of the table come from here
 */

use OpenCCK\Test\Support\PairBench;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/test/bootstrap.php';

foreach (PairBench::LANGUAGES as $language) {
    $r = PairBench::measure(PairBench::file($language));
    printf(
        "%-3s reached %d/%d related (%.2f), unrelated sent %d/%d, copies settled by code %d, pairs taken in all %d\n",
        $language,
        $r['reached'],
        $r['related'],
        (float) $r['reached'] / (float) max(1, $r['related']),
        $r['sent'],
        $r['unrelated'],
        $r['copies'],
        $r['total'],
    );
    foreach ($r['labels'] as $label => [$hit, $count]) {
        printf("    %-12s %d of %d\n", $label, $hit, $count);
    }
}
