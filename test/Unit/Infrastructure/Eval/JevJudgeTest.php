<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Eval;

use Amp\Http\Client\HttpClient;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Eval\EvalTask;
use OpenCCK\Infrastructure\Eval\JevJudge;
use OpenCCK\Test\Support\FakeHttpClient;

/**
 * Jev judges by one choice question; the grades are weighed by their probabilities.
 *
 * spec: brief/self-improvement.md#границы — the judge may be of any provider
 */
final class JevJudgeTest extends AsyncTestCase {
    private static function task(string $rubric = 'Агент вызвал send_message'): EvalTask {
        return EvalTask::fromArray(['id' => 't1', 'category' => 'messages', 'prompt' => 'Напиши Анне «привет»', 'rubric' => $rubric]);
    }

    public function testTheTaskGoesAsStateAndTheGradesAreWeighed(): void {
        $http = new FakeHttpClient(['https://jev-ai.pro/api/v1/systemone' => [200, [], json_encode([
            'model' => 'jev-latest',
            'answers' => ['quality' => ['type' => 'choice', 'choice' => 'complete', 'probabilities' => ['complete' => 0.8, 'mostly' => 0.2], 'confidence' => 0.8]],
        ], JSON_THROW_ON_ERROR)]]);
        $judge = new JevJudge(new HttpClient($http, []), 'jev-key');

        [$score, $reason] = $judge->verdict(self::task(), ['send_message {"to":"Анна"}'], 'Отправил.');

        self::assertSame(0.95, $score);
        self::assertStringContainsString('complete', $reason);
        self::assertSame('Bearer jev-key', $http->headers[0]['authorization'] ?? null);
        /** @var array{model: string, state: array{actions: list<string>}, questions: array{quality: array{type: string, criteria: array<string, string>}}} $body */
        $body = json_decode($http->bodies[0], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('jev-latest', $body['model']);
        self::assertSame(['send_message {"to":"Анна"}'], $body['state']['actions']);
        self::assertSame('choice', $body['questions']['quality']['type']);
        self::assertSame(['complete', 'mostly', 'partial', 'poor', 'failed'], array_keys($body['questions']['quality']['criteria']));
    }

    public function testNoRubricNoCallAndAnErrorIsNoVerdict(): void {
        $http = new FakeHttpClient(['https://jev-ai.pro/api/v1/systemone' => [402, [], '{"error":{"code":"balance","message":"no credits"}}']]);
        $judge = new JevJudge(new HttpClient($http, []), 'jev-key');

        self::assertSame([null, ''], $judge->verdict(self::task(''), [], ''));
        self::assertSame([], $http->bodies);
        [$score, $reason] = $judge->verdict(self::task(), [], '');
        self::assertNull($score);
        self::assertStringContainsString('402', $reason);
    }
}
