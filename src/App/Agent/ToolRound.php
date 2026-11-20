<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Closure;
use OpenCCK\App\Agent\Tool\BatchPlanner;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\LongRunning;
use OpenCCK\App\Agent\Tool\PreparesCall;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\ResultBudget;
use OpenCCK\App\Agent\Tool\RequestsAccess;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolExecutor;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Agent\Tool\ValidatedCall;
use OpenCCK\App\Agent\Trace\AgentEvents;
use OpenCCK\Domain\Agent\ApprovalStatus;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\RunStatus;
use OpenCCK\Domain\Agent\StopReason;
use OpenCCK\Domain\Agent\StoredToolCall;
use OpenCCK\Domain\Agent\ToolCallStatus;
use OpenCCK\Domain\Repository\ApprovalRepository;
use OpenCCK\Domain\Repository\RunRepository;
use OpenCCK\Domain\Repository\TranscriptRepository;
use Psr\Log\LoggerInterface;
use Throwable;
use function Amp\async;
use function Amp\Future\await;
use function Mercurius\I18n\t;

/**
 * One round of tool calls of a run: arguments prepared, the answer with its calls saved before anything runs, then
 * segments in order (parallel reads, writes one by one), each irreversible call admitted by a grant, a card or a refusal;
 * results fitted into the window budget, checked by the loop guard and written in the order of the calls.
 *
 * spec: brief/agent-loop.md#запуск-и-цикл
 */
final class ToolRound {
    private const WARN_SHARE = 0.8;
    /** results shorter than this are kept whole even when repeated: a reference would not be shorter */
    private const SAME_RESULT_MIN = 500;
    private readonly BatchPlanner $planner;

    /** @param Closure(): float $clock */
    public function __construct(
        private readonly RunRepository $runs,
        private readonly TranscriptRepository $transcript,
        private readonly ToolExecutor $executor,
        private readonly ResultBudget $budget,
        private readonly Closure $clock,
        private readonly LoggerInterface $logger,
        private readonly ?ApprovalRepository $approvals,
        private readonly ?ApprovalGate $gate,
        private readonly AgentEvents $events,
    ) {
        $this->planner = new BatchPlanner();
    }

