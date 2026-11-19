<?php

declare(strict_types=1);

namespace OpenCCK\Memory\App;

use Closure;
use InvalidArgumentException;
use Throwable;
use OpenCCK\Memory\Domain\ContentScreen;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;
use OpenCCK\Memory\Domain\TextAnalyzer;

use function Mercurius\I18n\t;

/**
 * Changes to memory go through the owner: the agent proposes, the owner accepts or rejects, and only an accepted
 * proposal changes memory, with a new version. A forgotten text leaves a keyed fingerprint, so it cannot come back.
 *
 * spec: BRIEF.md#фаза-6-по-частям — new facts and edits of layer 1 are proposals to the owner
 * ref: references/hermes-plugin-holographic/history.py@4a794f8:L489-L710 (change_fact, end_fact, forget_fact) —
 *      versions and tombstones; a proposal step in front of them is ours
 * ref: references/hermes-agent/tools/memory_tool.py@e96221fa:L56 — layer limits in characters, not tokens (2200 and
 *      1375 there; ours are larger: profile 2000, summary 4000)
 */
final class Proposals {
    public const PROFILE_MAX = 2000;
    public const SUMMARY_MAX = 4000;
    /** Trust of a fact the owner accepted. */
    private const CONFIRMED_TRUST = 0.8;
    /** a fact the agent wrote itself: below what the owner confirmed */
    private const AGENT_TRUST = 0.6;

    /** @var list<Closure(Fact, list<array{string, string}>): void> */
    private array $forgetListeners = [];

    public function __construct(
        private readonly MemoryStore $store,
        private readonly TextAnalyzer $analyzer,
        private readonly ContentScreen $screen,
        private readonly string $key,
    ) {}

    /**
     * Called after a fact is forgotten, with the cards of the proposals about it, so the places that still show its
     * text (cards, transcripts) can drop it.
     *
     * @param Closure(Fact, list<array{string, string}>): void $listener
     */
    public function onForget(Closure $listener): void {
        $this->forgetListeners[] = $listener;
    }

    /**
     * @param list<string> $readable the audience's scopes; a new fact goes to the first of them
     * @param bool $aboutPerson the fact is about another person: only the owner's memory takes it
     * @throws InvalidArgumentException with the reason to show the model
     */
    public function propose(string $kind, ?int $factId, string $text, string $reason, array $readable, ?int $principalId, ?int $runId, bool $aboutPerson = false, string $edit = 'all', string $oldText = '', bool $project = false): Proposal {
        $text = trim($text);
        $reason = trim($reason);
        if ($readable === []) {
            throw new InvalidArgumentException(t('app.proposals.zdes_pamyat_nedostupna'));
        }
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — what is said about other people is kept only for the owner
        if ($aboutPerson && !in_array(Audience::OWNER, $readable, true)) {
            throw new InvalidArgumentException(t('app.proposals.about_person_owner_only'));
        }
        $scope = $aboutPerson ? Audience::OWNER : $readable[0];
        // spec: BRIEF.md#проекты-задачи-и-отношение-участника-к-проекту — a fact of the chat's active project
        if ($project) {
            $projects = array_values(array_filter($readable, static fn(string $s): bool => str_starts_with($s, 'project:')));
            if ($projects === [] || $aboutPerson) {
                throw new InvalidArgumentException(t('app.proposals.no_project_here'));
            }
            $scope = $projects[0];
        }
        switch ($kind) {
            case Proposal::ADD:
                $this->check($text, ContentScreen::FACT_MAX);
                $this->notForgotten($text);
                break;
            case Proposal::CHANGE:
            case Proposal::END:
            case Proposal::FORGET:
                $fact = $factId === null ? null : $this->store->fact($factId);
                if ($fact === null || !$fact->active || !in_array($fact->scope, $readable, true)) {
                    throw new InvalidArgumentException(t('app.proposals.takogo_fakta_zdes_net'));
                }
                $scope = $fact->scope;
                if ($aboutPerson && $scope !== Audience::OWNER) {
                    throw new InvalidArgumentException(t('app.proposals.about_person_owner_only'));
                }
                if ($kind === Proposal::CHANGE) {
                    $this->check($text, ContentScreen::FACT_MAX);
                    $this->notForgotten($text);
                } else {
                    $text = $fact->text;
                }
                break;
            case Proposal::PROFILE:
            case Proposal::SUMMARY:
                if (!in_array(Audience::OWNER, $readable, true)) {
                    throw new InvalidArgumentException(t('app.proposals.profil_i_svodka_menyayutsya_tolko'));
                }
                // spec: BRIEF.md#всегда-в-контексте — one entry is added, replaced or removed; the card shows the result
                $text = CoreEntries::apply($this->store->core(MemoryContext::section($kind, Audience::OWNER)), $edit, $oldText, $text);
                $this->check($text, $kind === Proposal::PROFILE ? self::PROFILE_MAX : self::SUMMARY_MAX);
                $scope = Audience::OWNER;
                $factId = null;
                break;
            default:
                throw new InvalidArgumentException(t('app.proposals.neizvestnyy_vid_izmeneniya') . $kind);
        }
        if ($reason === '') {
            throw new InvalidArgumentException(t('app.proposals.nuzhna_prichina_vladelets_reshaet_po'));
        }
        $proposal = $this->store->propose($kind, $kind === Proposal::ADD ? null : $factId, $scope, $text, mb_substr($reason, 0, 500), $principalId, $runId, $aboutPerson);
        if ($kind !== Proposal::ADD) {
            return $proposal;
        }
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — the agent writes a new fact itself and the owner corrects
        //       after; a change or a removal of a fact still waits for the owner's card
        $written = $this->decide($proposal->id, true, true);
        return $written === null ? $proposal : ($this->store->proposal($proposal->id) ?? $written->proposal);
    }

