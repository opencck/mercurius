<?php

declare(strict_types=1);

namespace OpenCCK\App\Vault;

use DomainException;
use OpenCCK\Domain\Entity\Member;
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
    /** where a value named in an entry of .mcp.json came from: the narrowest layer of the context that has the name */
    public const LAYER_PROJECT = 'project';
    public const LAYER_OWNER = 'owner';
    public const LAYER_AGENT = 'agent';
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

    /** Who stored the secret; null — no such secret, or its author is gone. */
    public function authorOf(string $name): ?int {
        return $this->secrets->find($name)['principal'] ?? null;
    }

    /** Whether the reader may use the secret as their own, without opening it. */
    public function readable(string $name, Member $reader): bool {
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
     * The author of the project's secret with this name for this host; null — the project has no such secret, or the
     * request goes elsewhere. A secret of a project never leaves for another site.
     *
     * spec: brief/participants.md#секреты-проекта
     */
    public function sharedWith(string $name, int $projectId, string $host): ?int {
        $secret = $this->secrets->findIn($name, $projectId);
        if ($secret === null || !self::hostFits($host, $secret['site'])) {
            return null;
        }
        return $secret['principal'] ?? 0;
    }

    /** The value of a secret of the project, for a request to its site; the caller has checked the right to work in the project. */
    public function revealShared(string $name, int $projectId, string $host): ?string {
        $secret = $this->secrets->findIn($name, $projectId);
        if ($secret === null || !self::hostFits($host, $secret['site'])) {
            return null;
        }
        return self::known($this->decrypt($secret));
    }

    /**
     * Keeps a secret of a project for one site; the same name again takes the new value and site. True when it was
     * there before. The caller has checked that the author is the owner of the project or of the agent.
     *
     * spec: brief/participants.md#секреты-проекта
     *
     * @throws DomainException when the vault is off, the name, site or value is wrong
     */
    public function putProject(int $projectId, string $name, string $site, #[SensitiveParameter] string $value, Member $author): bool {
        [$kind, $nonce, $cipher] = $this->seal($name, $value);
        if ($this->secrets->findIn($name, $projectId) !== null) {
            $this->secrets->replaceIn($name, $projectId, $site, $kind, $nonce, $cipher);
            return true;
        }
        $this->secrets->storeIn($projectId, $site, $name, $kind, $nonce, $cipher, $author->id);
        return false;
    }

    /** @return list<array{name: string, kind: string, site: string}> the names and sites of a project's secrets, never values */
    public function projectNames(int $projectId): array {
        return $this->secrets->ofProject($projectId);
    }

    /**
     * Keeps a secret of the agent, the widest layer; the same name again takes the new value. True when it was there
     * before. The caller has checked that the author is an owner of the agent.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов
     *
     * @throws DomainException when the vault is off, the name or value is wrong
     */
    public function putAgent(string $name, #[SensitiveParameter] string $value, Member $author): bool {
        [$kind, $nonce, $cipher] = $this->seal($name, $value);
        if ($this->secrets->replaceAgent($name, $kind, $nonce, $cipher)) {
            return true;
        }
        $this->secrets->storeAgent($name, $kind, $nonce, $cipher, $author->id);
        return false;
    }

    /** @return list<array{name: string, kind: string}> the names of the agent's secrets, never values */
    public function agentNames(): array {
        return $this->secrets->ofAgent();
    }

    public function removeAgent(string $name): bool {
        return $this->secrets->deleteAgent($name);
    }

    /**
     * The value an entry of .mcp.json gets by a name, from the narrowest layer of its context that has it: the
     * project, the owner of the context (of the project, or the member of a personal context), the agent. Null — no
     * layer has it. Only code that calls the server asks; the value is remembered for masking.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов
     *
     * @return array{string, string}|null the value and its layer
     */
    public function layered(string $name, ?int $projectId, int $ownerId): ?array {
        $project = $projectId === null ? null : $this->decrypt($this->secrets->findIn($name, $projectId));
        if ($project !== null && $project !== '') {
            return [(string) self::known($project), self::LAYER_PROJECT];
        }
        $personal = $this->secrets->find($name);
        $owned = $personal !== null && $personal['principal'] === $ownerId ? $this->decrypt($personal) : null;
        if ($owned !== null && $owned !== '') {
            return [(string) self::known($owned), self::LAYER_OWNER];
        }
        $agent = $this->decrypt($this->secrets->findAgent($name));
        if ($agent !== null && $agent !== '') {
            return [(string) self::known($agent), self::LAYER_AGENT];
        }
        return null;
    }

    /**
     * The kind, nonce and ciphertext of a value under a name.
     *
     * @return array{string, string, string}
     * @throws DomainException
     */
    private function seal(string $name, #[SensitiveParameter] string $value): array {
        $key = $this->key ?? throw new DomainException(t('vault.vault.hranilische_sekretov_vyklyucheno_v_env'));
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainException(t('vault.vault.imya_sekreta_bukvy_tsifry_tochka'));
        }
        if ($value === '' || strlen($value) > self::VALUE_MAX) {
            throw new DomainException(t('vault.vault.pustoy_ili_slishkom_dlinnyy_sekret'));
        }
        $kind = str_starts_with($value, '-----BEGIN') ? 'private_key' : 'password';
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return [$kind, $nonce, sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, mb_strtolower($name), $nonce, $key)];
    }

    public function removeProject(string $name, int $projectId): bool {
        return $this->secrets->deleteIn($name, $projectId);
    }

    public function enabled(): bool {
        return $this->key !== null;
    }

    /** @throws DomainException when the vault is off, the name or kind is wrong, or the name is taken */
    /**
     * Keeps a secret. $replace: an existing one with this name gets the new value when the author may read it (spec:
     * brief/servers.md#как-сделано-хранилище-реестр-и-ssh — a wrong value is fixed by sending it again); without it an
     * existing name is refused.
     */
    public function put(string $name, string $kind, #[SensitiveParameter] string $value, Member $author, bool $replace = false): int {
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
        $existing = $replace ? $this->secrets->find($name) : null;
        if ($existing !== null) {
            if (!$this->mayRead($existing['principal'], $author)) {
                throw new DomainException(sprintf(t('repository.postgres_secret.sekret_uzhe_est'), $name));
            }
            $this->secrets->replace($name, $kind, $nonce, sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, mb_strtolower($name), $nonce, $key));
            return $existing['id'];
        }
        return $this->secrets->store($name, $kind, $nonce, sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, mb_strtolower($name), $nonce, $key), $author->id);
    }

    /**
     * The value, for code that uses it right now; null when there is no such secret or the reader may not have it.
     */
    public function reveal(string $name, Member $reader): ?string {
        return self::known($this->open($this->secrets->find($name), $reader));
    }

    public function revealById(int $id, Member $reader): ?string {
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
    public function names(Member $reader): array {
        $found = [];
        foreach ($this->secrets->all() as $secret) {
            if ($this->mayRead($secret['principal'], $reader)) {
                $found[] = ['name' => $secret['name'], 'kind' => $secret['kind']];
            }
        }
        return $found;
    }

    public function remove(string $name, Member $who): bool {
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
    private function mayRead(?int $author, Member $reader): bool {
        return $author === $reader->id || ($reader->isOwner() && ($this->hidden === null || !($this->hidden)($author)));
    }

    /** @param array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}|null $secret */
    private function open(?array $secret, Member $reader): ?string {
        return $secret === null || !$this->mayRead($secret['principal'], $reader) ? null : $this->decrypt($secret);
    }

    /** @param array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int, site?: string}|null $secret */
    private function decrypt(?array $secret): ?string {
        if ($secret === null || $this->key === null) {
            return null;
        }
        // The name is bound as associated data: a ciphertext moved under another name does not open.
        $value = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($secret['ciphertext'], mb_strtolower($secret['name']), $secret['nonce'], $this->key);
        return is_string($value) ? $value : null;
    }
}
