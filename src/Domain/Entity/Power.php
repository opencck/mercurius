<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * What a role lets a participant do beyond talking. The owner has every power.
 *
 * spec: brief/participants.md#свои-роли
 */
enum Power: string {
    /** the role may be given tools */
    case Tools = 'tools';
    /** skills in the context and their files */
    case Skills = 'skills';
    /** the context of a project */
    case Projects = 'projects';
    /** own secrets in the vault */
    case Secrets = 'secrets';
    /** decides on the cards of visitors */
    case Admit = 'admit';
    /** sees the participants and changes their role and settings, but not the owner's or their own */
    case Participants = 'participants';
    /** publishes to channels on behalf of the agent */
    case Publish = 'publish';
    /** reports and traces, without the private chats of others */
    case Reports = 'reports';
}
