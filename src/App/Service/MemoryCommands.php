<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use Mercurius\Memory\App\Aliases;
use Mercurius\Memory\App\Audience;
use Mercurius\Memory\App\Consolidation;
use Mercurius\Memory\App\LossGuard;
use Mercurius\Memory\App\MemoryContext;
use Mercurius\Memory\App\Proposals;
use Mercurius\Memory\Domain\MemoryStore;
use Mercurius\Memory\Domain\Probes;

use function Mercurius\I18n\t;

/**
 * The owner's view of memory: what is remembered, what waits for a decision, and forgetting by number.
 *
 * spec: brief/memory.md#запись-сведения-о-людях-и-забывание — the owner sees and edits memory; forgetting removes the text for good
 */
final class MemoryCommands {
    public const RECENT = 20;
    /** the facts paged through at most */
    private const ALL = 2000;
    private const SEARCH_BUDGET = 6000;
    private const LINE = 300;

    public function __construct(
        private readonly MemoryStore $store,
        private readonly Proposals $proposals,
        private readonly MemoryContext $context,
        private readonly ?Consolidation $consolidation = null,
        private readonly ?Aliases $aliases = null,
        private readonly ?LossGuard $guard = null,
        /** @var (\Closure(): bool)|null whether sleep runs: a model is listed for it */
        private readonly ?\Closure $sleeps = null,
        private readonly ?Probes $probes = null,
    ) {}

    /**
     * /probe — the owner's probes of memory; /probe + <question> #<fact> — a question memory must always answer with
     * that fact; /probe - <number> — remove one. The guard asks them every night.
     *
     * spec: brief/memory.md#пробы-владельца
     */
    public function probe(string $argument): string {
        if ($this->probes === null) {
            return t('service.memory_commands.probes_off');
        }
        $argument = trim($argument);
        if ($argument === '') {
            $lines = [];
            foreach ($this->probes->all() as $probe) {
                $lines[] = sprintf('#%d «%s» → #%d', $probe->id, $probe->question, $probe->factId) . ($probe->missing ? t('service.memory_commands.probe_missing_mark') : '');
            }
            return ($lines === [] ? t('service.memory_commands.probe_none') : implode("\n", $lines)) . "\n\n" . t('service.memory_commands.probe_help');
        }
        if (preg_match('~^-\s*#?(\d+)$~u', $argument, $m) === 1) {
            return $this->probes->remove((int) $m[1])
                ? sprintf(t('service.memory_commands.probe_removed'), (int) $m[1])
                : sprintf(t('service.memory_commands.probe_none_such'), (int) $m[1]);
        }
        if (preg_match('~^\+\s*(.+?)\s+#(\d+)$~us', $argument, $m) !== 1) {
            return t('service.memory_commands.probe_help');
        }
        $question = SecretMasker::mask(trim($m[1]));
        $fact = $this->store->fact((int) $m[2]);
        if ($fact === null || !$fact->active) {
            return sprintf(t('service.memory_commands.probe_no_fact'), (int) $m[2]);
        }
        if ($question === '' || mb_strlen($question) > Probes::QUESTION_MAX) {
            return t('service.memory_commands.probe_help');
        }
        try {
            $probe = $this->probes->add($question, $fact->id);
        } catch (\LengthException) {
            return sprintf(t('service.memory_commands.probe_full'), Probes::MAX);
        }
        $first = array_slice($this->context->search($question, [$fact->scope], self::SEARCH_BUDGET), 0, LossGuard::PROBE_TOP);
        $found = in_array($fact->id, array_map(static fn(\Mercurius\Memory\Domain\Fact $f): int => $f->id, $first), true);
        return sprintf(t('service.memory_commands.probe_added'), $probe->id, $probe->question, $fact->id)
            . ($found ? '' : ' ' . t('service.memory_commands.probe_not_found_now'));
    }

    /**
     * /aliases — the table of words that mean one thing; /aliases + word, word — a group (or words to the group one of
     * them is in); /aliases - <number> — remove a group.
     *
     * spec: brief/memory.md#словари — the owner sees and edits the table
     */
    public function aliases(string $argument): string {
        if ($this->aliases === null) {
            return t('service.memory_commands.aliases_off');
        }
        $argument = trim($argument);
        if ($argument === '') {
            return $this->aliases->list() . "\n\n" . t('service.memory_commands.aliases_help');
        }
        if (str_starts_with($argument, '-')) {
            $id = ltrim(trim(substr($argument, 1)), '#');
            return ctype_digit($id) ? $this->aliases->remove((int) $id)[1] : t('service.memory_commands.aliases_help');
        }
        if (str_starts_with($argument, '+')) {
            $words = preg_split('~[,;]+~u', substr($argument, 1)) ?: [];
            return $this->aliases->add(array_values(array_filter(array_map('trim', $words), static fn(string $w): bool => $w !== '')), null)[1];
        }
        return t('service.memory_commands.aliases_help');
    }

