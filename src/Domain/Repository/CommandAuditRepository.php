<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Repository;

/**
 * The journal of commands on servers: who, where, what, how it ended. Every record carries the hash of the one before
 * it, so a record changed or removed afterwards breaks the chain.
 *
 * spec: BRIEF.md#фаза-7-по-частям — a journal of every command that cannot be changed unnoticed
 * ref: references/ssh-mcp/src/audit/store.ts@e04ebf2:L78-L87, L120-L146 — prevHash and selfHash = sha256(record +
 *      prevHash); here in the database under a lock instead of a file
 */
interface CommandAuditRepository {
    /**
     * @param array{principal: ?int, run: ?int, server: string, command: string, class: string, event: string, exit: ?int, ms: ?int, error: ?string} $record
     * @return int the record's id
     */
    public function append(array $record): int;

    /** @return int|null the id of the first record whose hash does not hold; null when the chain is whole */
    public function verify(): ?int;

    /** @return list<array{id: int, at: string, server: string, command: string, class: string, event: string, exit: ?int, who: ?string}> newest first; who — the participant's name, or #number */
    public function recent(int $limit): array;
}
