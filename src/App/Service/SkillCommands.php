<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\Skill;
use OpenCCK\Domain\Skill\SkillVersion;

/**
 * The owner manages the catalog from the chat: the list, a skill's text, turning it on and off, its versions and a
 * rollback.
 *
 * spec: BRIEF.md#скиллы-и-mcp — list, enable, disable from the channel, the owner only
 */
final class SkillCommands {
    public function __construct(private readonly SkillRepository $skills) {}

    public function list(): string {
        $all = $this->skills->all();
        if ($all === []) {
            return 'Скиллов нет. Попросите меня предложить скилл для повторяющейся задачи — он придёт карточкой.';
        }
        return "Скиллы:\n" . implode("\n", array_map(static fn(Skill $s): string => sprintf(
            '%s v%d — %s%s',
            $s->name,
            $s->version,
            match ($s->status) {
                Skill::ACTIVE => 'включён',
                Skill::DISABLED => 'выключен',
                default => 'ждёт одобрения',
            },
            $s->description === '' ? '' : ': ' . $s->description,
        ), $all)) . "\n\n/skill <имя> show|on|off|history|rollback <версия>";
    }

    public function skill(string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $name = $parts[0] ?? '';
        $action = $parts[1] ?? 'show';
        $skill = $this->skills->find($name);
        if ($skill === null) {
            return sprintf('Скилла «%s» нет. /skills — список.', $name);
        }
        return match ($action) {
            'show' => sprintf("%s v%d (%s)\n%s\n\n%s", $skill->name, $skill->version, $skill->status, $skill->description, $skill->body === '' ? '(ещё не одобрен)' : $skill->body),
            'on' => $this->skills->setStatus($name, Skill::ACTIVE) ? sprintf('Скилл «%s» включён.', $name) : 'Сначала одобрите версию скилла.',
            'off' => $this->skills->setStatus($name, Skill::DISABLED) ? sprintf('Скилл «%s» выключен.', $name) : 'Нечего выключать.',
            'history' => "Принятые версии:\n" . implode("\n", array_map(static fn(SkillVersion $v): string => sprintf('v%d — %s', $v->version, $v->description), $this->skills->history($name))),
            'rollback' => ($rolled = $this->skills->rollback($name, (int) ($parts[2] ?? 0))) === null
                ? 'Такой принятой версии нет. /skill ' . $name . ' history'
                : sprintf('Скилл «%s» откатан к версии %d.', $name, $rolled->version),
            default => '/skill <имя> show|on|off|history|rollback <версия>',
        };
    }
}
