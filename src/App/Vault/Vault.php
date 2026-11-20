<?php

declare(strict_types=1);

namespace OpenCCK\App\Vault;

use DomainException;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\SecretRepository;
use SensitiveParameter;

use function Mercurius\I18n\t;

/**
 * Secrets encrypted with the master key: libsodium XChaCha20-Poly1305 with the name as associated data, a fresh nonce
 * for every value. A secret is read by its
 * author and the owner, and only by code that needs it at the moment of a call — the model never gets the value.
 *
 * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a secret is its own kind of record, kept encrypted, rights of its author and the
 *       owner; the master key is SECRETS_KEY in .env
 */
final class Vault {
    public const KINDS = ['password', 'private_key', 'token'];
    public const NAME_PATTERN = '~^[\p{L}\p{N}][\p{L}\p{N}._-]{0,63}$~u';
    public const VALUE_MAX = 16_384;

    private readonly ?string $key;

    public function __construct(
        private readonly SecretRepository $secrets,
        #[SensitiveParameter] ?string $key,
        /** @var (\Closure(?int): bool)|null whose secrets the owner does not read: former owners keep theirs */
        private readonly ?\Closure $hidden = null,
    ) {
        if ($key !== null && strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new DomainException('SECRETS_KEY must be 32 bytes');
        }
        $this->key = $key;
    }

    private ?\OpenCCK\Domain\Repository\ProjectLinkRepository $projectLinks = null;

    /** spec: brief/participants.md#ресурсы-проекта — a secret given to a project is used by those who work in it */
    public function useProjects(\OpenCCK\Domain\Repository\ProjectLinkRepository $links): void {
        $this->projectLinks = $links;
    }

    /** Who stored the secret; null — no such secret, or its author is gone. */
    public function authorOf(string $name): ?int {
        return $this->secrets->find($name)['principal'] ?? null;
    }

    /** Whether the reader may use the secret as their own, without opening it. */
    public function readable(string $name, Principal $reader): bool {
        $secret = $this->secrets->find($name);
        return $secret !== null && $this->mayRead($secret['principal'], $reader);
    }

    /** A host fits a secret given for a site: the site itself or a name under it. */
    public static function hostFits(string $host, string $site): bool {
        $host = strtolower(trim($host, '.'));
        $site = strtolower(trim($site, '.'));
        return $site !== '' && ($host === $site || str_ends_with($host, '.' . $site));
    }

    /**
     * The author of a secret given to this project for this host; null — the secret is not the project's, or the
     * request goes elsewhere. A secret of a project never leaves for another site.
     *
     * spec: brief/participants.md#ресурсы-проекта
     */
    public function sharedWith(string $name, int $projectId, string $host): ?int {
        $secret = $this->secrets->find($name);
        $binding = $secret === null ? null : $this->projectLinks?->secretBinding($secret['id']);
        if ($secret === null || $binding === null || $binding['projectId'] !== $projectId || !self::hostFits($host, $binding['host'])) {
            return null;
        }
        return $secret['principal'];
    }

    /** The value of a secret of the project, for a request to its site; the caller has checked the right to work in the project. */
    public function revealShared(string $name, int $projectId, string $host): ?string {
        if ($this->sharedWith($name, $projectId, $host) === null) {
            return null;
        }
        return self::known($this->decrypt($this->secrets->find($name)));
    }

    public function enabled(): bool {
        return $this->key !== null;
    }

    /** @throws DomainException when the vault is off, the name or kind is wrong, or the name is taken */
    public function put(string $name, string $kind, #[SensitiveParameter] string $value, Principal $author): int {
        $key = $this->key ?? throw new DomainException(t('vault.vault.hranilische_sekretov_vyklyucheno_v_env'));
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('vault.vault.imya_sekreta_bukvy_tsifry_tochka'));
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new DomainException(t('vault.vault.vid_sekreta_password_private_key'));
        }
        if ($value === '' || strlen($value) > self::VALUE_MAX) {
            throw new DomainException(t('vault.vault.pustoy_ili_slishkom_dlinnyy_sekret'));
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return $this->secrets->store($name, $kind, $nonce, sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, mb_strtolower($name), $nonce, $key), $author->id);
    }

    /**
     * The value, for code that uses it right now; null when there is no such secret or the reader may not have it.
     */
    public function reveal(string $name, Principal $reader): ?string {
        return self::known($this->open($this->secrets->find($name), $reader));
    }

    public function revealById(int $id, Principal $reader): ?string {
        return self::known($this->open($this->secrets->byId($id), $reader));
    }

    /** A value that left the vault is masked wherever it shows up later (output, logs, traces). */
    private static function known(?string $value): ?string {
        if ($value !== null) {
            \OpenCCK\App\Agent\Tool\Guard\SecretMasker::remember($value);
        }
        return $value;
    }

    /** @return list<array{name: string, kind: string}> what the reader may see, names and kinds only */
    public function names(Principal $reader): array {
        $found = [];
        foreach ($this->secrets->all() as $secret) {
            if ($this->mayRead($secret['principal'], $reader)) {
                $found[] = ['name' => $secret['name'], 'kind' => $secret['kind']];
            }
        }
        return $found;
    }

    public function remove(string $name, Principal $who): bool {
        $secret = $this->secrets->find($name);
        if ($secret === null || !$this->mayRead($secret['principal'], $who)) {
            return false;
        }
        return $this->secrets->delete($name);
    }

    public function id(string $name): ?int {
        return $this->secrets->find($name)['id'] ?? null;
    }

    /**
     * The author reads their secret; the owner reads everyone's but a former owner's.
     *
     * spec: brief/participants.md#несколько-пользователей — the personal stays with the former owner
     */
    private function mayRead(?int $author, Principal $reader): bool {
        return $author === $reader->id || ($reader->isOwner() && ($this->hidden === null || !($this->hidden)($author)));
    }

    /** @param array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}|null $secret */
    private function open(?array $secret, Principal $reader): ?string {
        return $secret === null || !$this->mayRead($secret['principal'], $reader) ? null : $this->decrypt($secret);
    }

    /** @param array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}|null $secret */
    private function decrypt(?array $secret): ?string {
        if ($secret === null || $this->key === null) {
            return null;
        }
        // The name is bound as associated data: a ciphertext moved under another name does not open.
        $value = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($secret['ciphertext'], mb_strtolower($secret['name']), $secret['nonce'], $this->key);
        return is_string($value) ? $value : null;
    }
}