    /**
     * /dream (and /consolidate): the guard against a loss of memory, and the night's sleep now, even if memory has not
     * changed — when a model is listed for sleep. /dream undo <n> undoes an edit sleep made by itself.
     *
     * spec: brief/memory.md#страж-потерь
     * spec: brief/memory.md#постоянное-разрешение — /dream undo
     */
    public function consolidate(string $argument = ''): string {
        if (preg_match('~^\s*undo\b\s*#?(\d*)\s*$~u', $argument, $undo) === 1) {
            if ($undo[1] === '') {
                return t('service.memory_commands.dream_undo_usage');
            }
            return $this->consolidation === null ? t('service.memory_commands.proverka_pamyati_ne_nastroena') : $this->consolidation->undo((int) $undo[1]);
        }
        $alert = $this->guard?->run();
        if ($this->sleeps !== null && !($this->sleeps)()) {
            return ($alert === null ? '' : $alert . "\n\n") . t('service.memory_commands.sleep_off');
        }
        return $this->consolidation === null ? t('service.memory_commands.proverka_pamyati_ne_nastroena') : $this->consolidation->run(true);
    }

    /**
     * /memory: the overview; /memory <number> — that page of facts, newest first; /memory <words> — the facts recall
     * finds by them.
     *
     * spec: brief/memory.md#как-сделано-области-и-факты — /memory <words> and pages
     */
    public function show(string $argument, string $channel, string $chatId): string {
        $argument = trim($argument);
        $scopes = [Audience::OWNER, Audience::chat($channel, $chatId)];
        if ($argument === '') {
            return $this->overview($channel, $chatId);
        }
        if (ctype_digit($argument)) {
            $all = $this->store->recent($scopes, self::ALL);
            $pages = max(1, (int) ceil(count($all) / self::RECENT));
            $page = max(1, min($pages, (int) $argument));
            $facts = array_slice($all, ($page - 1) * self::RECENT, self::RECENT);
            return sprintf(t('service.memory_commands.page'), $page, $pages) . "\n" . ($facts === [] ? t('service.memory_commands.faktov_poka_net') : self::lines($facts));
        }
        $found = $this->context->search($argument, $scopes, self::SEARCH_BUDGET);
        return $found === [] ? t('service.memory_commands.nothing_found') : t('service.memory_commands.found') . "\n" . self::lines($found);
    }

    public function overview(string $channel, string $chatId): string {
        $parts = [];
        $core = $this->context->core();
        if ($core !== '') {
            $parts[] = $core;
        }
        $facts = $this->store->recent([Audience::OWNER, Audience::chat($channel, $chatId)], self::RECENT);
        $parts[] = $facts === []
            ? t('service.memory_commands.faktov_poka_net')
            : t('service.memory_commands.poslednie_fakty') . self::lines($facts);
        $pending = $this->store->pending();
        if ($pending !== []) {
            $parts[] = t('service.memory_commands.zhdut_resheniya') . implode("\n", array_map(static fn($p): string => sprintf('№%d %s: %s', $p->id, $p->kind, self::cut($p->text)), $pending));
        }
        $parts[] = t('service.memory_commands.forget_nomer_zabyt_fakt_nasovsem');
        return implode("\n\n", $parts);
    }

    /**
     * One line a fact: number, text and where it came from.
     *
     * @param list<\Mercurius\Memory\Domain\Fact> $facts
     */
    private static function lines(array $facts): string {
        return implode("\n", array_map(static fn(\Mercurius\Memory\Domain\Fact $f): string => sprintf('#%d %s — %s', $f->id, self::cut($f->text), t('memory.provenance.' . $f->provenance)), $facts));
    }

    public function forget(string $argument): string {
        $id = ltrim(trim($argument), '#');
        if (!ctype_digit($id)) {
            return t('service.memory_commands.ukazhite_nomer_fakta_forget_12');
        }
        return $this->proposals->forget((int) $id)
            ? sprintf(t('service.memory_commands.fakt_zabyt_tekst_i_ego'), $id)
            : sprintf(t('service.memory_commands.fakta_net'), $id);
    }

    private static function cut(string $text): string {
        $text = SecretMasker::mask(str_replace("\n", ' ', $text));
        return mb_strlen($text) > self::LINE ? mb_substr($text, 0, self::LINE) . '…' : $text;
    }
}
