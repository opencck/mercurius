<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Context;

use Closure;
use DateTimeImmutable;
use Amp\Cancellation;
use Amp\CancelledException;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\Role;
use Mercurius\Llm\Usage;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\App\Agent\Trace\NullEvents;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Agent\TranscriptEntry;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\LlmCallRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use OpenCCK\Domain\Entity\LlmCallRecord;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the context of a run under the threshold of the model's window. First, without a model: old tool results
 * outside the protected tail are cut to 200 characters. If that is not enough, the middle is summarised by the cheap
 * model. Nothing is deleted: shortened texts and the summary live next to the originals.
 *
 * spec: BRIEF.md#контекст
 * ref: references/hermes-agent/agent/context_compressor.py@e96221fa:L3277 (_prune_old_tool_results) — prune long tool
 *      results outside the tail first, without a model; hermes drops the text, we keep 200 characters and the path
 *      of a spilled result
 * ref: references/hermes-agent/agent/context_compressor.py@e96221fa:L4422-L4430 (_align_boundary_backward) — the
 *      tail never starts with a tool result: the boundary moves back to the call that produced it
 */
final class ContextCompactor {
    private const PRUNE_ABOVE = 200;
    /** Seconds to wait after the 1st, 2nd and 3rd failed summary in a row; after the 3rd the stub is used. */
    private const BACKOFF = [60, 300, 900];

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param positive-int $window tokens of the model's window when $windowOf is not given or does not know the model
     * @param non-empty-string $cheapModel model of the summary when $cheapModelOf is not given
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @param (Closure(string): int)|null $windowOf window of a model, from the model catalog
     * @param (Closure(): string)|null $cheapModelOf the model of the cheap role as of now
     */
    public function __construct(
        private readonly LlmClient $llm,
        private readonly TranscriptRepository $transcript,
        private readonly ConversationRepository $conversations,
        private readonly RunRepository $runs,
        private readonly LlmCallRepository $calls,
        private readonly int $window,
        private readonly string $cheapModel,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null,
        private readonly ?Closure $windowOf = null,
        private readonly ?Closure $cheapModelOf = null,
        private readonly AgentEvents $events = new NullEvents(),
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * Compacts the context of the run if its estimate reached the threshold, or always with $force (the provider
     * said the context is too long). True when what the model gets has changed; the run's anchor is reset then.
     *
     * @param positive-int $historyLimit
     */
    public function ensure(Run $run, string $systemPrompt, int $historyLimit, int $estimate, bool $force = false, ?Cancellation $cancellation = null): bool {
        if (!$force && $estimate < ContextBudget::threshold($this->windowFor($run->model))) {
            return false;
        }
        $compaction = $this->conversations->compaction($run->conversationId);
        $entries = $this->entries($run, $historyLimit, $compaction->uptoId);
        $tailStart = self::boundary($entries, ContextBudget::tail($this->windowFor($run->model)));
        $pruned = $this->prune(array_slice($entries, 0, $tailStart));
        if ($pruned) {
            $this->logger->info('Old tool results pruned', ['run' => $run->id]);
            // The anchor counted the old texts: from now on the estimate goes by characters until the next call.
            $this->runs->settleAuxiliary($run->id, 0, 0);
            $entries = $this->entries($run, $historyLimit, $compaction->uptoId);
            if (!$force && $this->estimate($systemPrompt, $this->summaryMessage($run->conversationId), $entries) < ContextBudget::threshold($this->windowFor($run->model))) {
                return true;
            }
        }
        $middle = array_slice($entries, 0, $tailStart);
        if ($middle === []) {
            return $pruned;
        }

        $now = ($this->clock)();
        $cooling = $compaction->cooldownUntil !== null && $now < $compaction->cooldownUntil;
        if ($cooling && !$force && $estimate < ContextBudget::critical($this->windowFor($run->model))) {
            return $pruned;
        }
        $body = null;
        if (!$cooling && $compaction->failures < count(self::BACKOFF)) {
            $body = $this->summarise($run, $compaction->summary, $middle, $cancellation);
            if ($body === null) {
                $failures = $this->conversations->compactionFailed(
                    $run->conversationId,
                    $now->modify(sprintf('+%d seconds', self::BACKOFF[max(0, min($compaction->failures, 2))])),
                );
                $this->logger->warning('Context summary failed', ['run' => $run->id, 'failures' => $failures]);
                if (!$force && $estimate < ContextBudget::critical($this->windowFor($run->model))) {
                    return $pruned;
                }
            }
        }
        $stubbed = $body === null;
        $body ??= $this->stub($compaction->summary, $middle);

        $uptoId = $middle[count($middle) - 1]->id;
        // Only the body is stored; the header and the user messages are added when the context is built.
        $this->conversations->saveSummary($run->conversationId, trim($body), $uptoId);
        $this->runs->settleAuxiliary($run->id, 0, 0);
        $this->logger->info('Context compacted', ['run' => $run->id, 'messages' => count($middle), 'upto' => $uptoId, 'stub' => $stubbed]);
        return true;
    }

    /**
     * The message the model gets in place of the compacted part: the reference-only header, the summary, then the
     * user messages of that part verbatim — the newest first to fit, each at most 2000 characters, all of them at
     * most a tenth of the window; null when nothing was compacted.
     */
    public function summaryMessage(int $conversationId): ?string {
        $compaction = $this->conversations->compaction($conversationId);
        if ($compaction->summary === null) {
            return null;
        }
        $budget = intdiv($this->windowFor($this->conversations->meta($conversationId)?->model) * 4, 10);
        $shown = [];
        $users = $this->transcript->userMessages($conversationId, 0, $compaction->uptoId);
        for ($i = count($users) - 1; $i >= 0; $i--) {
            if ($budget < 100) {
                break;
            }
            // The newest message that does not fit whole is cut to what is left.
            $line = '- ' . self::cut($users[$i], min(2000, $budget - 3));
            $budget -= mb_strlen($line);
            array_unshift($shown, $line);
        }
        $hidden = count($users) - count($shown);
        $list = $shown === [] && $hidden === 0 ? '' : "\n\n" . SummaryPrompt::USERS . "\n"
            . ($hidden > 0 ? sprintf("(ранних сообщений не показано: %d; они сохранены в истории)\n", $hidden) : '')
            . implode("\n", $shown);
        return SummaryPrompt::HEADER . "\n\n" . $compaction->summary . $list;
    }

    /**
     * The cheap model's summary of $middle, updating $previous; null when the call failed or the budget is out.
     *
     * @param list<TranscriptEntry> $middle
     */
    private function summarise(Run $run, ?string $previous, array $middle, ?Cancellation $cancellation): ?string {
        // Masked whole: the earlier summary may carry what a user typed.
        $text = SecretMasker::mask(($previous === null ? '' : "Прежнее резюме (обнови его):\n" . $previous . "\n\n") . "Новая часть переписки:\n" . $this->render($middle));
        $maxTokens = ContextBudget::summaryTokens(ContextBudget::tokens($text), $this->windowFor($run->model));
        $reserved = ContextBudget::tokens(SummaryPrompt::SYSTEM . $text) + $maxTokens;
        if (!$this->runs->reserve($run->id, $reserved)) {
            $this->logger->warning('No budget for a context summary', ['run' => $run->id]);
            return null;
        }
        $model = $this->cheapModelOf !== null ? ($this->cheapModelOf)() : $this->cheapModel;
        $request = new ChatRequest($model, [Message::system(SummaryPrompt::SYSTEM), Message::user($text)], [], $maxTokens);
        $started = microtime(true);
        $usage = new Usage();
        $error = null;
        $response = null;
        try {
            $response = $this->llm->complete($request, $cancellation);
            $usage = $response->usage;
            $summary = trim($response->text);
            return $summary === '' ? null : $summary;
        } catch (CancelledException $e) {
            $error = 'cancelled';
            throw $e;
        } catch (LlmException $e) {
            $error = $e->kind->value;
            return null;
        } catch (Throwable $e) {
            $error = 'unknown';
            $this->logger->error('Context summary call failed', ['run' => $run->id, 'error' => $e::class]);
            return null;
        } finally {
            $this->runs->settleAuxiliary($run->id, $reserved, max(0, $usage->totalTokens()));
            $this->record($run, $model, $error, $started, $usage);
            $this->events->modelCalled($run->id, 'compaction', $request, $response, $error, microtime(true) - $started);
        }
    }

    /**
     * Without a model: what is known for sure — the earlier summary and which tools were called.
     *
     * @param list<TranscriptEntry> $middle
     */
    private function stub(?string $previous, array $middle): string {
        $tools = [];
        foreach ($middle as $entry) {
            foreach ($entry->message->toolCalls as $call) {
                $tools[$call->name] = ($tools[$call->name] ?? 0) + 1;
            }
        }
        $lines = [SummaryPrompt::STUB];
        if ($tools !== []) {
            $lines[] = 'Вызывались инструменты: ' . implode(', ', array_map(static fn(string $name, int $n): string => sprintf('%s ×%d', $name, $n), array_keys($tools), $tools)) . '.';
        }
        if ($previous !== null) {
            $lines[] = "Прежнее резюме:\n" . $previous;
        }
        return implode("\n\n", $lines);
    }

    /** @param list<TranscriptEntry> $entries */
    private function render(array $entries): string {
        $lines = [];
        foreach ($entries as $entry) {
            $message = $entry->message;
            $line = match ($message->role) {
                Role::User => '[собеседник] ' . self::cut($message->content, 4000),
                Role::Tool => sprintf('[результат %s] %s', $entry->toolName ?? 'инструмента', self::cut($message->content, 2000)),
                default => '[агент] ' . self::cut($message->content, 4000),
            };
            foreach ($message->toolCalls as $call) {
                $line .= sprintf("\n[вызов] %s(%s)", $call->name, self::cut($call->argumentsJson, 500));
            }
            $lines[] = $line;
        }
        return SecretMasker::mask(implode("\n\n", $lines));
    }

    /** @param list<TranscriptEntry> $entries */
    private function estimate(string $systemPrompt, ?string $summary, array $entries): int {
        $tokens = ContextBudget::tokens($systemPrompt . ($summary ?? ''));
        foreach ($entries as $entry) {
            $tokens += self::size($entry->message);
        }
        return $tokens;
    }

    private function record(Run $run, string $model, ?string $error, float $started, Usage $usage): void {
        try {
            $this->calls->record(new LlmCallRecord(
                conversationId: $run->conversationId,
                runId: $run->id,
                purpose: 'compaction',
                model: $model,
                finishReason: $error === null ? 'stop' : null,
                errorKind: $error,
                inputTokens: max(0, $usage->inputTokens),
                outputTokens: max(0, $usage->outputTokens),
                cacheReadTokens: max(0, $usage->cacheReadTokens),
                cacheWriteTokens: max(0, $usage->cacheWriteTokens),
                reasoningTokens: max(0, $usage->reasoningTokens),
                costUsd: $usage->costUsd,
                durationMs: max(0, (int) round((microtime(true) - $started) * 1000.0)),
            ));
        } catch (Throwable $e) {
            $this->logger->error('Could not record model usage', ['error' => $e::class]);
        }
    }

    /** @return positive-int */
    private function windowFor(?string $model): int {
        $window = $model !== null && $this->windowOf !== null ? ($this->windowOf)($model) : $this->window;
        return max(1, $window);
    }

    private static function cut(string $text, int $chars): string {
        return mb_strlen($text) > $chars ? mb_substr($text, 0, $chars) . '…' : $text;
    }

    /**
     * Index of the first message of the protected tail: the last messages within $tailTokens, at least one, never
     * starting with a tool result.
     *
     * @param list<TranscriptEntry> $entries
     */
    public static function boundary(array $entries, int $tailTokens): int {
        $start = count($entries);
        $tokens = 0;
        while ($start > 0) {
            $tokens += self::size($entries[$start - 1]->message);
            if ($tokens > $tailTokens && $start < count($entries)) {
                break;
            }
            $start--;
        }
        while ($start > 0 && $start < count($entries) && $entries[$start]->message->role === Role::Tool) {
            $start--;
        }
        return $start;
    }

    /** @return list<TranscriptEntry> */
    private function entries(Run $run, int $historyLimit, int $uptoId): array {
        /** @var positive-int $limit */
        $limit = max(1, $historyLimit);
        return [
            ...$this->transcript->historyEntries($run->conversationId, $run->id, $limit, $uptoId),
            ...$this->transcript->runEntries($run->id, $uptoId),
        ];
    }

    /** @param list<TranscriptEntry> $entries */
    private function prune(array $entries): bool {
        $changed = false;
        foreach ($entries as $entry) {
            $content = $entry->message->content;
            if ($entry->message->role !== Role::Tool || mb_strlen($content) <= self::PRUNE_ABOVE || str_contains($content, '[Старый результат инструмента убран')) {
                continue;
            }
            $path = preg_match('/сохранён: (\S+?)\. Читай/u', $content, $match) === 1 ? sprintf(' Полный — в %s.', $match[1]) : '';
            $this->transcript->compactContent($entry->id, sprintf(
                "%s\n[Старый результат инструмента убран из контекста (%d символов).%s]",
                mb_substr($content, 0, self::PRUNE_ABOVE),
                mb_strlen($content),
                $path,
            ));
            $changed = true;
        }
        return $changed;
    }

    private static function size(Message $message): int {
        $chars = mb_strlen($message->content);
        foreach ($message->toolCalls as $call) {
            $chars += mb_strlen($call->argumentsJson) + mb_strlen($call->name);
        }
        return intdiv($chars, 4);
    }
}
