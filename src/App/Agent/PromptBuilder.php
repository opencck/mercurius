<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use DateTimeImmutable;
use DateTimeZone;
use OpenCCK\App\Agent\Tool\Guard\UntrustedWrapper;
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
 * spec: brief/agent-loop.md#контекст — three tiers, unchanged during the conversation
 */
final class PromptBuilder {
    /**
     * @param string $core memory layer 1, given only to the owner in a private chat
     * @param string $channel the channel of the conversation: a channel other than Telegram is named to the agent
     * @param string $instance what the agent knows about its own installation (the network of agents: its address and
     *                         how a neighbour connects), the same for every conversation
     * @param string $notes rules the owner accepted from the agent's proposals (the active version of prompt notes):
     *                      they come right after the constant rules (spec: brief/self-improvement.md#самоулучшение)
     */
    public static function system(Principal $principal, DateTimeImmutable $openedAt, string $core = '', string $project = '', string $skills = '', string $channel = '', string $notes = '', string $instance = ''): string {
        $who = $principal->displayName ?? ($principal->kind === PrincipalKind::Agent ? t('agent.prompt_builder.drugoy_agent_bez_imeni') : t('agent.prompt_builder.uchastnik_bez_imeni'));
        // spec: brief/participants.md#свои-роли — a role is named by the title the owner gave it
        $role = $principal->role->title() !== '' ? $principal->role->title() : match ($principal->role->value) {
            Role::OWNER => t('agent.prompt_builder.vladelets'),
            'admin' => t('agent.prompt_builder.administrator'),
            'user' => t('agent.prompt_builder.polzovatel'),
            'visitor' => t('agent.prompt_builder.posetitel_tolko_razgovor_bez_instrumento'),
            default => $principal->role->value,
        };
        return SystemPrompt::text() . (trim($notes) === '' ? '' : "\n\n" . t('agent.prompt_builder.notes') . "\n" . trim($notes)) . "\n\n" . sprintf(
            t('agent.prompt_builder.kontekst_sobesednik_dialog_nachat'),
            $who,
            $role,
            $openedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i') . ' UTC',
        ) . ($channel === 'ws' ? "\n" . t('agent.prompt_builder.channel_ws') : '')
            . ($instance === '' ? '' : "\n\n" . $instance)
            . ($core === '' ? '' : "\n\n" . UntrustedWrapper::wrap('memory', t('agent.prompt_builder.pamyat_sloy_1_spravochnye_dannye') . $core))
            . ($project === '' ? '' : "\n\n" . UntrustedWrapper::wrap('project', t('agent.prompt_builder.proekt_etogo_chata_spravochnye_dannye') . $project))
            // spec: brief/skills-mcp.md#скиллы--файлы-встроенные-участника-и-проекта — the index of the owner's accepted skills, the bodies on demand
            . ($skills === '' ? '' : "\n\n" . $skills);
    }
}