    /**
     * Accepts or rejects a pending proposal once; an accepted one is applied. Null when it is already decided.
     * A text forgotten since the proposal was made is rejected instead. If applying fails, the proposal goes back to
     * pending and the error goes on, so the owner can press again.
     */
    /** @param bool $byAgent the agent accepted its own new fact: it is kept as the agent's, not as confirmed */
    public function decide(int $id, bool $approve, bool $byAgent = false): ?Decision {
        $pending = $this->store->proposal($id);
        if ($approve && $pending !== null && in_array($pending->kind, [Proposal::ADD, Proposal::CHANGE], true)
            && $this->store->forgotten($this->fingerprint($pending->text))) {
            $rejected = $this->store->decide($id, Proposal::REJECTED);
            return $rejected === null ? null : new Decision($rejected, false, t('app.proposals.otkloneno_etot_tekst_veleno_zabyt'));
        }
        $proposal = $this->store->decide($id, $approve ? Proposal::APPROVED : Proposal::REJECTED);
        if ($proposal === null) {
            return null;
        }
        if (!$approve) {
            return new Decision($proposal, false, t('app.proposals.otkloneno'));
        }
        try {
            $applied = $this->apply($proposal, $byAgent);
        } catch (Throwable $e) {
            $this->store->reopen($proposal->id);
            throw $e;
        }
        return new Decision($proposal, $applied, $applied ? t('app.proposals.prinyato_pamyat_izmenena') : t('app.proposals.prinyato_no_menyat_uzhe_nechego'));
    }

    private function apply(Proposal $proposal, bool $byAgent = false): bool {
        switch ($proposal->kind) {
            case Proposal::ADD:
                $fact = $byAgent
                    ? $this->store->addFact($proposal->scope, Fact::KNOWLEDGE, $proposal->text, $this->analyzer->keys($proposal->text), self::AGENT_TRUST, Fact::AGENT, $proposal->runId)
                    : $this->store->addFact($proposal->scope, Fact::KNOWLEDGE, $proposal->text, $this->analyzer->keys($proposal->text), self::CONFIRMED_TRUST, Fact::OWNER_CONFIRMED, $proposal->runId);
                $this->store->linkProposal($proposal->id, $fact->id);
                return true;
            case Proposal::CHANGE:
                return $this->store->changeFact((int) $proposal->factId, $proposal->text, $this->analyzer->keys($proposal->text), $proposal->reason) !== null;
            case Proposal::END:
                return $this->store->endFact((int) $proposal->factId, $proposal->reason);
            case Proposal::FORGET:
                return $this->forget((int) $proposal->factId);
            case Proposal::PROFILE:
            case Proposal::SUMMARY:
                $this->store->setCore(MemoryContext::section($proposal->kind, $proposal->scope), $proposal->text);
                return true;
        }
        return false;
    }

    /**
     * Removes the fact with its versions and the proposals about it; only a keyed fingerprint of its text stays.
     * Listeners then clear the cards and transcripts that still show it.
     */
    public function forget(int $factId): bool {
        $fact = $this->store->fact($factId);
        if ($fact === null) {
            return false;
        }
        $cards = $this->store->cards($factId);
        if (!$this->store->forgetFact($factId, $this->fingerprint($fact->text))) {
            return false;
        }
        foreach ($this->forgetListeners as $listener) {
            $listener($fact, $cards);
        }
        return true;
    }

    private function notForgotten(string $text): void {
        if ($this->store->forgotten($this->fingerprint($text))) {
            throw new InvalidArgumentException(t('app.proposals.vladelets_velel_zabyt_etot_tekst'));
        }
    }

    private function fingerprint(string $text): string {
        $normal = mb_strtolower(preg_replace('~\s+~u', ' ', trim(str_replace(['ё', 'Ё'], ['е', 'Е'], $text))) ?? $text);
        return hash_hmac('sha256', $normal, $this->key);
    }

    private function check(string $text, int $max): void {
        $problem = $this->screen->problem($text, $max);
        if ($problem !== null) {
            throw new InvalidArgumentException(t('app.proposals.ne_zapisyvayu') . $problem . '.');
        }
    }
}
