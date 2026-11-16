<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Memory\App\Audience;
use OpenCCK\Memory\App\MemoryContext;
use OpenCCK\Memory\App\Proposals;
use OpenCCK\Memory\Domain\MemoryStore;

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
    ) {}

    public function overview(string $channel, string $chatId): string {
        $parts = [];
        $core = $this->context->core();
        if ($core !== '') {
            $parts[] = $core;
        }
        $facts = $this->store->recent([Audience::OWNER, Audience::chat($channel, $chatId)], self::RECENT);
        $parts[] = $facts === []
            ? 'Фактов пока нет.'
            : "Последние факты:\n" . implode("\n", array_map(static fn($f): string => sprintf('#%d %s', $f->id, self::cut($f->text)), $facts));
        $pending = $this->store->pending();
        if ($pending !== []) {
            $parts[] = "Ждут решения:\n" . implode("\n", array_map(static fn($p): string => sprintf('№%d %s: %s', $p->id, $p->kind, self::cut($p->text)), $pending));
        }
        $parts[] = '/forget <номер> — забыть факт насовсем.';
        return implode("\n\n", $parts);
    }

    public function forget(string $argument): string {
        $id = ltrim(trim($argument), '#');
        if (!ctype_digit($id)) {
            return 'Укажите номер факта: /forget 12';
        }
        return $this->proposals->forget((int) $id)
            ? sprintf('Факт #%s забыт: текст и его версии удалены, записать его снова нельзя.', $id)
            : sprintf('Факта #%s нет.', $id);
    }

    private static function cut(string $text): string {
        $text = SecretMasker::mask(str_replace("\n", ' ', $text));
        return mb_strlen($text) > self::LINE ? mb_substr($text, 0, self::LINE) . '…' : $text;
    }
}
