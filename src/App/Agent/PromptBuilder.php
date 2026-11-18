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

use function Mercurius\I18n\t;

/**
 * The system prompt of a conversation, built once when it opens and kept unchanged until it ends (prompt cache).
 * Tiers by how often they change: constant rules → context of the participant and the date → volatile (empty for now).
 * Anything new in the middle of a conversation goes into a user message or a tool result instead.
 *
 * spec: BRIEF.md#контекст — three tiers, unchanged during the conversation
 */
final class PromptBuilder {
    /** @param string $core memory layer 1, given only to the owner in a private chat */
    /** @param string $channel the channel of the conversation: a channel other than Telegram is named to the agent */
    public static function system(Principal $principal, DateTimeImmutable $openedAt, string $core = '', string $project = '', string $skills = '', string $channel = ''): string {
        $who = $principal->displayName ?? ($principal->kind === PrincipalKind::Agent ? t('agent.prompt_builder.drugoy_agent_bez_imeni') : t('agent.prompt_builder.uchastnik_bez_imeni'));
        $role = match ($principal->role) {
            Role::Owner => t('agent.prompt_builder.vladelets'),
            Role::Admin => t('agent.prompt_builder.administrator'),
            Role::User => t('agent.prompt_builder.polzovatel'),
            Role::Visitor => t('agent.prompt_builder.posetitel_tolko_razgovor_bez_instrumento'),
        };
        return SystemPrompt::text() . "\n\n" . sprintf(
            t('agent.prompt_builder.kontekst_sobesednik_dialog_nachat'),
            $who,
            $role,
            $openedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
        ) . ($channel === 'ws' ? "\n" . t('agent.prompt_builder.channel_ws') : '')
            . ($core === '' ? '' : "\n\n" . UntrustedWrapper::wrap('memory', t('agent.prompt_builder.pamyat_sloy_1_spravochnye_dannye') . $core))
            . ($project === '' ? '' : "\n\n" . UntrustedWrapper::wrap('project', t('agent.prompt_builder.proekt_etogo_chata_spravochnye_dannye') . $project))
            // spec: BRIEF.md#скиллы-и-mcp — the index of the owner's accepted skills, the bodies on demand
            . ($skills === '' ? '' : "\n\n" . $skills);
    }
}
