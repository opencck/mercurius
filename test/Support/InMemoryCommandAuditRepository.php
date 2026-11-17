<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use OpenCCK\Domain\Repository\CommandAuditRepository;
use OpenCCK\Domain\Server\AuditChain;
use RuntimeException;

final class InMemoryCommandAuditRepository implements CommandAuditRepository {
    /** @var list<array{id: int, at: string, principal: ?int, run: ?int, server: string, command: string, class: string, event: string, exit: ?int, ms: ?int, error: ?string, prev: string, hash: string}> */
    public array $records = [];
    /** fails the next appends when set: a journal that cannot be written */
    public bool $broken = false;

    public function append(array $record): int {
        if ($this->broken) {
            throw new RuntimeException('journal is down');
        }
        $previous = $this->records === [] ? AuditChain::GENESIS : $this->records[count($this->records) - 1]['hash'];
        $full = ['at' => date('c')] + $record;
        $id = count($this->records) + 1;
        $this->records[] = ['id' => $id] + $full + ['prev' => $previous, 'hash' => AuditChain::hash($full, $previous)];
        return $id;
    }

    /** Changes a record behind the chain's back, as an intruder would. */
    public function tamper(int $index, string $command): void {
        $records = [];
        foreach ($this->records as $i => $record) {
            $records[] = $i === $index ? ['command' => $command] + $record : $record;
        }
        $this->records = $records;
    }

    public function verify(): ?int {
        $previous = AuditChain::GENESIS;
        foreach ($this->records as $r) {
            $fields = ['at' => $r['at'], 'principal' => $r['principal'], 'run' => $r['run'], 'server' => $r['server'], 'command' => $r['command'], 'class' => $r['class'], 'event' => $r['event'], 'exit' => $r['exit'], 'ms' => $r['ms'], 'error' => $r['error']];
            if ($r['prev'] !== $previous || $r['hash'] !== AuditChain::hash($fields, $previous)) {
                return $r['id'];
            }
            $previous = $r['hash'];
        }
        return null;
    }

    public function recent(int $limit): array {
        $found = [];
        foreach (array_slice(array_reverse($this->records), 0, $limit) as $r) {
            $found[] = ['id' => $r['id'], 'at' => $r['at'], 'server' => $r['server'], 'command' => $r['command'], 'class' => $r['class'], 'event' => $r['event'], 'exit' => $r['exit']];
        }
        return $found;
    }
}
