<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

use DomainException;
use OpenCCK\Domain\Mail\Mailbox;

/**
 * spec: brief/skills-mcp.md#ящики-участников-и-проектов
 */
interface MailboxRepository {
    /** @throws DomainException when the owner has a mailbox of this name */
    public function add(int $ownerId, string $name, string $host, int $imapPort, int $smtpPort, string $username, string $from, string $authservId, int $secretId): Mailbox;

    public function find(int $id): ?Mailbox;

    /** @return list<Mailbox> by name */
    public function of(int $ownerId): array;

    /** @return list<Mailbox> by name */
    public function ofProject(int $projectId): array;

    public function setProject(int $id, ?int $projectId): ?Mailbox;

    public function remove(int $id): bool;
}
