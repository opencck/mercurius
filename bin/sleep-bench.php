<?php

declare(strict_types=1);

/**
 * Measures the model of sleep with real models: the judge of pairs on the pair sets and the search words on the recall
 * sets of test/Fixtures (search before and after a night of enrichment). Calls are paid; the run stops at the budget.
 *
 *   php bin/sleep-bench.php --models=openrouter:a/b,openrouter:c/d [--part=judge|merge|enrich|all] [--effort=low]
 *                           [--lang=ru,en,zh] [--budget=1.0]
 *
 * Runs where the keys and the route of the agent are: on the server, in the test image.
 *
 * spec: brief/memory.md#судья-пары — the shares of right verdicts and the thresholds come from here
 * spec: brief/memory.md#ночное-обогащение — the gain of search words comes from here
 */

use Amp\Cancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\LlmClient;
use Mercurius\Memory\App\Enrichment;
use Mercurius\Memory\Domain\ContentScreen;
use Mercurius\Memory\Domain\Fact;
use Mercurius\Memory\Domain\TextAnalyzer;
use Mercurius\Memory\Infrastructure\InMemoryMemoryStore;
use OpenCCK\App\Agent\LlmSleepJudge;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\Infrastructure\Llm\ProviderClients;
use OpenCCK\Test\Support\PairBench;
use OpenCCK\Test\Support\RecallBench;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/test/bootstrap.php';

$options = getopt('', ['models:', 'part:', 'effort:', 'lang:', 'budget:']);
$models = array_values(array_filter(array_map('trim', explode(',', (string) ($options['models'] ?? '')))));
$part = (string) ($options['part'] ?? 'all');
$effort = isset($options['effort']) ? (string) $options['effort'] : null;
$languages = array_values(array_filter(explode(',', (string) ($options['lang'] ?? 'ru,en,zh'))));
$budget = (float) ($options['budget'] ?? 1.0);
if ($models === []) {
    fwrite(STDERR, "--models is required\n");
    exit(1);
}

/** @var array<string, string> $env */
$env = getenv();
$settings = LlmSettings::fromEnv($env);
$clients = ProviderClients::build($settings, HttpClients::create($settings->routeUri()), null)->clients;
$llm = new class($clients, $effort) implements LlmClient {
    /** @param array<string, LlmClient> $clients */
    public function __construct(private readonly array $clients, private readonly ?string $effort) {}

    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse {
        $ref = ModelRef::parse($request->model);
        $client = $this->clients[$ref->provider] ?? throw new RuntimeException('no key for ' . $ref->provider);
        $bare = $request->withModel($ref->model);
        return $client->complete($this->effort === null ? $bare : $bare->withReasoningEffort($this->effort), $cancellation);
    }

    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable {
        throw new LogicException('not used');
    }
};

