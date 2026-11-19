<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Memory\App\Audience;
use OpenCCK\Memory\App\Consolidation;
use OpenCCK\Memory\App\MemoryContext;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\Domain\MemoryStore;

use function Mercurius\I18n\t;

/**
 * The owner's view of memory: what is remembered, what waits for a decision, and forgetting by number.
 *
 * spec: BRIEF.md#память-слои — the owner sees and edits memory; forgetting removes the text for good
 */
final class MemoryCommands {
    public const RECENT = 20;
    private const LINE = 300;

    public function __construct(
        private readonly MemoryStore $store,
        private readonly Proposals $proposals,
        private readonly MemoryContext $context,
        private readonly ?Consolidation $consolidation = null,
    ) {}

    /** The nightly check, now, even if memory has not changed. */
    public function consolidate(): string {
        return $this->consolidation === null ? t('service.memory_commands.proverka_pamyati_ne_nastroena') : $this->consolidation->run(true);
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
            : t('service.memory_commands.poslednie_fakty') . implode("\n", array_map(static fn($f): string => sprintf('#%d %s', $f->id, self::cut($f->text)), $facts));
        $pending = $this->store->pending();
        if ($pending !== []) {
            $parts[] = t('service.memory_commands.zhdut_resheniya') . implode("\n", array_map(static fn($p): string => sprintf('№%d %s: %s', $p->id, $p->kind, self::cut($p->text)), $pending));
        }
        $parts[] = t('service.memory_commands.forget_nomer_zabyt_fakt_nasovsem');
        return implode("\n\n", $parts);
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
