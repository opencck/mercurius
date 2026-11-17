<?php

declare(strict_types=1);

namespace OpenCCK\App\Skill;

use InvalidArgumentException;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Domain\Skill\SkillVersion;

/**
 * The catalog of skills as the agent sees it: an index of active skills in the prompt, the body on demand, a change
 * in the middle of a conversation as a one-off note — the prompt itself stays as it was for the cache.
 *
 * spec: BRIEF.md#скиллы-и-mcp
 * ref: references/hermes-agent/agent/prompt_builder.py@e96221fa:L1303-L1368 (_render_skills_index) — the index and the
 *      rule to load a matching skill before answering; references/hermes-agent/agent/skills_index_delta.py@e96221fa:
 *      L102-L137 (stage_skills_index_note) — a note on the next user message instead of a new prompt
 */
final class SkillCatalog {
    /** characters a skill text must not carry: Unicode tags (invisible instructions) and other format characters */
    private const HIDDEN = '~[\x{E0000}-\x{E007F}]|[\p{Co}]|(?![\x{200D}])[\p{Cf}]~u';

    public function __construct(private readonly SkillRepository $skills) {}

    /** The index for the system prompt; empty when there are no active skills. */
    public function index(): string {
        $active = $this->skills->active();
        if ($active === []) {
            return '';
        }
        return "Скиллы — проверенные владельцем инструкции для типовых задач. Перед ответом просмотри список; если скилл хоть "
            . "частично подходит — загрузи его через skill_view и следуй ему.\n"
            . implode("\n", array_map(static fn(Skill $s): string => sprintf('- %s: %s', $s->name, $s->description), $active));
    }

    /** @return array<string, int> active skill => its version, to compare later */
    public function snapshot(): array {
        $snapshot = [];
        foreach ($this->skills->active() as $skill) {
            $snapshot[$skill->name] = $skill->version;
        }
        return $snapshot;
    }

    /**
     * What changed since a snapshot, as a note for the next message; empty when nothing did.
     *
     * @param array<string, int> $before
     */
    public function note(array $before): string {
        $now = $this->snapshot();
        // array keys of numeric names come back as ints
        $added = array_map(strval(...), array_keys(array_diff_key($now, $before)));
        $removed = array_map(strval(...), array_keys(array_diff_key($before, $now)));
        $changed = array_keys(array_filter($now, static fn(int $v, string $name): bool => isset($before[$name]) && $before[$name] !== $v, ARRAY_FILTER_USE_BOTH));
        if ($added === [] && $removed === [] && $changed === []) {
            return '';
        }
        $parts = [];
        if ($added !== []) {
            $lines = [];
            foreach ($added as $name) {
                $lines[] = sprintf('%s (%s)', $name, $this->skills->find($name)->description ?? '');
            }
            $parts[] = 'появились: ' . implode(', ', $lines);
        }
        if ($changed !== []) {
            $parts[] = 'изменились: ' . implode(', ', array_map(strval(...), $changed));
        }
        if ($removed !== []) {
            $parts[] = 'больше нет: ' . implode(', ', array_map(strval(...), $removed));
        }
        return '[Каталог скиллов изменился — ' . implode('; ', $parts) . '.]';
    }

    /** The body of an active skill; null when there is none by that name. */
    public function body(string $name): ?string {
        $skill = $this->skills->find($name);
        return $skill === null || $skill->status !== Skill::ACTIVE ? null : $skill->body;
    }

    /** @throws InvalidArgumentException when the text cannot be a skill */
    public function propose(string $name, string $description, string $body, string $reason, ?int $principalId, string $origin): SkillVersion {
        $description = trim($description);
        $body = trim($body);
        if (preg_match(Skill::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Имя скилла — строчные латинские буквы, цифры, точка, дефис, подчёркивание; до 64 символов.');
        }
        if ($description === '' || str_contains($description, "\n") || mb_strlen($description) > Skill::DESCRIPTION_MAX) {
            throw new InvalidArgumentException(sprintf('Описание — одна строка до %d символов: по ней агент решает, загружать ли скилл.', Skill::DESCRIPTION_MAX));
        }
        if ($body === '' || mb_strlen($body) > Skill::BODY_MAX) {
            throw new InvalidArgumentException(sprintf('Текст скилла — от 1 до %d символов.', Skill::BODY_MAX));
        }
        foreach ([$description, $body] as $text) {
            if (preg_match(self::HIDDEN, $text) === 1) {
                throw new InvalidArgumentException('В тексте невидимые символы: такой скилл не принимается.');
            }
            if (SecretMasker::mask($text) !== $text) {
                throw new InvalidArgumentException('В скилле не хранят секреты: уберите их.');
            }
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Нужна причина: владелец решает по ней.');
        }
        return $this->skills->propose($name, $description, $body, mb_substr(trim($reason), 0, 500), $principalId, $origin);
    }
}
