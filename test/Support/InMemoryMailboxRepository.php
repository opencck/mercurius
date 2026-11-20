<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use DomainException;
use OpenCCK\Domain\Mail\Mailbox;
use OpenCCK\Domain\Repository\MailboxRepository;

final class InMemoryMailboxRepository implements MailboxRepository {
    /** @var array<int, Mailbox> */
    public array $mailboxes = [];
    private int $next = 1;

    public function add(int $ownerId, string $name, string $host, int $imapPort, int $smtpPort, string $username, string $from, string $authservId, int $secretId): Mailbox {
        foreach ($this->of($ownerId) as $mailbox) {
            if (mb_strtolower($mailbox->name) === mb_strtolower($name)) {
                throw new DomainException(sprintf('Ящик «%s» уже есть.', $name));
            }
        }
        $id = $this->next++;
        return $this->mailboxes[$id] = new Mailbox($id, $ownerId, $name, $host, $imapPort, $smtpPort, $username, $from, $authservId, $secretId);
    }

    public function find(int $id): ?Mailbox {
        return $this->mailboxes[$id] ?? null;
    }

    public function of(int $ownerId): array {
        return self::sorted(array_filter($this->mailboxes, static fn(Mailbox $m): bool => $m->ownerId === $ownerId));
    }

    public function ofProject(int $projectId): array {
        return self::sorted(array_filter($this->mailboxes, static fn(Mailbox $m): bool => $m->projectId === $projectId));
    }

    public function setProject(int $id, ?int $projectId): ?Mailbox {
        $m = $this->mailboxes[$id] ?? null;
        if ($m === null) {
            return null;
        }
        return $this->mailboxes[$id] = new Mailbox($m->id, $m->ownerId, $m->name, $m->host, $m->imapPort, $m->smtpPort, $m->username, $m->from, $m->authservId, $m->secretId, $projectId);
    }

    public function remove(int $id): bool {
        $had = isset($this->mailboxes[$id]);
        unset($this->mailboxes[$id]);
        return $had;
    }

    /**
     * @param array<int, Mailbox> $found
     * @return list<Mailbox>
     */
    private static function sorted(array $found): array {
        $found = array_values($found);
        usort($found, static fn(Mailbox $a, Mailbox $b): int => strcmp(mb_strtolower($a->name), mb_strtolower($b->name)));
        return $found;
    }
}
