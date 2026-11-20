<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Mail;

/**
 * A mailbox of a participant: the server, the login and the secret of the vault that opens it. It may be given to a
 * project of its owner; then the chats and the jobs of that project use it.
 *
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
final class Mailbox {
    public const NAME_PATTERN = '~^[\p{L}\p{N}][\p{L}\p{N}._-]{0,63}$~u';
    /** a name of a host, not an address: the mail server checks the certificate by it */
    public const HOST_PATTERN = '~^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$~i';
    /** no spaces and no control characters: the login travels in a header */
    public const USER_PATTERN = '~^[\x21-\x7e]{1,254}$~';
    public const FROM_PATTERN = '~^[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9.-]{1,253}$~';
    /** IMAP over TLS from the first byte */
    public const IMAP_PORTS = [993];
    /** SMTP: 465 — TLS, 587 — STARTTLS */
    public const SMTP_PORTS = [465, 587];
    /** mailboxes of one participant */
    public const MAX_PER_OWNER = 10;

    public function __construct(
        public readonly int $id,
        /** who connected it and decides what is sent from it */
        public readonly int $ownerId,
        public readonly string $name,
        public readonly string $host,
        public readonly int $imapPort,
        public readonly int $smtpPort,
        public readonly string $username,
        public readonly string $from,
        /** the authserv-id of the receiving server in Authentication-Results; '' — the host */
        public readonly string $authservId,
        public readonly int $secretId,
        public readonly ?int $projectId = null,
    ) {}
}
