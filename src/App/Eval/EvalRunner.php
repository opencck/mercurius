<?php

declare(strict_types=1);

namespace OpenCCK\App\Eval;

use Amp\NullCancellation;
use DateTimeImmutable;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use OpenCCK\App\Agent\AgentLoop;
use OpenCCK\App\Agent\PromptBuilder;
use OpenCCK\App\Agent\SilentObserver;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\LlmCallRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Throwable;

/**
 * Runs the evaluation set: each task in a fresh agent with its own fake tools, through the real loop and prompt,
 * then checks by code and a judge of another model family. The stores come from outside: in memory for the command
 * line, the database of the app when the owner's acceptance of a prompt change runs the set (each task in a
 * conversation of its own, channel `eval`).
 *
 * spec: brief/self-improvement.md#границы — at least 20 tasks by categories, at least 3 runs, a judge of another
 *       family, the prompt version recorded
 * Basis: notes/claude-cookbooks.md — scores by category, the prompt version recorded with each result.
 */
final class EvalRunner {
    private const JUDGE_PROMPT = 'Ты — строгий проверяющий ответов агента. Оцени, насколько агент выполнил задачу по критериям. '
        . 'Учитывай вызовы инструментов: действие засчитывается, только если был вызов. Ответь одной строкой JSON: '
        . '{"score": <целое 0–10>, "reason": "<кратко>"}.';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly ?LlmClient $judgeLlm,
        private readonly ?string $judgeModel,
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly LlmCallRepository $calls,
        /** the owner the tasks run as */
        private readonly Principal $owner,
        /** a judge of another kind (a judging service); null — the judge model, when set */
        private readonly ?EvalJudge $judge = null,
    ) {}

    /**
     * @return array{task: string, category: string, score: float, checks: ?float, judge: ?float, tokens: int, calls: list<string>, answer: string, failures: list<string>, reason: string}
     */
    public function runTask(EvalTask $task, string $model, string $promptNotes = ''): array {
        $registry = new ToolRegistry();
        foreach ($task->tools as $tool) {
            $registry->register(new EvalTool(
                $tool['name'],
                $tool['description'],
                DangerClass::tryFrom($tool['danger']) ?? DangerClass::Irreversible,
                $tool['parameters'],
                $tool['result'],
                $tool['error'],
                $tool['untrusted'],
            ));
        }
        $runs = $this->runs;
        $transcript = $this->transcript;
        $conversations = $this->conversations;
        $loop = new AgentLoop($this->llm, $runs, $transcript, $conversations, $this->calls, $registry, new ToolExecutor(), 2000, null, 10);
        $owner = $this->owner;
        $limits = new RunLimits(8, 80_000, 240, 30);
        // a conversation of its own every time: no history of an earlier run of the task gets in
        $chat = $task->id . '-' . bin2hex(random_bytes(4));
        $conversation = $conversations->openConversation($owner->id, 'eval', $chat);
        $conversations->setMeta($conversation, PromptBuilder::system($owner, new DateTimeImmutable(), notes: $promptNotes), $model, SystemPrompt::VERSION);
        $run = $runs->start($conversation, $owner->id, $model, $limits) ?? throw new \RuntimeException('run did not start');
        $transcript->appendUser($conversation, $run->id, $task->prompt);
        try {
            $final = $loop->run($run, new ParticipantPolicy(null, $limits, null, false), $owner, new SilentObserver(), new NullCancellation());
            $conversations->close('eval', $chat);
        } catch (Throwable $e) {
            return ['task' => $task->id, 'category' => $task->category, 'score' => 0.0, 'checks' => 0.0, 'judge' => null, 'tokens' => 0, 'calls' => [], 'answer' => '', 'failures' => ['run crashed: ' . $e::class], 'reason' => ''];
        }

        $calls = [];
        $args = [];
        $answer = '';
        $trace = [];
        foreach ($transcript->runMessages($final->id) as $message) {
            if ($message->role === Role::Assistant) {
                foreach ($message->toolCalls as $call) {
                    $calls[] = $call->name;
                    $args[$call->name] = ($args[$call->name] ?? '') . $call->argumentsJson;
                    $trace[] = sprintf('вызов %s %s', $call->name, mb_substr($call->argumentsJson, 0, 300));
                }
                if ($message->toolCalls === [] && trim($message->content) !== '') {
                    $answer = trim($message->content);
                }
            } elseif ($message->role === Role::Tool) {
                $trace[] = 'результат: ' . mb_substr($message->content, 0, 1500);
            }
        }

        [$checks, $failures] = self::check($task, $calls, $args, $answer);
        $spent = $runs->find($final->id);
        [$judge, $reason] = $this->judge($task, $trace, $answer);
        $parts = array_values(array_filter([$checks, $judge], static fn(?float $v): bool => $v !== null));
        return [
            'task' => $task->id,
            'category' => $task->category,
            'score' => $parts === [] ? 0.0 : EvalReport::sum($parts) / (float) count($parts),
            'checks' => $checks,
            'judge' => $judge,
            'tokens' => $spent === null ? 0 : $spent->tokensUsed,
            'calls' => $calls,
            'answer' => mb_substr($answer, 0, 1000),
            'failures' => $failures,
            'reason' => $reason,
        ];
    }

    /**
     * @param list<string> $calls
     * @param array<string, string> $args
     * @return array{?float, list<string>} the share of checks passed (null — no checks) and what failed
     */
    public static function check(EvalTask $task, array $calls, array $args, string $answer): array {
        $results = [];
        $failures = [];
        $lower = mb_strtolower($answer);
        foreach ($task->called as $tool) {
            $results[] = $ok = in_array($tool, $calls, true);
            $ok || $failures[] = 'не вызван ' . $tool;
        }
        foreach ($task->notCalled as $tool) {
            $results[] = $ok = !in_array($tool, $calls, true);
            $ok || $failures[] = 'вызван ' . $tool;
        }
        if ($task->answerAny !== []) {
            $results[] = $ok = array_filter($task->answerAny, static fn(string $s): bool => str_contains($lower, mb_strtolower($s))) !== [];
            $ok || $failures[] = 'в ответе нет ни одного из: ' . implode(', ', $task->answerAny);
        }
        foreach ($task->answerNone as $s) {
            $results[] = $ok = !str_contains($lower, mb_strtolower($s));
            $ok || $failures[] = 'в ответе есть «' . $s . '»';
        }
        foreach ($task->argsContain as $tool => $needle) {
            $results[] = $ok = str_contains(mb_strtolower($args[$tool] ?? ''), mb_strtolower($needle));
            $ok || $failures[] = sprintf('в аргументах %s нет «%s»', $tool, $needle);
        }
        return [$results === [] ? null : (float) count(array_filter($results)) / (float) count($results), $failures];
    }

    /**
     * @param list<string> $trace
     * @return array{?float, string}
     */
    private function judge(EvalTask $task, array $trace, string $answer): array {
        if ($this->judge !== null) {
            return $this->judge->verdict($task, $trace, $answer);
        }
        if ($this->judgeLlm === null || $this->judgeModel === null || $task->rubric === '') {
            return [null, ''];
        }
        $text = sprintf(
            "Задача пользователя:\n%s\n\nКритерии:\n%s\n\nДействия агента:\n%s\n\nИтоговый ответ агента:\n%s",
            $task->prompt,
            $task->rubric,
            $trace === [] ? '(инструменты не вызывались)' : implode("\n", $trace),
            $answer === '' ? '(ответа нет)' : $answer,
        );
        try {
            $response = $this->judgeLlm->complete(new ChatRequest($this->judgeModel, [Message::system(self::JUDGE_PROMPT), Message::user($text)], maxTokens: 400, temperature: 0.0));
        } catch (Throwable $e) {
            return [null, 'судья недоступен: ' . $e::class];
        }
        return self::parseVerdict($response->text);
    }

    /** @return array{?float, string} */
    public static function parseVerdict(string $text): array {
        if (preg_match('~\{.*\}~s', $text, $m) !== 1) {
            return [null, 'судья ответил не JSON'];
        }
        try {
            /** @var mixed $data */
            $data = json_decode($m[0], true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, 'судья ответил не JSON'];
        }
        if (!is_array($data) || !isset($data['score']) || !is_numeric($data['score'])) {
            return [null, 'нет оценки'];
        }
        return [max(0.0, min(10.0, (float) $data['score'])) / 10.0, isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : ''];
    }
}
