<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Entity;

/**
 * What a role lets a participant do beyond talking. The permission owner makes its holder an owner of the agent and
 * gives every other permission.
 *
 * spec: brief/participants.md#свои-роли
 */
enum Permission: string {
    /** an owner of the agent: every permission, the roles and their permissions */
    case Owner = 'owner';
    /** installs, sets up and removes MCP servers */
    case Mcp = 'mcp';
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
    /** own mailboxes: reading and sending mail through them */
    case Mail = 'mail';
    /** reports and traces, without the private chats of others */
    case Reports = 'reports';
}
