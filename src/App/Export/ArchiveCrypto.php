<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use InvalidArgumentException;
use SodiumException;
use Throwable;

use function Amp\File\deleteFile;
use function Amp\File\exists;
use function Amp\File\openFile;

/**
 * The archive of an agent sealed by the owner's password: `MRCX1`, a salt, then the file in chunks of 1 MB by
 * XChaCha20-Poly1305 (sodium secretstream), the key derived by Argon2id from the password. A wrong password, a changed
 * or a cut file are refused, and nothing of a failed opening is left.
 *
 * spec: brief/operations.md#экспорт-и-импорт-агента — the archive is sealed by the owner's password (decision В10)
 * ref: references/hermes-agent/hermes_cli/backup.py@e96221fa:L1-L40 — hermes zips its home without encryption; here the
 *      archive holds the secrets and their key, so it is sealed
 */
final class ArchiveCrypto {
    public const MAGIC = 'MRCX1';
    public const PASSWORD_MIN = 12;
    private const CHUNK = 1_048_576;

    public static function encrypt(string $from, string $to, #[\SensitiveParameter] string $password): void {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            throw new InvalidArgumentException(sprintf(\Mercurius\I18n\t('export.password_short'), self::PASSWORD_MIN));
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::key($password, $salt);
        /** @var array{string, string} $pushed */
        $pushed = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        [$state, $header] = $pushed;
        $in = openFile($from, 'r');
        $out = openFile($to, 'w');
        try {
            $out->write(self::MAGIC . $salt . $header);
            $chunk = $in->read(length: self::CHUNK) ?? '';
            while (true) {
                $next = $chunk === '' ? '' : ($in->read(length: self::CHUNK) ?? '');
                $last = $next === '';
                $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                $out->write(pack('N', strlen($sealed)) . $sealed);
                if ($last) {
                    break;
                }
                $chunk = $next;
            }
        } finally {
            $in->close();
            $out->close();
            sodium_memzero($key);
        }
    }

    /** @throws InvalidArgumentException a wrong password, a changed or a cut archive */
    public static function decrypt(string $from, string $to, #[\SensitiveParameter] string $password): void {
        $in = openFile($from, 'r');
        $out = openFile($to, 'w');
        $done = false;
        try {
            $head = self::exactly($in, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (!str_starts_with($head, self::MAGIC)) {
                throw new InvalidArgumentException(\Mercurius\I18n\t('export.not_archive'));
            }
            $salt = substr($head, strlen(self::MAGIC), SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $header = substr($head, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = self::key($password, $salt);
            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            } finally {
                sodium_memzero($key);
            }
            while (true) {
                $length = unpack('N', self::exactly($in, 4));
                /** @var mixed $first */
                $first = is_array($length) ? ($length[1] ?? null) : null;
                $size = is_int($first) ? $first : 0;
                if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new InvalidArgumentException(\Mercurius\I18n\t('export.broken'));
                }
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, self::exactly($in, $size));
                if ($opened === false) {
                    throw new InvalidArgumentException(\Mercurius\I18n\t('export.wrong_password'));
                }
                /** @var array{string, int} $opened */
                [$plain, $tag] = $opened;
                $out->write($plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    break;
                }
            }
            $done = true;
        } catch (SodiumException) {
            throw new InvalidArgumentException(\Mercurius\I18n\t('export.broken'));
        } finally {
            $in->close();
            $out->close();
            if (!$done) {
                try {
                    if (exists($to)) {
                        deleteFile($to);
                    }
                } catch (Throwable) {
                }
            }
        }
    }

    private static function key(#[\SensitiveParameter] string $password, string $salt): string {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /** @throws InvalidArgumentException the file ends before $length bytes */
    private static function exactly(\Amp\File\File $in, int $length): string {
        $data = '';
        while (strlen($data) < $length) {
            $part = $in->read(length: $length - strlen($data));
            if ($part === null || $part === '') {
                throw new InvalidArgumentException(\Mercurius\I18n\t('export.broken'));
            }
            $data .= $part;
        }
        return $data;
    }
}
