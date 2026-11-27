<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\Cancellation;
use Closure;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\ProjectRepository;

use function Mercurius\I18n\t;

/**
 * /ls: the folder of the conversation's context as a tree with the size of every folder and file, under a header that
 * names the context, the folder and, in a project, the member's level. Sizes come from du in the sandbox: a folder
 * shows its whole size, deeper than the tree too.
 *
 * spec: brief/participants.md#контекст-беседы-в-личном-чате
 */
final class FolderTree {
    /** the longest tree in lines */
    public const LIMIT = 200;
    public const DIRS = '--dirs--';
    public const MISSING = '--missing--';
    private const ROOT = '/workspace';

    /** @param Closure(): ?int $agentOwner the member whose home keeps the projects of the agent's owner */
    public function __construct(
        private readonly Sandbox $sandbox,
        private readonly ProjectRepository $projects,
        private readonly Closure $agentOwner,
    ) {}

    /** @param int<1, 3> $depth */
    public function show(Member $who, ConversationPlace $place, SandboxLimits $limits, int $depth, Cancellation $cancellation): string {
        [$holder, $dir] = match ($place->kind) {
            ConversationPlace::OWNERS => [0, '.'],
            ConversationPlace::PROJECT => [$place->project->ownerId ?? ($this->agentOwner)() ?? $who->id, 'projects/' . ($place->project->id ?? 0)],
            default => [$who->id, '.'],
        };
        $shown = ($holder === 0 ? self::ROOT : self::ROOT . '/p' . $holder) . ($dir === '.' ? '' : '/' . $dir);
        $header = [sprintf(t('ls.context'), $place->title())];
        if ($place->project !== null) {
            $level = $place->project->levelOf($who, $this->projects->memberLevel($place->project->id, $who->id));
            $header[] = sprintf(t('ls.level'), $level?->label() ?? '—');
        }
        $command = sprintf(
            "test -d %1\$s || { echo '%2\$s'; exit 0; }; cd %1\$s && du -ab --max-depth=%3\$d . 2>/dev/null | head -n 5000; echo '%4\$s'; find . -mindepth 1 -maxdepth %3\$d -type d 2>/dev/null | head -n 5000",
            escapeshellarg($dir), self::MISSING, $depth, self::DIRS,
        );
        try {
            $out = $this->sandbox->exec($holder, $limits, 'ls-' . bin2hex(random_bytes(4)), $command, '.', 60, $cancellation)->stdout;
        } catch (SandboxFailure $e) {
            return implode("\n", $header) . "\n" . sprintf(t('ls.failed'), $e->getMessage());
        }
        if (str_starts_with(trim($out), self::MISSING)) {
            return implode("\n", $header) . "\n" . sprintf(t('ls.missing'), $shown);
        }
        [$tree, $total] = self::format($out, $depth);
        array_splice($header, 1, 0, [sprintf(t('ls.folder'), $shown, self::size($total))]);
        return implode("\n", $header) . "\n\n" . ($tree === '' ? sprintf(t('ls.empty'), $shown) : $tree);
    }

    /**
     * The tree of `du -ab --max-depth` output, followed by the folders `find` gave after the marker.
     *
     * @return array{string, int} the tree and the size of the whole folder
     */
    public static function format(string $out, int $depth): array {
        [$du, $found] = array_pad(explode(self::DIRS, $out, 2), 2, '');
        $dirs = [];
        foreach (preg_split('/\R/', $found) ?: [] as $line) {
            if (str_starts_with($line, './')) {
                $dirs[substr($line, 2)] = true;
            }
        }
        $sizes = [];
        $children = [];
        $total = 0;
        foreach (preg_split('/\R/', $du) ?: [] as $line) {
            if (preg_match('/^(\d+)\t(.+)$/', $line, $m) !== 1) {
                continue;
            }
            if ($m[2] === '.') {
                $total = (int) $m[1];
                continue;
            }
            $path = substr($m[2], 2);
            if (!str_starts_with($m[2], './') || $path === '' || substr_count($path, '/') >= $depth) {
                continue;
            }
            $sizes[$path] = (int) $m[1];
            $parent = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';
            $children[$parent][] = $path;
        }
        $lines = [];
        self::draw('', '', $children, $sizes, $dirs, $lines);
        $count = count($lines);
        if ($count > self::LIMIT) {
            $lines = array_slice($lines, 0, self::LIMIT);
            $lines[] = sprintf(t('ls.cut'), self::LIMIT, $count);
        }
        return [implode("\n", $lines), $total];
    }

    /** A size in bytes in words: one decimal, none when it is whole. */
    public static function size(int $bytes): string {
        $units = explode('|', t('ls.units'));
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024.0;
            $unit++;
        }
        $rounded = round($value, 1);
        $text = $rounded == floor($rounded) ? (string) (int) $rounded : number_format($rounded, 1, '.', '');
        return $text . ' ' . $units[$unit];
    }

    /**
     * @param array<string, list<string>> $children
     * @param array<string, int> $sizes
     * @param array<string, true> $dirs
     * @param list<string> $lines
     */
    private static function draw(string $parent, string $indent, array $children, array $sizes, array $dirs, array &$lines): void {
        $list = $children[$parent] ?? [];
        usort($list, static fn(string $a, string $b): int => [!isset($dirs[$a]), mb_strtolower($a)] <=> [!isset($dirs[$b]), mb_strtolower($b)]);
        foreach ($list as $i => $path) {
            $last = $i === count($list) - 1;
            $name = basename($path) . (isset($dirs[$path]) ? '/' : '');
            $lines[] = $indent . ($last ? '└─ ' : '├─ ') . $name . ' — ' . self::size($sizes[$path] ?? 0);
            self::draw($path, $indent . ($last ? '   ' : '│  '), $children, $sizes, $dirs, $lines);
        }
    }
}
