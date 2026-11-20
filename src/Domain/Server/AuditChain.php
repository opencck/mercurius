<?php

declare(strict_types=1);

namespace OpenCCK\Domain\Server;

/**
 * The hash that links a record of the command journal to the one before it.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh
 */
final class AuditChain {
    public const GENESIS = '0';

    /**
     * @param array{at: string, principal: ?int, run: ?int, server: string, command: string, class: string, event: string, exit: ?int, ms: ?int, error: ?string} $record
     */
    public static function hash(array $record, string $previous): string {
        $fields = [
            $record['at'],
            $record['principal'],
            $record['run'],
            $record['server'],
            $record['command'],
            $record['class'],
            $record['event'],
            $record['exit'],
            $record['ms'],
            $record['error'],
        ];
        return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n" . $previous);
    }
}
