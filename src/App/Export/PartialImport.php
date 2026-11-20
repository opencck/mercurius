<?php

declare(strict_types=1);

namespace OpenCCK\App\Export;

use Amp\Cancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Memory\Domain\MemoryStore;
use OpenCCK\Memory\Domain\TextAnalyzer;
use RuntimeException;

/**
 * A part of a checked archive into the running agent, nothing else replaced: the owner's memory (the facts of the
 * owner's scope, new texts only) or the owner's skills (the folders of .mercurius/skills, written into the current
 * owner's sandbox folder; a skill of the same name here is kept).
 *
 * spec: BRIEF.md#экспорт-и-импорт-агента — a partial import: only memory or only skills
 */
final class PartialImport {
    /** a skill file larger than this stays out: skills are text */
    private const FILE_BYTES = 1_048_576;

    /**
     * @param Closure(list<string>, array<string, string>): void $run runs pg_restore and tar
     * @param (Closure(int): ?SandboxLimits)|null $limitsOf the sandbox limits of a participant
     */
    public function __construct(
        private readonly Closure $run,
        private readonly MemoryStore $memory,
        private readonly TextAnalyzer $analyzer,
        private readonly ?Sandbox $sandbox = null,
        private readonly ?Closure $limitsOf = null,
    ) {}

    /** The facts of the archive owner's scope as new facts of this owner; returns how many were added. */
    public function memory(string $staging): int {
        $rows = $this->table($staging, 'facts');
        $known = [];
        foreach ($this->memory->active(100_000) as $fact) {
            $known[$fact->scope . "\0" . $fact->text] = true;
        }
        $added = 0;
        foreach ($rows as $row) {
            if (($row['scope'] ?? null) !== 'owner' || ($row['status'] ?? 'active') !== 'active') {
                continue;
            }
            $text = trim($row['text'] ?? '');
            if ($text === '' || isset($known['owner' . "\0" . $text])) {
                continue;
            }
            $category = ($row['category'] ?? '') === 'rule' ? 'rule' : 'knowledge';
            $trust = is_numeric($row['trust'] ?? null) ? max(0.0, min(1.0, (float) $row['trust'])) : 0.5;
            $this->memory->addFact('owner', $category, $text, $this->analyzer->keys($text), $trust, $row['provenance'] ?? 'agent', null);
            $known['owner' . "\0" . $text] = true;
            $added++;
        }
        return $added;
    }

    /**
     * The archive owner's skills into the sandbox folder of $owner.
     *
     * @return list<string> the names of the skills written
     */
    public function skills(string $staging, int $owner, Cancellation $cancellation): array {
        if ($this->sandbox === null || $this->limitsOf === null) {
            throw new RuntimeException('no sandbox');
        }
        $limits = ($this->limitsOf)($owner);
        if ($limits === null) {
            throw new RuntimeException('no sandbox limits of the owner');
        }
        $from = null;
        foreach ($this->table($staging, 'principals') as $row) {
            if (($row['role'] ?? null) === 'owner' && is_numeric($row['id'] ?? null)) {
                $from = (int) $row['id'];
            }
        }
        if ($from === null || !is_file($staging . '/workspace.tar.gz') || filesize($staging . '/workspace.tar.gz') === 0) {
            return [];
        }
        $out = $staging . '/skills';
        AgentImport::remove($out);
        mkdir($out, 0700);
        $root = './p' . $from . '/' . \OpenCCK\App\Skill\SkillLibrary::MINE;
        try {
            ($this->run)(['tar', '-xzf', $staging . '/workspace.tar.gz', '-C', $out, '--wildcards', $root . '/*'], []);
        } catch (RuntimeException) {
            // no skills in the archive: tar says it found none of the members
            return [];
        }
        $existing = [];
        try {
            foreach ($this->sandbox->list($owner, $limits, \OpenCCK\App\Skill\SkillLibrary::MINE, 1, $cancellation) as $entry) {
                // paths relative to the listed folder: the first part is the skill
                $path = str_starts_with($entry['path'], './') ? substr($entry['path'], 2) : $entry['path'];
                $existing[explode('/', $path)[0]] = true;
            }
        } catch (\Throwable) {
            // no skills folder yet
        }
        $written = [];
        $base = $out . '/' . substr($root, 2);
        $names = is_dir($base) ? scandir($base) : false;
        foreach ($names === false ? [] : $names as $name) {
            if ($name === '.' || $name === '..' || isset($existing[$name]) || !is_dir($base . '/' . $name)) {
                continue;
            }
            foreach (self::files($base . '/' . $name) as $relative) {
                $bytes = (string) file_get_contents($base . '/' . $name . '/' . $relative);
                $this->sandbox->put($owner, $limits, \OpenCCK\App\Skill\SkillLibrary::MINE . '/' . $name . '/' . $relative, $bytes, $cancellation);
            }
            $written[] = $name;
        }
        AgentImport::remove($out);
        sort($written);
        return $written;
    }

    /**
     * The rows of a table of the archive's dump, by the column names of its COPY.
     *
     * @return list<array<array-key, ?string>>
     */
    private function table(string $staging, string $table): array {
        $sql = $staging . '/' . $table . '.sql';
        ($this->run)(['pg_restore', '--data-only', '--table=' . $table, '-f', $sql, $staging . '/db.dump'], []);
        $rows = self::copyRows((string) file_get_contents($sql));
        @unlink($sql);
        return $rows;
    }

    /**
     * The rows of the COPY ... FROM stdin blocks of a pg_restore script, in COPY's text format.
     *
     * @return list<array<array-key, ?string>>
     */
    public static function copyRows(string $sql): array {
        $rows = [];
        $columns = null;
        foreach (preg_split('~\r?\n~', $sql) ?: [] as $line) {
            if ($columns === null) {
                if (preg_match('~^COPY \S+ \((.+)\) FROM stdin;$~', $line, $m) === 1) {
                    $columns = array_map(static fn(string $c): string => trim($c, " \""), explode(',', $m[1]));
                }
                continue;
            }
            if ($line === '\.') {
                $columns = null;
                continue;
            }
            $values = explode("\t", $line);
            if (count($values) !== count($columns)) {
                continue;
            }
            $row = [];
            foreach ($columns as $i => $column) {
                $row[$column] = self::unescape($values[$i]);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private static function unescape(string $value): ?string {
        if ($value === '\N') {
            return null;
        }
        return preg_replace_callback('~\\\\(.)~s', static fn(array $m): string => match ($m[1]) {
            'n' => "\n",
            't' => "\t",
            'r' => "\r",
            'b' => "\x08",
            'f' => "\f",
            'v' => "\v",
            default => $m[1],
        }, $value) ?? $value;
    }

    /** @return list<string> the files under $dir, relative, small enough to be a skill's */
    private static function files(string $dir, string $prefix = ''): array {
        $found = [];
        $names = scandir($dir);
        foreach ($names === false ? [] : $names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $found = [...$found, ...self::files($path, $prefix . $name . '/')];
            } elseif (filesize($path) <= self::FILE_BYTES) {
                $found[] = $prefix . $name;
            }
        }
        return $found;
    }
}
