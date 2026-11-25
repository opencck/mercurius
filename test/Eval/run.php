<?php

declare(strict_types=1);

/**
 * Runs the evaluation set against real models and writes a report.
 *
 *   php test/Eval/run.php --models z-ai/glm-5.3-flash[,другая] [--judge anthropic/claude-...] [--runs 3]
 *       [--tasks src/App/Eval/tasks.json] [--only категория] [--out storage/eval/<время>.json] [--baseline файл]
 *
 * Models go through OpenRouter (OPENROUTER_API_KEY), through VLESS when VLESS_URI is set, as in the app. Calls are
 * paid. The exit code is 1 when a category regressed against the baseline.
 *
 * spec: brief/self-improvement.md#границы
 */

use Dotenv\Dotenv;
use Mercurius\Llm\OpenRouter\OpenRouterClient;
use Mercurius\Llm\RetryingClient;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Infrastructure\Config\LlmSettings;
use OpenCCK\Infrastructure\Http\HttpClients;
use OpenCCK\App\Eval\EvalReport;
use OpenCCK\App\Eval\EvalRunner;
use OpenCCK\App\Eval\EvalTask;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryMemberRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
$options = getopt('', ['models:', 'judge:', 'runs:', 'tasks:', 'only:', 'out:', 'baseline:']);
$option = static fn(string $name): ?string => isset($options[$name]) && is_string($options[$name]) && $options[$name] !== '' ? $options[$name] : null;

$models = array_values(array_filter(explode(',', $option('models') ?? ''), static fn(string $m): bool => $m !== ''));
if ($models === []) {
    fwrite(STDERR, "--models is required\n");
    exit(2);
}
$runs = max(1, (int) ($option('runs') ?? '3'));
$tasks = EvalTask::load($option('tasks') ?? dirname(__DIR__, 2) . '/src/App/Eval/tasks.json');
$only = $option('only');
if ($only !== null) {
    $tasks = array_values(array_filter($tasks, static fn(EvalTask $t): bool => $t->category === $only));
}

$settings = LlmSettings::fromEnv($_ENV);
$key = $settings->openRouterKey() ?? throw new RuntimeException('OPENROUTER_API_KEY is not set');
$llm = new RetryingClient(new OpenRouterClient(HttpClients::create($settings->routeUri()), $key, appName: 'Mercurius eval'));
$judge = $option('judge');
$owner = (new InMemoryMemberRepository())->ensureOwner('telegram', '42', 'Владелец');
$runner = new EvalRunner($llm, $judge === null ? null : $llm, $judge, new InMemoryRunRepository(), new InMemoryTranscriptRepository(), new InMemoryConversationRepository(), new InMemoryLlmCallRepository(), $owner);

$baseline = null;
if (($path = $option('baseline')) !== null) {
    /** @var mixed $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    $baseline = is_array($decoded) ? $decoded : null;
}

$report = ['prompt_version' => SystemPrompt::VERSION, 'judge' => $judge, 'runs' => $runs, 'tasks' => count($tasks), 'at' => date(DATE_ATOM), 'models' => []];
$regressed = false;
foreach ($models as $model) {
    $results = [];
    $tokens = 0;
    foreach ($tasks as $task) {
        for ($i = 1; $i <= $runs; $i++) {
            $result = $runner->runTask($task, $model);
            $results[] = $result;
            $tokens += $result['tokens'];
            fwrite(STDERR, sprintf("%s %s #%d: %.2f%s\n", $model, $task->id, $i, $result['score'], $result['failures'] === [] ? '' : ' — ' . implode('; ', $result['failures'])));
        }
    }
    $categories = EvalReport::categories($results);
    $overall = $results === [] ? 0.0 : round(EvalReport::sum(array_column($results, 'score')) / (float) count($results), 4);
    $entry = ['overall' => $overall, 'tokens' => $tokens, 'categories' => $categories, 'results' => $results];
    /** @var mixed $base */
    $base = null;
    if (is_array($baseline) && isset($baseline['models']) && is_array($baseline['models']) && isset($baseline['models'][$model]) && is_array($baseline['models'][$model])) {
        /** @var mixed $base */
        $base = $baseline['models'][$model]['categories'] ?? null;
    }
    if (is_array($base)) {
        /** @var array<string, array{mean: float, se: float, n: int}> $base */
        $entry['regressions'] = EvalReport::regressions($categories, $base);
        $regressed = $regressed || $entry['regressions'] !== [];
    }
    $report['models'][$model] = $entry;
    fwrite(STDOUT, sprintf("%s: %.2f, %d токенов\n", $model, $overall, $tokens));
    foreach ($categories as $name => $c) {
        fwrite(STDOUT, sprintf("  %-28s %.2f ± %.2f (n=%d)\n", $name, $c['mean'], $c['se'], $c['n']));
    }
    foreach ($entry['regressions'] ?? [] as $line) {
        fwrite(STDOUT, '  УХУДШЕНИЕ ' . $line . "\n");
    }
}

$out = $option('out') ?? dirname(__DIR__, 2) . '/storage/eval/' . date('Ymd-His') . '.json';
@mkdir(dirname($out), 0775, true);
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
fwrite(STDOUT, 'Отчёт: ' . $out . "\n");
exit($regressed ? 1 : 0);
