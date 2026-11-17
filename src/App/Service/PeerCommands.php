<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PeerRepository;

/**
 * The owner's commands for the network of agents.
 *
 * spec: BRIEF.md#сеть-агентов — the owner lets agents in by the fingerprint of their key and adds neighbours
 */
final class PeerCommands {
    public function __construct(
        private readonly PeerNetwork $network,
        private readonly PeerRepository $peers,
    ) {}

    public function handle(Principal $owner, string $argument): string {
        $parts = preg_split('~\s+~', trim($argument)) ?: [];
        $action = $parts[0] ?? '';
        try {
            return match ($action) {
                '' => $this->network->text(),
                'allow' => $this->network->allow($parts[1] ?? '', $parts[2] ?? '', Role::tryFrom($parts[3] ?? 'user') ?? Role::Visitor),
                'revoke' => $this->network->revoke($parts[1] ?? '') ? sprintf('Агент «%s» больше не подключится.', $parts[1] ?? '') : 'Такого допущенного агента нет.',
                'add' => $this->network->add($owner, $parts[1] ?? '', $parts[2] ?? '', $parts[3] ?? ''),
                'remove' => $this->peers->removePeer($parts[1] ?? '') ? 'Сосед удалён.' : 'Такого соседа нет.',
                default => '/peers — список и мой ключ; /peer allow <имя> <отпечаток> [user|admin] — пускать агента с этим ключом; /peer revoke <имя>; '
                    . '/peer add <имя> <https://адрес> <секрет с токеном входа соседа> — сосед, к которому подключаюсь я; /peer remove <имя>.',
            };
        } catch (DomainException $e) {
            return $e->getMessage();
        }
    }
}
