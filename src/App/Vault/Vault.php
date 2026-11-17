<?php

declare(strict_types=1);

namespace OpenCCK\App\Vault;

use DomainException;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Repository\SecretRepository;
use SensitiveParameter;

/**
 * Secrets encrypted with the master key: libsodium XChaCha20-Poly1305 with the name as associated data, a fresh nonce
 * for every value. A secret is read by its
 * author and the owner, and only by code that needs it at the moment of a call — the model never gets the value.
 *
 * spec: BRIEF.md#фаза-7-по-частям — a secret is its own kind of record, kept encrypted, rights of its author and the
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
    ) {
        if ($key !== null && strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new DomainException('SECRETS_KEY must be 32 bytes');
        }
        $this->key = $key;
    }

    public function enabled(): bool {
        return $this->key !== null;
    }

    /** @throws DomainException when the vault is off, the name or kind is wrong, or the name is taken */
    public function put(string $name, string $kind, #[SensitiveParameter] string $value, Principal $author): int {
        $key = $this->key ?? throw new DomainException('Хранилище секретов выключено: в .env нет SECRETS_KEY.');
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DomainException('Имя секрета — буквы, цифры, точка, дефис, подчёркивание; до 64 символов.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new DomainException('Вид секрета — password, private_key или token.');
        }
        if ($value === '' || strlen($value) > self::VALUE_MAX) {
            throw new DomainException('Пустой или слишком длинный секрет.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return $this->secrets->store($name, $kind, $nonce, sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, mb_strtolower($name), $nonce, $key), $author->id);
    }

    /**
     * The value, for code that uses it right now; null when there is no such secret or the reader may not have it.
     */
    public function reveal(string $name, Principal $reader): ?string {
        return $this->open($this->secrets->find($name), $reader);
    }

    public function revealById(int $id, Principal $reader): ?string {
        return $this->open($this->secrets->byId($id), $reader);
    }

    /** @return list<array{name: string, kind: string}> what the reader may see, names and kinds only */
    public function names(Principal $reader): array {
        $found = [];
        foreach ($this->secrets->all() as $secret) {
            if ($reader->isOwner() || $secret['principal'] === $reader->id) {
                $found[] = ['name' => $secret['name'], 'kind' => $secret['kind']];
            }
        }
        return $found;
    }

    public function remove(string $name, Principal $who): bool {
        $secret = $this->secrets->find($name);
        if ($secret === null || (!$who->isOwner() && $secret['principal'] !== $who->id)) {
            return false;
        }
        return $this->secrets->delete($name);
    }

    public function id(string $name): ?int {
        return $this->secrets->find($name)['id'] ?? null;
    }

    /** @param array{id: int, name: string, kind: string, nonce: string, ciphertext: string, principal: ?int}|null $secret */
    private function open(?array $secret, Principal $reader): ?string {
        if ($secret === null || $this->key === null || (!$reader->isOwner() && $secret['principal'] !== $reader->id)) {
            return null;
        }
        // The name is bound as associated data: a ciphertext moved under another name does not open.
        $value = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($secret['ciphertext'], mb_strtolower($secret['name']), $secret['nonce'], $this->key);
        return is_string($value) ? $value : null;
    }
}