    /**
     * Tools that complete their arguments do it before the call is saved, so the card, the grant and the run all see
     * the same completed call; a call that cannot be completed gets its reason as an error.
     *
     * spec: brief/internet.md#скрипты-браузера
     *
     * @param list<ValidatedCall> $calls
     * @return list<ValidatedCall>
     */
    public function prepare(RunState $state, array $calls): array {
        return array_map(function (ValidatedCall $call) use ($state): ValidatedCall {
            if (!$call->executable() || !$call->tool instanceof PreparesCall || $call->args === null) {
                return $call;
            }
            try {
                $prepared = $call->tool->prepare($call->args, $state->principal, $state->policy, $state->cancellation);
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (Throwable $e) {
                $prepared = $e::class;
            }
            return is_string($prepared)
                ? new ValidatedCall($call->call, $call->tool, $call->args, $prepared, $call->danger, $call->wire)
                : new ValidatedCall($call->call, $call->tool, $prepared, null, $call->tool->danger($prepared), $call->wire);
        }, $calls);
    }
    /** @param list<ValidatedCall> $calls */
    public function persist(RunState $state, string $text, array $calls): bool {
        $stored = [];
        foreach ($calls as $position => $call) {
            // spec: brief/skills-mcp.md#mcp-ресурсы-и-поиск-инструментов — a call through tool_call is saved as the model made it, the history stays its own
            $wire = $call->wire ?? $call->call;
            $stored[] = new StoredToolCall(
                $call->call->id,
                $position,
                $wire->name,
                $call->args,
                $wire->argumentsJson,
                $call->danger,
                ToolCallStatus::Pending,
            );
        }
        try {
            $this->transcript->appendAssistant($state->run->conversationId, $state->run->id, $text, $stored);
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Could not save the model answer; no tool runs', ['run' => $state->run->id, 'error' => $e::class]);
            return false;
        }
    }
    /**
     * Segments in order: parallel reads, writes one by one. Results are written in the original order as each
     * segment ends; the loop guard and the 80% warning ride on the last result of the answer.
     *
     * @param list<ValidatedCall> $calls
     */
    public function execute(RunState $state, array $calls): ?StopReason {
        $lastId = $calls === [] ? null : $calls[array_key_last($calls)]->call->id;
        $halted = false;
        $warn = false;
        $turnUsed = 0;
        foreach ($this->planner->segments($calls) as $segment) {
            if ($halted) {
                foreach ($segment as $call) {
                    $this->writeResult($state, $call, ToolResult::error(t('agent.agent_loop.ne_vypolneno_zapusk_ostanovlen_potomu')));
                }
                continue;
            }

            // An irreversible call is alone in its segment (BatchPlanner): ask, use a grant, or refuse first.
            $admission = $this->admit($state, $segment[0]);
            if ($admission === false) {
                $state->paused = true;
                return null;
            }
            $futures = [];
            foreach ($segment as $call) {
                $futures[] = $admission instanceof ToolResult
                    ? \Amp\Future::complete($admission)
                    : async(fn(): ToolResult => $this->runCall($state, $call, $admission === 'granted' || $admission === 'approved'));
            }
            /** @var array<int, ToolResult> $results */
            $results = await($futures);
            // spec: brief/agent-loop.md#контекст — the results of a turn take at most 30% of the window:
            //       the largest are spilled to a file (else cut) until the turn fits
            $room = new ResultBudget($this->budget->perResult, max(1, $this->budget->perTurn - $turnUsed));
            $contents = array_map(static fn(ToolResult $r): string => $r->content, $results);
            $fitted = $room->fit($contents, fn(string $content, int $index): string => $this->executor->shrink($content, $this->contextOf($state, $segment[$index])));
            foreach ($fitted as $index => $content) {
                $result = $results[$index] ?? null;
                if ($result instanceof ToolResult && $content !== $result->content) {
                    $results[$index] = new ToolResult($content, $result->isError);
                }
                $turnUsed += mb_strlen($content);
            }
            if ($admission === 'granted') {
                $first = $results[0] ?? null;
                if ($first instanceof ToolResult) {
                    $results[0] = new ToolResult($first->content . t('agent.agent_loop.vypolneno_po_sohranennomu_razresheniyu_v'), $first->isError);
                }
            }
            foreach ($segment as $index => $call) {
                $result = $results[$index] ?? null;
                if (!$result instanceof ToolResult) {
                    throw new \LogicException('A tool call produced no result');
                }
                $verdict = $call->args === null ? GuardVerdict::Ok : $state->guard->observe(LoopGuard::signature($call->call->name, $call->args, $result->content), LoopGuard::isPoll($call->call->name));
                $halted = $halted || $verdict === GuardVerdict::Halt;
                $warn = $warn || $verdict === GuardVerdict::Warn;
                // spec: brief/agent-loop.md#контекст — the same long result again is a reference to the first
                if (!$result->isError && mb_strlen($result->content) >= self::SAME_RESULT_MIN) {
                    $hash = hash('sha256', $result->content);
                    $first = $state->seenResults[$hash] ?? null;
                    if ($first === null) {
                        $state->seenResults[$hash] = $call->call->id;
                    } else {
                        $result = new ToolResult(sprintf(t('agent.agent_loop.same_result'), $first), false);
                    }
                }
                if ($call->call->id === $lastId) {
                    $result = $this->withNotes($state, $result, $warn || $halted);
                }
                $this->writeResult($state, $call, $result);
            }
        }
        // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — the loop guard of a run outlives a restart
        $this->runs->saveLoopHistory($state->run->id, $state->guard->history());
        return $halted ? StopReason::LoopGuard : null;
    }
    /**
     * Whether a call may run now. Only irreversible calls are checked.
     *
     * spec: brief/agent-loop.md#разрешения-навсегда — approval, saved grants, nobody to ask means refusal
     * ref: references/hermes-agent/tools/approval.py@e96221fa:L1421-L1479 (check_all_command_guards) — guards in order:
     *      saved permissions, then asking a person; with nobody to ask (cron, API) the answer is an immediate denial
     *
     * @return bool|'granted'|'approved'|ToolResult true — run; 'granted' — run by a saved grant; 'approved' — run, the owner
     *         pressed "allow"; false — paused for the owner;
     *         ToolResult — do not run, this is the result
     */
    private function admit(RunState $state, ValidatedCall $call): bool|string|ToolResult {
        // spec: brief/orchestration.md#часть-4--проверяющий-и-данные-трасс — the verifier reads; no grant lets it write
        if ($state->policy->readOnly && $call->executable() && $call->danger !== DangerClass::Read) {
            return ToolResult::error(t('agent.agent_loop.zdes_dostupno_tolko_chtenie_etot'));
        }
        // spec: brief/orchestration.md#часть-1--синхронное-делегирование — a worker gets no irreversible action, a saved grant included
        if (!$state->policy->irreversible && $call->executable() && $call->danger === DangerClass::Irreversible) {
            return ToolResult::error(t('agent.agent_loop.vorkeru_neobratimye_deystviya_nedostupny'));
        }
        if (!$call->executable() || $call->danger !== DangerClass::Irreversible || $call->tool === null || $call->args === null) {
            return true;
        }
        $approval = $this->approvals?->forCall($state->run->id, $call->call->id);
        if ($approval !== null) {
            return match ($approval->status) {
                ApprovalStatus::Approved => 'approved',
                ApprovalStatus::Pending => false,
                ApprovalStatus::Denied => ToolResult::error(t('agent.agent_loop.otkazano_vladeltsem') . ($approval->reason !== null ? ': ' . $approval->reason : '.') . t('agent.agent_loop.deystvie_ne_vypolneno')),
                ApprovalStatus::Expired => ToolResult::error(t('agent.agent_loop.vladelets_ne_otvetil_vovremya_molchanie')),
            };
        }
        if ($call->tool instanceof Tool\ChecksBeforeApproval) {
            $refused = $call->tool->refuseEarly($call->args, $this->contextOf($state, $call));
            if ($refused !== null) {
                return $refused;
            }
        }
        $key = $call->tool instanceof GrantKeyed ? $call->tool->grantKey($call->args) : null;
        // spec: brief/agent-loop.md#запуск-начатый-внешними-данными — after outside data a grant is not used: the text read may be someone's
        //      instructions, so the owner sees every irreversible action of this run on a card
        $outside = $this->runs->find($state->run->id)?->outside;
        if ($key !== null && $outside === null && $this->approvals?->hasGrant($state->principal->id, $key) === true) {
            $this->logger->info('Irreversible call allowed by a saved grant', ['run' => $state->run->id, 'tool' => $call->call->name, 'key' => $key]);
            return 'granted';
        }
        // spec: brief/network.md#участник-просит-инструмент — asking for a tool reaches the owner always
        if ($this->gate === null || $this->approvals === null) {
            return ToolResult::error(t('agent.agent_loop.deystvie_trebuet_podtverzhdeniya_vladelt'));
        }
        // spec: brief/agent-loop.md#права-участника — the refusal names the missing right and who gives it, so the model
        //      reports it to the participant instead of trying again
        if (!$state->policy->mayRequestApproval && !$call->tool instanceof RequestsAccess) {
            return ToolResult::error(t('agent.agent_loop.net_prava_prosit_podtverzhdenie'));
        }
        // Saved as waiting before the owner sees the card: a press that comes at once must find the run waiting.
        $this->transcript->setCallStatus($state->run->id, $call->call->id, ToolCallStatus::AwaitingApproval);
        $this->runs->setStatus($state->run->id, RunStatus::WaitingApproval);
        $this->gate->request($this->runs->find($state->run->id) ?? $state->run, $call, $state->principal);
        return false;
    }
    private function runCall(RunState $state, ValidatedCall $call, bool $approved = false): ToolResult {
        if (!$call->executable() || $call->tool === null) {
            return ToolResult::error($call->error ?? t('agent.agent_loop.vyzov_ne_proshel_proverku'));
        }
        // a tool call runs in a fiber of its own: its log lines name the run too
        \OpenCCK\Infrastructure\Logger\RunContext::set($state->run->id);
        $this->transcript->startCall($state->run->id, $call->call->id);
        if ($call->tool instanceof ReadsOutside && $call->args !== null && $call->tool->readsOutside($call->args)) {
            // marked before the call: even a failed read may have brought text in
            $this->runs->markOutside($state->run->id, $call->call->name);
        }
        // The status message is in the chat: secrets in the arguments are masked there too.
        $preview = $call->args === null ? $call->call->argumentsJson : (json_encode(SecretMasker::maskArgs($call->args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $state->observer->toolStarted($state->run, $call->call->name, mb_substr(SecretMasker::mask($preview), 0, 200));
        $remaining = max(1, (int) floor(($state->run->limits->runTimeout * 1000 - $this->elapsedMs($state)) / 1000));
        $timeout = $call->tool instanceof LongRunning
            ? max(1, min($call->tool->timeout(), $remaining))
            : max(1, min($call->tool->timeout(), $state->run->limits->toolTimeout, $remaining));
        $context = $this->contextOf($state, $call, $approved);
        $started = ($this->clock)();
        $result = $this->executor->run($call, $context, $timeout, $this->budget->perResult);
        $this->events->toolFinished($state->run->id, $call->call->name, $call->args, $result, ($this->clock)() - $started);
        if ($call->tool instanceof RequestsAccess && !$result->isError) {
            // the owner gave the tool: it is offered from the next turn of this very run
            $state->policy = $state->policy->withTool($call->tool->grantedTool($call->args ?? []));
        }
        return $result;
    }
    private function contextOf(RunState $state, ValidatedCall $call, bool $approved = false): ToolContext {
        return new ToolContext($state->run->id, $state->run->conversationId, $state->principal, $state->cancellation, $state->policy, $call->call->id, $approved);
    }
    private function writeResult(RunState $state, ValidatedCall $call, ToolResult $result): void {
        $status = $result->isError ? ToolCallStatus::Failed : ToolCallStatus::Done;
        // spec: brief/self-improvement.md#самоулучшение — a call the policy did not allow is marked for the review
        $this->transcript->appendToolResult($state->run->conversationId, $state->run->id, $call->call->id, $status, $result->content, $call->tool === null);
    }
    /**
     * ref: references/hermes-agent/agent/turn_iteration_prep.py@e96221fa:L41-L99, L183-L186 — one-time budget warning
     *      appended to the last tool result, not a separate message (keeps role alternation and the prompt cache)
     */
    private function withNotes(RunState $state, ToolResult $result, bool $repeating): ToolResult {
        $notes = [];
        if ($repeating) {
            $notes[] = \Mercurius\I18n\t(LoopGuard::WARNING);
        }
        $limit = $this->nearLimit($state);
        if ($limit !== null && !$state->run->warned) {
            $notes[] = sprintf(t('agent.agent_loop.izrashodovano_bolshe_80_imita_podvodi'), $limit);
            $this->runs->markWarned($state->run->id);
            $state->run = $this->runs->find($state->run->id) ?? $state->run;
        }
        return $notes === [] ? $result : new ToolResult($result->content . "\n\n[" . implode(' ', $notes) . ']', $result->isError);
    }
    private function nearLimit(RunState $state): ?string {
        $run = $state->run;
        return match (true) {
            $run->turns >= self::WARN_SHARE * (float) $run->limits->maxTurns => t('agent.agent_loop.hodov'),
            $run->tokensUsed >= self::WARN_SHARE * (float) $run->limits->tokenBudget => t('agent.agent_loop.tokenov'),
            $this->elapsedMs($state) >= self::WARN_SHARE * (float) ($run->limits->runTimeout * 1000) => t('agent.agent_loop.vremeni'),
            default => null,
        };
    }

    private function elapsedMs(RunState $state): int {
        return $state->run->activeMs + (int) round((($this->clock)() - $state->flushedAt) * 1000.0);
    }
}
