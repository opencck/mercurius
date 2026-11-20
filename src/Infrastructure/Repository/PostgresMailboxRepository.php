<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Repository;

use Amp\Postgres\PostgresLink;
use Amp\Sql\SqlQueryError;
use DomainException;
use OpenCCK\Domain\Mail\Mailbox;
use OpenCCK\Domain\Repository\MailboxRepository;
use UnexpectedValueException;

use function Mercurius\I18n\t;

final class PostgresMailboxRepository implements MailboxRepository {
    private const COLUMNS = 'id, principal_id, name, host, imap_port, smtp_port, username, from_address, authserv_id, secret_id, project_id';

    public function __construct(private readonly PostgresLink $db) {}

    public function add(int $ownerId, string $name, string $host, int $imapPort, int $smtpPort, string $username, string $from, string $authservId, int $secretId): Mailbox {
        try {
            $row = $this->db->execute(
                'INSERT INTO mailboxes (principal_id, name, host, imap_port, smtp_port, username, from_address, authserv_id, secret_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING ' . self::COLUMNS,
                [$ownerId, $name, $host, $imapPort, $smtpPort, $username, $from, $authservId, $secretId],
            )->fetchRow();
        } catch (SqlQueryError) {
            throw new DomainException(sprintf(t('mail.mailbox.exists'), $name));
        }
        return self::mailbox($row ?? throw new UnexpectedValueException('Mailbox was not added'));
    }

    public function find(int $id): ?Mailbox {
        $row = $this->db->execute('SELECT ' . self::COLUMNS . ' FROM mailboxes WHERE id = ?', [$id])->fetchRow();
        return $row === null ? null : self::mailbox($row);
    }

    public function of(int $ownerId): array {
        $found = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM mailboxes WHERE principal_id = ? ORDER BY lower(name)', [$ownerId]) as $row) {
            $found[] = self::mailbox($row);
        }
        return $found;
    }

    public function ofProject(int $projectId): array {
        $found = [];
        foreach ($this->db->execute('SELECT ' . self::COLUMNS . ' FROM mailboxes WHERE project_id = ? ORDER BY lower(name)', [$projectId]) as $row) {
            $found[] = self::mailbox($row);
        }
        return $found;
    }

    public function setProject(int $id, ?int $projectId): ?Mailbox {
        $row = $this->db->execute('UPDATE mailboxes SET project_id = ? WHERE id = ? RETURNING ' . self::COLUMNS, [$projectId, $id])->fetchRow();
        return $row === null ? null : self::mailbox($row);
    }

    public function remove(int $id): bool {
        return $this->db->execute('DELETE FROM mailboxes WHERE id = ? RETURNING id', [$id])->fetchRow() !== null;
    }

    /** @param array<string, mixed> $row */
    private static function mailbox(array $row): Mailbox {
        $id = $row['id'] ?? null;
        $owner = $row['principal_id'] ?? null;
        $name = $row['name'] ?? null;
        $host = $row['host'] ?? null;
        $imap = $row['imap_port'] ?? null;
        $smtp = $row['smtp_port'] ?? null;
        $username = $row['username'] ?? null;
        $from = $row['from_address'] ?? null;
        $authserv = $row['authserv_id'] ?? null;
        $secret = $row['secret_id'] ?? null;
        if (!is_int($id) || !is_int($owner) || !is_string($name) || !is_string($host) || !is_int($imap) || !is_int($smtp) || !is_string($username) || !is_string($from) || !is_string($authserv) || !is_int($secret)) {
            throw new UnexpectedValueException('Malformed mailbox row');
        }
        return new Mailbox($id, $owner, $name, $host, $imap, $smtp, $username, $from, $authserv, $secret, isset($row['project_id']) && is_int($row['project_id']) ? $row['project_id'] : null);
    }
}
