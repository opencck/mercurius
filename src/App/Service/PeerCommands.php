<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use DomainException;
use OpenCCK\App\Peer\PeerNetwork;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\PeerRepository;

use function Mercurius\I18n\t;

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
                'allow' => $this->network->allow($parts[1] ?? '', $parts[2] ?? '', Role::tryFrom($parts[3] ?? 'user') ?? Role::visitor()),
                'revoke' => $this->network->revoke($parts[1] ?? '') ? sprintf(t('service.peer_commands.agent_bolshe_ne_podklyuchitsya'), $parts[1] ?? '') : t('service.peer_commands.takogo_dopuschennogo_agenta_net'),
                'add' => $this->network->add($owner, $parts[1] ?? '', $parts[2] ?? '', $parts[3] ?? '', $parts[4] ?? ''),
                // spec: BRIEF.md#вход-по-websocket-и-несколько-ключей-решение-владельца-2026-11-17 — which key a neighbour sees
                // spec: BRIEF.md#подключения-агентов-решение-владельца-2026-11-17
                'open' => $this->network->open($parts[1] ?? ''),
                'close' => $this->network->closeLink((int) ltrim($parts[1] ?? '0', '#')) ? t('tool.peer_links.closed') : t('tool.peer_links.no_link'),
                'key' => $this->network->useKey($parts[1] ?? '', $parts[2] ?? ''),
                'remove' => $this->peers->removePeer($parts[1] ?? '') ? t('service.peer_commands.sosed_udalen') : t('service.peer_commands.takogo_soseda_net'),
                default => t('service.peer_commands.peers_spisok_i_moy_klyuch')
                    . t('service.peer_commands.peer_add_imya_https_adres'),
            };
        } catch (DomainException $e) {
            return $e->getMessage();
        }
    }
}
