<?php

declare(strict_types=1);

namespace OpenCCK\Memory\App;

use InvalidArgumentException;
use OpenCCK\Memory\Domain\ContentScreen;
use OpenCCK\Memory\Domain\Fact;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\Proposal;
use OpenCCK\Memory\Domain\TextAnalyzer;

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

    public function __construct(
        private readonly MemoryStore $store,
        private readonly TextAnalyzer $analyzer,
        private readonly ContentScreen $screen,
        private readonly string $key,
    ) {}

    /**
     * @param list<string> $readable the audience's scopes; a new fact goes to the first of them
     * @throws InvalidArgumentException with the reason to show the model
     */
    public function propose(string $kind, ?int $factId, string $text, string $reason, array $readable, ?int $principalId, ?int $runId): Proposal {
        $text = trim($text);
        $reason = trim($reason);
        if ($readable === []) {
            throw new InvalidArgumentException('Здесь память недоступна.');
        }
        $scope = $readable[0];
        switch ($kind) {
            case Proposal::ADD:
                $this->check($text, ContentScreen::FACT_MAX);
                if ($this->store->forgotten($this->fingerprint($text))) {
                    throw new InvalidArgumentException('Владелец велел забыть этот текст: снова он не записывается.');
                }
                break;
            case Proposal::CHANGE:
            case Proposal::END:
            case Proposal::FORGET:
                $fact = $factId === null ? null : $this->store->fact($factId);
                if ($fact === null || !$fact->active || !in_array($fact->scope, $readable, true)) {
                    throw new InvalidArgumentException('Такого факта здесь нет.');
                }
                $scope = $fact->scope;
                if ($kind === Proposal::CHANGE) {
                    $this->check($text, ContentScreen::FACT_MAX);
                } else {
                    $text = $fact->text;
                }
                break;
            case Proposal::PROFILE:
            case Proposal::SUMMARY:
                if (!in_array(Audience::OWNER, $readable, true)) {
                    throw new InvalidArgumentException('Профиль и сводка меняются только в личном чате владельца.');
                }
                $this->check($text, $kind === Proposal::PROFILE ? self::PROFILE_MAX : self::SUMMARY_MAX);
                $scope = Audience::OWNER;
                $factId = null;
                break;
            default:
                throw new InvalidArgumentException('Неизвестный вид изменения: ' . $kind);
        }
        if ($reason === '') {
            throw new InvalidArgumentException('Нужна причина: владелец решает по ней.');
        }
        return $this->store->propose($kind, $kind === Proposal::ADD ? null : $factId, $scope, $text, mb_substr($reason, 0, 500), $principalId, $runId);
    }

    /** Accepts or rejects a pending proposal once; an accepted one is applied. Null when it is already decided. */
    public function decide(int $id, bool $approve): ?Proposal {
        $proposal = $this->store->decide($id, $approve ? Proposal::APPROVED : Proposal::REJECTED);
        if ($proposal === null || !$approve) {
            return $proposal;
        }
        match ($proposal->kind) {
            Proposal::ADD => $this->store->addFact($proposal->scope, Fact::KNOWLEDGE, $proposal->text, $this->analyzer->keys($proposal->text), self::CONFIRMED_TRUST, Fact::OWNER_CONFIRMED, $proposal->runId),
            Proposal::CHANGE => $this->store->changeFact((int) $proposal->factId, $proposal->text, $this->analyzer->keys($proposal->text), $proposal->reason),
            Proposal::END => $this->store->endFact((int) $proposal->factId, $proposal->reason),
            Proposal::FORGET => $this->forget((int) $proposal->factId),
            Proposal::PROFILE, Proposal::SUMMARY => $this->store->setCore($proposal->kind, $proposal->text),
            default => null,
        };
        return $proposal;
    }

    /** Removes the fact with its versions; only a keyed fingerprint of its text stays. */
    public function forget(int $factId): bool {
        $fact = $this->store->fact($factId);
        return $fact !== null && $this->store->forgetFact($factId, $this->fingerprint($fact->text));
    }

    private function fingerprint(string $text): string {
        $normal = mb_strtolower(preg_replace('~\s+~u', ' ', trim(str_replace(['ё', 'Ё'], ['е', 'Е'], $text))) ?? $text);
        return hash_hmac('sha256', $normal, $this->key);
    }

    private function check(string $text, int $max): void {
        $problem = $this->screen->problem($text, $max);
        if ($problem !== null) {
            throw new InvalidArgumentException('Не записываю: ' . $problem . '.');
        }
    }
}
