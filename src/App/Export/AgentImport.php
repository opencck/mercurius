<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The full import of an agent's archive, in two halves. While the agent runs: the archive is opened by the password and
 * unpacked into storage/import/staging, then, on the owner's yes, marked pending. At the next start, before the
 * database is touched: the schema is replaced by the dump, the data unpacked over storage, the vault key of the
 * archive kept in storage/.vault-key; the working folders go back through the sandbox executor after the start.
 *
 * spec: brief/operations.md#экспорт-и-импорт-агента — a full import replaces the agent, its state saved before
 * ref: bin/restore.sh — the same steps: the schema dropped and restored by pg_restore --no-owner, the data unpacked,
 *      .env not changed; the vault key is the one exception, without it the secrets of the dump cannot be read
 */
final class AgentImport {
    public const KEY_FILE = '.vault-key';
    private const REQUIRED = ['db.dump', 'app.tar.gz', 'env', 'commit'];

    /**
     * @param Closure(list<string>, array<string, string>): void $run runs a command (psql, pg_restore, tar) with its environment
     * @param array{host: string, port: int, user: string, password: string, name: string} $db
     */
    public function __construct(
        private readonly Closure $run,
        private readonly array $db,
        /** the data folder of the application, mounted as storage/app on the host */
        private readonly string $storage,
    ) {}

    /**
     * Opens the archive into staging and checks its parts.
     *
     * @return array{commit: string, bytes: int} the commit the archive was made on and the size of its dump
     * @throws InvalidArgumentException a wrong password, a changed or a broken archive
     */
    public function prepare(string $archive, #[\SensitiveParameter] string $password): array {
        $staging = $this->dir('staging');
        self::remove($staging);
        if (!mkdir($staging, 0700, true) && !is_dir($staging)) {
            throw new RuntimeException('import folder');
        }
        try {
            ArchiveCrypto::decrypt($archive, $staging . '/archive.tar', $password);
            ($this->run)(array_merge(['tar', '-xf', $staging . '/archive.tar', '-C', $staging], AgentExport::FILES), []);
            @unlink($staging . '/archive.tar');
            foreach (self::REQUIRED as $file) {
                if (!is_file($staging . '/' . $file)) {
                    throw new InvalidArgumentException(t('export.broken'));
                }
            }
        } catch (Throwable $e) {
            self::remove($staging);
            throw $e instanceof InvalidArgumentException ? $e : new InvalidArgumentException(t('export.broken'), 0, $e);
        }
        return ['commit' => trim((string) file_get_contents($staging . '/commit')), 'bytes' => (int) filesize($staging . '/db.dump')];
    }

    /** Where a checked archive lies until the yes, the no or a partial import. */
    public function staging(): string {
        return $this->dir('staging');
    }

    public function prepared(): bool {
        return is_dir($this->dir('staging'));
    }

    public function discard(): void {
        self::remove($this->dir('staging'));
    }

    /** The prepared archive is applied at the next start. */
    public function commit(): void {
        $pending = $this->dir('pending');
        self::remove($pending);
        if (!rename($this->dir('staging'), $pending)) {
            throw new RuntimeException('import not marked');
        }
    }

    /**
     * At the start, before the database is used: applies a pending import.
     *
     * @return array{dir: string, commit: string}|null the folder left with workspace.tar.gz for the executor; null — nothing pending
     * @throws Throwable the database or the data could not be restored; the pending import is moved aside to failed-*
     */
    public function apply(): ?array {
        $pending = $this->dir('pending');
        if (!is_dir($pending)) {
            return null;
        }
        $stamp = gmdate('Ymd-His');
        $env = ['PGPASSWORD' => $this->db['password']];
        $connection = ['-h', $this->db['host'], '-p', (string) $this->db['port'], '-U', $this->db['user'], '-d', $this->db['name']];
        try {
            // the schema anew: no table is left that the archive did not have
            ($this->run)(array_merge(['psql'], $connection, ['-v', 'ON_ERROR_STOP=1', '-q', '-c', 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;']), $env);
            ($this->run)(array_merge(['pg_restore'], $connection, ['--no-owner', '--exit-on-error', $pending . '/db.dump']), $env);
            // app/... of the export; its exports and imports stay as they are here
            ($this->run)(['tar', '-xzf', $pending . '/app.tar.gz', '--strip-components=1', '--exclude=app/import', '--exclude=app/exports', '-C', $this->storage], []);
            $key = self::variable((string) file_get_contents($pending . '/env'), 'SECRETS_KEY');
            if ($key !== null && $key !== '') {
                file_put_contents($this->storage . '/' . self::KEY_FILE, $key);
                chmod($this->storage . '/' . self::KEY_FILE, 0600);
            }
        } catch (Throwable $e) {
            @rename($pending, $this->dir('failed-' . $stamp));
            throw $e;
        }
        $commit = trim((string) @file_get_contents($pending . '/commit'));
        foreach (['db.dump', 'app.tar.gz', 'env', 'commit'] as $file) {
            @unlink($pending . '/' . $file);
        }
        $applied = $this->dir('applied-' . $stamp);
        rename($pending, $applied);
        return ['dir' => $applied, 'commit' => $commit];
    }

    /** The vault key an import brought: it wins over SECRETS_KEY of .env, the secrets of the dump are sealed by it. */
    public static function secretsKey(string $storage): ?string {
        $key = @file_get_contents($storage . '/' . self::KEY_FILE);
        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /** Removes what an import left: the folder of an applied one once its working folders are back. */
    public static function remove(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $names = @scandir($dir);
        foreach ($names === false ? [] : $names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function dir(string $name): string {
        return $this->storage . '/import/' . $name;
    }

    private static function variable(string $env, string $name): ?string {
        foreach (preg_split('~\R~', $env) ?: [] as $line) {
            if (str_starts_with($line, $name . '=')) {
                return trim(substr($line, strlen($name) + 1), " \t\"'");
            }
        }
        return null;
    }
}
