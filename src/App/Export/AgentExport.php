<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Amp\Cancellation;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Builds the archive of the agent: the files of bin/backup.sh — db.dump (pg_dump -Fc), app.tar.gz (the data of the
 * application), workspace.tar.gz (the working folders, from the sandbox executor), env (the settings) and commit — in a
 * tar, sealed by the owner's password into exports/.
 *
 * spec: brief/operations.md#экспорт-и-импорт-агента — one format with bin/backup.sh, sealed by the owner's password
 * ref: references/hermes-agent/hermes_cli/backup.py@e96221fa:L100-L110, L475 — hermes leaves out browser profiles and
 *      takes a consistent snapshot of its database rather than its files; here the same: pg_dump, no browser profiles
 */
final class AgentExport {
    public const FILES = ['db.dump', 'app.tar.gz', 'workspace.tar.gz', 'env', 'commit'];
    public const EXTENSION = '.mrcx';

    /**
     * @param Closure(list<string>, array<string, string>): void $run runs a command (pg_dump, tar) with its environment
     * @param array{host: string, port: int, user: string, password: string, name: string} $db
     * @param (Closure(string, Cancellation): void)|null $workspace writes the tar.gz of the working folders to a file
     */
    public function __construct(
        private readonly Closure $run,
        private readonly array $db,
        /** the data folder of the application, mounted as storage/app on the host */
        private readonly string $storage,
        private readonly string $env,
        private readonly string $commit,
        private readonly ?Closure $workspace = null,
    ) {}

    /** The sealed archive's path in exports/. */
    public function export(#[\SensitiveParameter] string $password, Cancellation $cancellation): string {
        if (mb_strlen($password) < ArchiveCrypto::PASSWORD_MIN) {
            throw new \InvalidArgumentException(sprintf(\Mercurius\I18n\t('export.password_short'), ArchiveCrypto::PASSWORD_MIN));
        }
        $exports = $this->storage . '/exports';
        if (!is_dir($exports) && !mkdir($exports, 0700, true) && !is_dir($exports)) {
            throw new RuntimeException('exports folder');
        }
        $work = $exports . '/.build-' . bin2hex(random_bytes(6));
        mkdir($work, 0700);
        try {
            ($this->run)(['pg_dump', '-h', $this->db['host'], '-p', (string) $this->db['port'], '-U', $this->db['user'], '-d', $this->db['name'], '-Fc', '-f', $work . '/db.dump'], ['PGPASSWORD' => $this->db['password']]);
            // the same names as bin/backup.sh: app/... ; the exports and the imports themselves stay out
            ($this->run)(['tar', '-czf', $work . '/app.tar.gz', '--exclude=./exports', '--exclude=./import', '--transform=s,^\.,app,', '-C', $this->storage, '.'], []);
            if ($this->workspace !== null) {
                ($this->workspace)($work . '/workspace.tar.gz', $cancellation);
            } else {
                touch($work . '/workspace.tar.gz');
            }
            file_put_contents($work . '/env', $this->env);
            file_put_contents($work . '/commit', $this->commit . "\n");
            // a plain tar of the parts, by a process: packing a large archive in this process would hold the event loop
            ($this->run)(array_merge(['tar', '-cf', $work . '/archive.tar', '-C', $work], self::FILES), []);
            $out = $exports . '/mercurius-' . gmdate('Ymd-His') . self::EXTENSION;
            ArchiveCrypto::encrypt($work . '/archive.tar', $out, $password);
            chmod($out, 0600);
            return $out;
        } finally {
            self::remove($work);
        }
    }

    /**
     * The settings for the archive: the variables of .env.example with the values this process has.
     *
     * @param array<array-key, mixed> $environment the variables of the process ($_ENV)
     */
    public static function env(array $environment, string $example): string {
        $lines = [];
        foreach (preg_split('~\R~', $example) ?: [] as $line) {
            if (preg_match('~^#?\s*([A-Z][A-Z0-9_]*)=~', $line, $m) === 1 && !isset($lines[$m[1]]) && is_scalar($environment[$m[1]] ?? null)) {
                $lines[$m[1]] = $m[1] . '=' . (string) $environment[$m[1]];
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private static function remove(string $dir): void {
        try {
            $names = scandir($dir);
            foreach ($names === false ? [] : $names as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($dir . '/' . $name);
                }
            }
            @rmdir($dir);
        } catch (Throwable) {
        }
    }
}
