<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Improve;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Eval\EvalRunner;
use OpenCCK\App\Eval\EvalTask;
use OpenCCK\App\Improve\PromptGate;
use OpenCCK\Test\Support\InMemoryConversationRepository;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use OpenCCK\Test\Support\InMemoryTranscriptRepository;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * The gate runs the whole set for both versions with the notes in the prompt, and refuses to judge without a judge
 * of another family or with fewer than 20 tasks or 3 runs.
 */
final class PromptGateTest extends AsyncTestCase {
    private ScriptedLlm $agent;

    private function runner(): EvalRunner {
        $this->agent = new ScriptedLlm([]);
        $owner = (new InMemoryPrincipalRepository())->ensureOwner('telegram', '42', 'Владелец');
        return new EvalRunner($this->agent, new ScriptedLlm([]), 'judge/model', new InMemoryRunRepository(), new InMemoryTranscriptRepository(), new InMemoryConversationRepository(), new InMemoryLlmCallRepository(), $owner);
    }

    public function testBothVersionsRunTheWholeSetWithTheirNotes(): void {
        $tasks = EvalTask::load(dirname(__DIR__, 4) . '/resources/eval/tasks.json');
        $gate = new PromptGate($this->runner(), $tasks, 'agent/model', 3, true);

        $verdict = $gate('', 'Отвечай коротко.');

        self::assertTrue($verdict->passed, $verdict->report);
        self::assertGreaterThanOrEqual(count($tasks) * 3 * 2, count($this->agent->requests));
        $withNotes = array_filter($this->agent->requests, static fn($r): bool => str_contains($r->messages[0]->content ?? '', 'Отвечай коротко.'));
        self::assertGreaterThanOrEqual(count($tasks) * 3, count($withNotes), 'the candidate runs with its notes in the system prompt');
        self::assertStringContainsString('agent/model', $verdict->report);
    }

    public function testNoJudgeOrASmallSetIsNotACheck(): void {
        $tasks = EvalTask::load(dirname(__DIR__, 4) . '/resources/eval/tasks.json');

        self::assertFalse((new PromptGate($this->runner(), $tasks, 'agent/model', 3, false))('', 'x')->passed);
        self::assertFalse((new PromptGate($this->runner(), array_slice($tasks, 0, 5), 'agent/model', 3, true))('', 'x')->passed);
        self::assertFalse((new PromptGate($this->runner(), $tasks, 'agent/model', 1, true))('', 'x')->passed);
        self::assertSame([], $this->agent->requests, 'nothing is spent');
    }
}