$spentAll = 0.0;
foreach ($models as $model) {
    $judge = new LlmSleepJudge($llm, static fn(): array => [$model], new NullLogger());
    printf("=== %s%s\n", $model, $effort === null ? '' : ' (effort ' . $effort . ')');

    if ($part === 'judge' || $part === 'all') {
        $started = microtime(true);
        [$total, $answered, $right] = [0, 0, 0];
        /** @var array<string, array{int, int}> $labels */
        $labels = [];
        /** @var list<array{float, bool}> $graded confidence, right */
        $graded = [];
        $wrong = [];
        [$calls, $tokens, $cost] = [0, 0, 0.0];
        foreach ($languages as $language) {
            /** @var array{pairs: list<array{a: string, b: string, label: string}>} $set */
            $set = json_decode((string) file_get_contents(PairBench::file($language)), true, 8, JSON_THROW_ON_ERROR);
            $store = new InMemoryMemoryStore();
            $analyzer = new TextAnalyzer();
            $pairs = [];
            foreach ($set['pairs'] as $p) {
                $pairs[] = [
                    $store->addFact('owner', Fact::KNOWLEDGE, $p['a'], $analyzer->keys($p['a']), 0.8, Fact::OWNER_SAID, null),
                    $store->addFact('owner', Fact::KNOWLEDGE, $p['b'], $analyzer->keys($p['b']), 0.8, Fact::OWNER_SAID, null),
                ];
            }
            foreach (array_chunk($pairs, LlmSleepJudge::BATCH, true) as $chunk) {
                if ($spentAll + $cost > $budget) {
                    break 2;
                }
                $verdicts = $judge->judge(array_values($chunk));
                [$c, $t, $d] = $judge->spent();
                [$calls, $tokens, $cost] = [$calls + $c, $tokens + $t, $cost + $d];
                foreach (array_keys($chunk) as $n => $i) {
                    $expected = $set['pairs'][$i]['label'];
                    $total++;
                    $labels[$expected] ??= [0, 0];
                    $labels[$expected][1]++;
                    $verdict = $verdicts[$n] ?? null;
                    if ($verdict === null) {
                        continue;
                    }
                    $answered++;
                    $ok = $verdict->label === $expected;
                    $graded[] = [$verdict->confidence, $ok];
                    if ($ok) {
                        $right++;
                        $labels[$expected][0]++;
                    } else {
                        $wrong[] = sprintf('%s %s→%s %.2f', $language, $expected, $verdict->label, $verdict->confidence);
                    }
                }
            }
        }
        $spentAll += $cost;
        printf("judge: pairs %d, answered %d, right %d (%.3f of all, %.3f of answered); calls %d, tokens %d, \$%.4f, %.0f s\n",
            $total, $answered, $right, $right / max(1, $total), $right / max(1, $answered), $calls, $tokens, $cost, microtime(true) - $started);
        ksort($labels);
        foreach ($labels as $label => [$hit, $count]) {
            printf("    %-12s %d of %d\n", $label, $hit, $count);
        }
        foreach ([0.6, 0.7, 0.85, 0.9] as $threshold) {
            $over = array_filter($graded, static fn(array $g): bool => $g[0] >= $threshold);
            printf("    confidence ≥ %.2f: %d pairs (%.3f of all), right %.3f\n", $threshold, count($over), count($over) / max(1, $total),
                count(array_filter($over, static fn(array $g): bool => $g[1])) / max(1, count($over)));
        }
        printf("    wrong: %s\n", implode('; ', $wrong));
    }

    if ($part === 'merge' || $part === 'all') {
        // spec: brief/memory.md#слияние — the model writes merges of the pairs adding a detail; the next model of the
        //       list checks them and copies spoiled on purpose: a detail dropped, a negation or a number changed
        $started = microtime(true);
        $checker = $models[(array_search($model, $models, true) + 1) % count($models)];
        $verifier = new LlmSleepJudge($llm, static fn(): array => [$model, $checker], new NullLogger());
        [$good, $goodKept, $spoiled, $spoiledCaught, $written, $guarded] = [0, 0, 0, 0, 0, 0];
        $mergeCost = 0.0;
        foreach ($languages as $language) {
            /** @var array{pairs: list<array{a: string, b: string, label: string}>} $set */
            $set = json_decode((string) file_get_contents(PairBench::file($language)), true, 8, JSON_THROW_ON_ERROR);
            $store = new InMemoryMemoryStore();
            $analyzer = new TextAnalyzer();
            $pairs = [];
            foreach ($set['pairs'] as $p) {
                if ($p['label'] === 'adds_detail') {
                    $pairs[] = [
                        $store->addFact('owner', Fact::KNOWLEDGE, $p['a'], $analyzer->keys($p['a']), 0.8, Fact::OWNER_SAID, null),
                        $store->addFact('owner', Fact::KNOWLEDGE, $p['b'], $analyzer->keys($p['b']), 0.8, Fact::OWNER_SAID, null),
                    ];
                }
            }
            if ($spentAll + $mergeCost > $budget || $pairs === []) {
                continue;
            }
            $texts = $verifier->merge($pairs);
            $merges = [];
            foreach ($pairs as $i => [$a, $b]) {
                $text = $texts[$i] ?? null;
                if ($text === null) {
                    continue;
                }
                $written++;
                if (\Mercurius\Memory\App\MergeGuard::problem($a, $b, $text) !== null) {
                    continue;
                }
                $guarded++;
                $merges[] = [$a, $b, $text, true];
                $merges[] = [$a, $b, $a->text, false];
                $negated = preg_match('~\d~u', $text) === 1
                    ? (string) preg_replace_callback('~\d+~u', static fn(array $m): string => (string) ((int) $m[0] + 1), $text, 1)
                    : (string) preg_replace('~^(\S+\s+)~u', '$1' . ($language === 'en' ? 'never ' : ($language === 'zh' ? '不' : 'не ')), $text, 1);
                $merges[] = [$a, $b, $negated, false];
            }
            $checks = $verifier->verify(array_map(static fn(array $m): array => [$m[0], $m[1], $m[2]], $merges));
            foreach ($merges as $i => [, , , $right]) {
                $kept = $checks[$i] ?? null;
                if ($right) {
                    $good++;
                    $goodKept += $kept === true ? 1 : 0;
                } else {
                    $spoiled++;
                    $spoiledCaught += $kept === false ? 1 : 0;
                }
            }
            $mergeCost += $verifier->spent()[2];
        }
        $spentAll += $mergeCost;
        printf("merge (written by %s, checked by %s): written %d, passed the code %d; right texts kept %d of %d; spoiled caught %d of %d; \$%.4f, %.0f s\n",
            $model, $checker, $written, $guarded, $goodKept, $good, $spoiledCaught, $spoiled, $mergeCost, microtime(true) - $started);
    }

    if ($part === 'enrich' || $part === 'all') {
        $started = microtime(true);
        $analyzer = new TextAnalyzer();
        $enrichPaid = 0.0;
        foreach ($languages as $language) {
            if ($spentAll + $enrichPaid > $budget) {
                break;
            }
            $file = RecallBench::file($language);
            $before = RecallBench::measure(0.3, $file);
            $nights = 0;
            $after = RecallBench::measure(0.3, $file, null, static function (InMemoryMemoryStore $store) use ($judge, $analyzer, &$nights, &$enrichPaid, $budget, $spentAll): void {
                $enrichment = new Enrichment($store, $analyzer, new ContentScreen(), $judge, static fn(): bool => false);
                // a night takes 20 facts: nights go on until every fact has its words or the model stops answering
                while ($nights < 20 && $spentAll + $enrichPaid <= $budget) {
                    [$enriched] = $enrichment->run();
                    $nights++;
                    $enrichPaid += $judge->spent()[2];
                    if ($enriched === 0) {
                        break;
                    }
                }
            });
            printf("enrich %s (%d nights): recall@5 %.3f → %.3f, MRR %.3f → %.3f, extra %.2f → %.2f, silence %.3f → %.3f\n",
                $language, $nights, $before['recall'], $after['recall'], $before['mrr'], $after['mrr'], $before['extra'], $after['extra'], $before['silence'], $after['silence']);
            foreach ($after['kinds'] as $kind => [$share, $count]) {
                printf("    %-10s %.3f → %.3f (%d)\n", $kind, $before['kinds'][$kind][0] ?? 0.0, $share, $count);
            }
        }
        $spentAll += $enrichPaid;
        printf("enrich: \$%.4f, %.0f s\n", $enrichPaid, microtime(true) - $started);
    }
}
printf("spent in all: \$%.4f of \$%.2f\n", $spentAll, $budget);
