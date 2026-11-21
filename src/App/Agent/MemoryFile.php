<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

/**
 * MEMORY.md in two sections: «Правила» — what the person said plainly about how to work with them, and «Заметки» — the
 * agent's working notes. The headings are known in three languages, in any case and level; text before the first of
 * them, and a file without them, is notes: old text does not gain the force of rules by itself.
 *
 * spec: brief/memory.md#правила-и-заметки-memorymd
 */
final class MemoryFile {
    /** headings of the rules, folded to lower case */
    public const RULES = ['правила', 'rules', '规则', '規則'];
    public const NOTES = ['заметки', 'notes', '笔记', '筆記'];

    private function __construct(
        public readonly string $rules,
        public readonly string $notes,
    ) {}

    public static function of(string $rules, string $notes): self {
        return new self(trim($rules), trim($notes));
    }

    public static function parse(string $text): self {
        $rules = [];
        $notes = [];
        $into = null;
        foreach (preg_split('~\R~u', $text) ?: [] as $line) {
            if (preg_match('~^\s{0,3}#{1,6}\s*(.+?)\s*#*\s*$~u', $line, $m) === 1) {
                $title = rtrim(mb_strtolower($m[1]), ': ');
                if (in_array($title, self::RULES, true)) {
                    $into = 'rules';
                    continue;
                }
                if (in_array($title, self::NOTES, true)) {
                    $into = 'notes';
                    continue;
                }
            }
            if ($into === 'rules') {
                $rules[] = $line;
            } else {
                $notes[] = $line;
            }
        }
        return new self(trim(implode("\n", $rules)), trim(implode("\n", $notes)));
    }

    /**
     * The file with one more rule: a line of the «Правила» section; null when the same rule is there already. The file
     * is written back in its two sections (what stood before the headings goes into the notes).
     */
    public function withRule(string $rule): ?self {
        $rule = trim(preg_replace('~\s+~u', ' ', $rule) ?? $rule);
        $line = '- ' . ltrim($rule, '-• ');
        foreach (preg_split('~\R~u', $this->rules) ?: [] as $have) {
            if (mb_strtolower(trim($have, "-• \t")) === mb_strtolower(trim($line, "-• \t"))) {
                return null;
            }
        }
        return new self(trim($this->rules . "\n" . $line), $this->notes);
    }

    /** The file as it is written: the two sections under their headings. */
    public function text(string $language = 'ru'): string {
        [$rules, $notes] = match ($language) {
            'en' => ['Rules', 'Notes'],
            'zh' => ['规则', '笔记'],
            default => ['Правила', 'Заметки'],
        };
        return "## {$rules}\n" . ($this->rules === '' ? '' : $this->rules . "\n") . "\n## {$notes}\n" . ($this->notes === '' ? '' : $this->notes . "\n");
    }

    public function empty(): bool {
        return $this->rules === '' && $this->notes === '';
    }

    /** The characters both sections take, as the limit counts them. */
    public function length(): int {
        return mb_strlen($this->rules) + mb_strlen($this->notes);
    }

    /**
     * Within the limit: the rules whole (cut only when they alone pass it), the notes in what is left.
     *
     * spec: brief/memory.md#правила-и-заметки-memorymd — over the limit the notes are cut, never a rule
     *
     * @param positive-int $limit
     * @return array{string, string, bool} rules, notes, whether something was cut
     */
    public function within(int $limit): array {
        $rules = mb_strlen($this->rules) > $limit ? mb_substr($this->rules, 0, $limit) : $this->rules;
        $room = max(0, $limit - mb_strlen($rules));
        $notes = mb_strlen($this->notes) > $room ? mb_substr($this->notes, 0, $room) : $this->notes;
        return [$rules, $notes, $rules !== $this->rules || $notes !== $this->notes];
    }
}
