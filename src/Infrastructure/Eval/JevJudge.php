<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Eval;

use Amp\CancelledException;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use OpenCCK\App\Eval\EvalJudge;
use OpenCCK\App\Eval\EvalTask;
use Throwable;

/**
 * The judge of Jev (jev-ai.pro): a service made for judging, asked one choice question over the task, its criteria,
 * the agent's actions and answer. The grades are weighed by their probabilities into a score 0..1.
 *
 * spec: brief/self-improvement.md#границы — the judge may be of any provider
 * Not in the references: hermes judges by a chat model only; the API is by its documentation (jev-ai.pro/docs,
 * read 2026-11-19): POST /api/v1/systemone, a choice question of options with descriptions, an answer with the choice,
 * the probabilities of the options and the confidence.
 */
final class JevJudge implements EvalJudge {
    public const BASE = 'https://jev-ai.pro/api';
    /** grade => [its weight, its description] */
    private const GRADES = [
        'complete' => [1.0, 'The agent did everything the task asked, by the criteria; every action was a real tool call.'],
        'mostly' => [0.75, 'The task is done with small gaps or slips that do not change the result.'],
        'partial' => [0.5, 'Part of the task is done; something the criteria ask for is missing.'],
        'poor' => [0.25, 'Little of the task is done, or the answer is mostly wrong.'],
        'failed' => [0.0, 'The task is not done: no answer, a wrong one, or actions claimed but not called.'],
    ];

    public function __construct(
        private readonly HttpClient $http,
        #[\SensitiveParameter] private readonly string $key,
        private readonly string $model = 'jev-latest',
        private readonly string $base = self::BASE,
    ) {}

    public function verdict(EvalTask $task, array $trace, string $answer): array {
        if ($task->rubric === '') {
            return [null, ''];
        }
        $body = [
            'model' => $this->model,
            'state' => [
                'task' => $task->prompt,
                'criteria' => $task->rubric,
                'actions' => $trace,
                'answer' => $answer,
            ],
            'questions' => [
                'quality' => [
                    'type' => 'choice',
                    'instructions' => 'How well did the agent do the task by the criteria? An action counts only if it was a tool call in the actions.',
                    'criteria' => array_map(static fn(array $g): string => $g[1], self::GRADES),
                ],
            ],
        ];
        $request = new Request($this->base . '/v1/systemone', 'POST', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $request->setHeader('authorization', 'Bearer ' . $this->key);
        $request->setHeader('content-type', 'application/json');
        $request->setTransferTimeout(60);
        try {
            $response = $this->http->request($request);
            /** @var mixed $data */
            $data = json_decode($response->getBody()->buffer(), true, 16);
        } catch (CancelledException $e) {
            throw $e;
        } catch (Throwable $e) {
            return [null, 'судья Jev недоступен: ' . $e::class];
        }
        if ($response->getStatus() !== 200 || !is_array($data)) {
            return [null, sprintf('судья Jev ответил HTTP %d', $response->getStatus())];
        }
        return self::score($data);
    }

    /**
     * The expected weight of the grades by their probabilities; the chosen grade when there are none.
     *
     * @param array<array-key, mixed> $data
     * @return array{?float, string}
     */
    public static function score(array $data): array {
        $choice = self::dig($data, 'answers', 'quality', 'choice');
        if (!is_string($choice) || !isset(self::GRADES[$choice])) {
            return [null, 'судья Jev не дал оценки'];
        }
        $sum = 0.0;
        $weight = 0.0;
        foreach (self::GRADES as $grade => [$value]) {
            /** @var mixed $p */
            $p = self::dig($data, 'answers', 'quality', 'probabilities', $grade);
            if (is_int($p) || is_float($p)) {
                $sum += (float) $p;
                $weight += (float) $p * $value;
            }
        }
        $score = $sum > 0.0 ? $weight / $sum : self::GRADES[$choice][0];
        /** @var mixed $confidence */
        $confidence = self::dig($data, 'answers', 'quality', 'confidence');
        return [round($score, 3), sprintf('Jev: %s%s', $choice, is_int($confidence) || is_float($confidence) ? sprintf(' (уверенность %.2f)', $confidence) : '')];
    }

    /** A value deep in decoded JSON; null — not there. */
    private static function dig(mixed $data, string ...$path): mixed {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            /** @var mixed $data */
            $data = $data[$key];
        }
        return $data;
    }
}
