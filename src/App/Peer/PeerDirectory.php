<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Closure;
use Mercurius\Peer\A2aClient;
use Mercurius\Peer\AgentIdentity;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\PeerRepository;

/**
 * The directory of the network of agents: this agent, the neighbours it connects to, the live connections both ways
 * and, for people, the agents it lets in — with addresses, transports and public keys, so another agent can reach
 * them. Never a token or the name of its secret, never a private key or which of our keys a neighbour sees. An agent
 * the owner hid is counted but shown only to an owner of the agent, marked.
 *
 * spec: brief/network.md#справочник-сети
 */
final class PeerDirectory {
    /**
     * @param Closure(): list<array{direction: string, name: string, fingerprint: string, opened: float}> $links the live connections
     * @param Closure(): array{name: string, url: string, transport: string, ws: string, fingerprint: string, publicKey: string} $self this agent
     */
    public function __construct(
        private readonly PeerRepository $peers,
        private readonly Closure $links,
        private readonly Closure $self,
    ) {}

    /**
     * @return array{
     *     self: array{name: string, url: string, transport: string, ws: string, fingerprint: string, publicKey: string},
     *     neighbours: list<array<array-key, mixed>>,
     *     connected: list<array<array-key, mixed>>,
     *     allowed?: list<array<array-key, mixed>>,
     *     counts: array{total: int, visible: int}
     * }
     */
    public function of(Member $who): array {
        $owner = $who->isOwner();
        $hidden = array_fill_keys($this->peers->hidden(), true);
        $links = ($this->links)();
        $live = array_fill_keys(array_column($links, 'name'), true);
        $keys = array_values(array_filter($this->peers->keys(), static fn(array $k): bool => !$k['revoked']));
        $known = [];
        $shown = [];
        /**
         * @param array<string, mixed> $row
         * @return array<array-key, mixed>|null null — hidden from this reader
         */
        $show = static function (string $name, array $row) use ($owner, $hidden, &$known, &$shown): ?array {
            $known[$name] = true;
            $isHidden = isset($hidden[$name]);
            if ($isHidden && !$owner) {
                return null;
            }
            if (!$isHidden) {
                $shown[$name] = true;
            }
            return $owner ? $row + ['hidden' => $isHidden] : $row;
        };

        $neighbours = [];
        foreach ($this->peers->peers() as $peer) {
            $row = $show($peer->name, [
                'name' => $peer->name,
                'url' => $peer->url,
                'transport' => A2aClient::transport($peer),
                'ws' => A2aClient::socketUrl($peer),
                'fingerprint' => AgentIdentity::fingerprintOf($peer->publicKey) ?? '',
                'publicKey' => $peer->publicKey,
                'connected' => isset($live[$peer->name]),
            ]);
            if ($row !== null) {
                $neighbours[] = $row;
            }
        }
        $connected = [];
        foreach ($links as $link) {
            $row = $show($link['name'], [
                'name' => $link['name'],
                'direction' => $link['direction'],
                'fingerprint' => $link['fingerprint'],
                'minutes' => (int) round((microtime(true) - $link['opened']) / 60.0),
            ]);
            if ($row !== null) {
                $connected[] = $row;
            }
        }
        $directory = ['self' => ($this->self)(), 'neighbours' => $neighbours, 'connected' => $connected];
        // whom the agent lets in is told to people only; to an agent such an agent is only counted
        if ($who->isAgent()) {
            foreach ($keys as $key) {
                $known[$key['name']] = true;
            }
        } else {
            $allowed = [];
            foreach ($keys as $key) {
                $row = $show($key['name'], ['name' => $key['name'], 'fingerprint' => $key['fingerprint']]);
                if ($row !== null) {
                    $allowed[] = $row;
                }
            }
            $directory['allowed'] = $allowed;
        }
        $directory['counts'] = ['total' => count($known), 'visible' => count($shown)];
        return $directory;
    }
}
