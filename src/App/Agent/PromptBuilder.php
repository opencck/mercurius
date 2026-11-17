<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\App\Agent\Tool\UntrustedWrapper;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;

/**
 * The system prompt of a conversation, built once when it opens and kept unchanged until it ends (prompt cache).
 * Tiers by how often they change: constant rules → context of the participant and the date → volatile (empty for now).
 * Anything new in the middle of a conversation goes into a user message or a tool result instead.
 *
 * spec: BRIEF.md#контекст — three tiers, unchanged during the conversation
 */
final class PromptBuilder {
    /** @param string $core memory layer 1, given only to the owner in a private chat */
    public static function system(Principal $principal, DateTimeImmutable $openedAt, string $core = '', string $project = ''): string {
        $who = $principal->displayName ?? ($principal->kind === PrincipalKind::Agent ? 'другой агент без имени' : 'участник без имени');
        $role = match ($principal->role) {
            Role::Owner => 'владелец',
            Role::Admin => 'администратор',
            Role::User => 'пользователь',
            Role::Visitor => 'посетитель: только разговор, без инструментов и без сведений о делах владельца',
        };
        return SystemPrompt::text() . "\n\n" . sprintf(
            "Контекст:\n- Собеседник: %s, %s.\n- Диалог начат: %s.",
            $who,
            $role,
            $openedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
        ) . ($core === '' ? '' : "\n\n" . UntrustedWrapper::wrap('memory', "Память (слой 1), справочные данные:\n" . $core))
            . ($project === '' ? '' : "\n\n" . UntrustedWrapper::wrap('project', "Проект этого чата, справочные данные:\n" . $project));
    }
}
