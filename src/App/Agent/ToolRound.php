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

    private ?RunAreas $areas = null;

    /** spec: brief/agent-loop.md#разрешения-навсегда — grants of an area (a job, a project) and runs with nobody to ask */
    public function useAreas(RunAreas $areas): void {
        $this->areas = $areas;
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
            if (!$call->executable() || $call->args === null || !($call->tool instanceof PreparesCall || $call->tool instanceof Tool\PreparesInConversation)) {
                return $call;
            }
            try {
                $prepared = $call->tool instanceof PreparesCall
                    ? $call->tool->prepare($call->args, $state->principal, $state->policy, $state->cancellation)
                    : $call->args;
                if (is_array($prepared) && $call->tool instanceof Tool\PreparesInConversation) {
                    $prepared = $call->tool->prepareIn($prepared, $state->principal, $state->run->conversationId, $state->cancellation);
                }
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
                $wire->signature,
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
        /** @var array<string, true> $observed what the guard already saw in this answer */
        $observed = [];
        // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — a call to a tool that is no longer offered:
        //       the model took its name from the history and did not hear «do not repeat». Running it gives the same
        //       answer and two more model calls before the stop, so nothing of this answer runs and the run stops now
        // ref: references/hermes-agent/agent/tool_guardrails.py@e96221fa:L362-L378 (before_call) — a repeated call with
        //      no progress is blocked before it runs and gets a synthetic result; hermes blocks the one call and goes
        //      on, here the tool was already taken away after a warning, so the run ends
        foreach ($calls as $call) {
            $halted = $halted || isset($state->withheld[$call->call->name]);
        }
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
                // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — the same call written several times in one
                //       answer is one slip of the model, not a loop: the guard counts it once
                $signature = $call->args === null ? null : LoopGuard::signature($call->call->name, $call->args, $result->content);
                $verdict = $signature === null || isset($observed[$signature]) ? GuardVerdict::Ok : $state->guard->observe($signature, LoopGuard::isPoll($call->call->name));
                if ($signature !== null) {
                    $observed[$signature] = true;
                }
                $halted = $halted || $verdict === GuardVerdict::Halt;
                $warn = $warn || $verdict === GuardVerdict::Warn;
                // spec: brief/agent-loop.md#запасная-модель-и-ограничитель-зацикливания — a tool asked the same thing a third
                //       time has nothing new to say: it is not offered again, the model answers by what it has. A tool that
                //       watches the outside world stays — its unchanged answer may change
                if ($verdict !== GuardVerdict::Ok && !LoopGuard::isPoll($call->call->name)) {
                    $state->withheld[$call->call->name] = true;
                }
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
        // spec: brief/agent-loop.md#запреты — a ban of any area is stronger than a grant of any area: it is checked first,
        //       and the refusal says not to ask again
        $ban = $call->executable() && $call->tool !== null && $call->args !== null ? $this->banFor($state, $call->tool, $call->args, $call->call->name) : null;
        if ($ban !== null) {
            return ToolResult::error(sprintf(t('agent.bans.refused'), GrantKeys::label($ban->key)));
        }
        // spec: brief/orchestration.md#часть-1--синхронное-делегирование — a worker cannot ask the owner: an irreversible
        //      action runs only by the owner's saved "always" grant, under the same rules as for the agent itself
        if (!$state->policy->irreversible && $call->executable() && $call->danger === DangerClass::Irreversible) {
            return $call->tool !== null && $call->args !== null && $this->grantFor($state, $call->tool, $call->args, $call->call->name)
                ? 'granted'
                : ToolResult::error(t('agent.agent_loop.vorkeru_neobratimye_deystviya_nedostupny'));
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
        if ($this->grantFor($state, $call->tool, $call->args, $call->call->name)) {
            return 'granted';
        }
        // spec: brief/scheduler.md#разрешения-задания — a run nobody watches does not wait for a card: the call is refused
        //       and the grant it lacked joins one list the owner gets after the run
        $run = $this->runs->find($state->run->id) ?? $state->run;
        $alone = $this->areas?->unattended($run);
        if ($alone !== null && $this->approvals !== null && !$call->tool instanceof RequestsAccess) {
            $keys = GrantKeys::of($call->tool, $call->args, $call->call->name);
            $label = SecretMasker::mask($call->tool instanceof GrantKeyed ? $call->tool->describe($call->args) : $call->call->name);
            $this->approvals->noteNeed($alone, $state->principal->id, $keys->exact, mb_substr($label, 0, 300));
            return ToolResult::error(sprintf(t('agent.grants.lacking'), GrantKeys::label($keys->exact)));
        }
        // spec: brief/network.md#участник-просит-инструмент — asking for a tool reaches the owner always
        if ($this->gate === null || $this->approvals === null) {
            return ToolResult::error(t('agent.agent_loop.deystvie_trebuet_podtverzhdeniya_vladelt'));
        }
        // spec: brief/agent-loop.md#права-участника — the refusal names the missing right and who gives it, so the model
        //      reports it to the participant instead of trying again
        // spec: brief/skills-mcp.md#ящики-участников-и-проектов — what leaves a mailbox is decided by the one who connected it
        //       (a mailbox, a secret, a browser login); the caller needs no right to ask the owner of the agent for that
        $resourceOwner = $call->tool instanceof Tool\DecidedByParticipant ? $call->tool->decider($call->args) : null;
        $decider = $resourceOwner?->isOwner() === true ? null : $resourceOwner;
        if ($resourceOwner === null && !$state->policy->mayRequestApproval && !$call->tool instanceof RequestsAccess) {
            return ToolResult::error(t('agent.agent_loop.net_prava_prosit_podtverzhdenie'));
        }
        // Saved as waiting before the owner sees the card: a press that comes at once must find the run waiting.
        $this->transcript->setCallStatus($state->run->id, $call->call->id, ToolCallStatus::AwaitingApproval);
        $this->runs->setStatus($state->run->id, RunStatus::WaitingApproval);
        $this->gate->request($this->runs->find($state->run->id) ?? $state->run, $call, $state->principal, $decider);
        return false;
    }
    /**
     * A saved "always" grant of the participant covers this call.
     *
     * spec: brief/agent-loop.md#запуск-начатый-внешними-данными — after outside data a grant is not used: the text read may
     *       be someone's instructions, so the owner sees every irreversible action of this run on a card
     *
     * @param array<string, mixed> $args
     */
    private function grantFor(RunState $state, Tool\Tool $tool, array $args, string $name): bool {
        if ($this->approvals === null) {
            return false;
        }
        $run = $this->runs->find($state->run->id) ?? $state->run;
        // spec: brief/agent-loop.md#разрешения-навсегда — the call's own key, a wider key of the tool, the grant of an area
        //       the run works in; after outside data only a grant the owner gave for that
        $keys = GrantKeys::of($tool, $args, $name)->candidates($name, $this->areas?->of($run) ?? []);
        $grant = $this->approvals->grantFor($state->principal->id, $keys, $run->conversationId, $run->outside !== null);
        if ($grant === null) {
            return false;
        }
        $this->approvals->useGrant($grant->id);
        $this->logger->info('Irreversible call allowed by a saved grant', ['run' => $state->run->id, 'tool' => $name, 'key' => $grant->key, 'grant' => $grant->id]);
        return true;
    }

    /**
     * The ban that covers this call: of everyone, of the participant, of a project or a job the run works in.
     *
     * @param array<string, mixed> $args
     */
    private function banFor(RunState $state, Tool\Tool $tool, array $args, string $name): ?\OpenCCK\Domain\Agent\Ban {
        if ($this->approvals === null) {
            return null;
        }
        $bans = $this->approvals->bans();
        if ($bans === []) {
            return null;
        }
        $keys = GrantKeys::of($tool, $args, $name);
        $own = array_values(array_unique(array_filter([$keys->exact, $keys->wide[0] ?? null, GrantKeys::any($name)], static fn(?string $k): bool => $k !== null)));
        $areas = $this->areas?->of($this->runs->find($state->run->id) ?? $state->run) ?? [];
        foreach ($bans as $ban) {
            if ($ban->reaches($state->principal->id, $areas) && $ban->covers($own)) {
                $this->logger->info('Call refused by a ban', ['run' => $state->run->id, 'tool' => $name, 'ban' => $ban->id]);
                return $ban;
            }
        }
        return null;
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
