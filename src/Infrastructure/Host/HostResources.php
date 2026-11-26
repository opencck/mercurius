<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Host;

use function Mercurius\I18n\t;

/**
 * The server the agent runs on, read at the call: memory and swap of the host, processors and their load, the disk of
 * the agent's data, the memory of the agent's container against its limit. A source that cannot be read is skipped.
 * /proc and the cgroup files live in memory: reading them does not wait on a disk.
 *
 * spec: brief/agent-loop.md#ресурсы-сервера-и-песочницы
 */
final class HostResources {
    /** less than one part of this many free: memory, swap and the disk are said to run short (10%) */
    private const SHORT = 10;

    public function __construct(
        private readonly string $proc = '/proc',
        private readonly string $cgroup = '/sys/fs/cgroup',
        /** a folder on the disk of the agent's data */
        private readonly string $disk = '/',
    ) {}

    /**
     * @return array{memTotal: ?int, memAvailable: ?int, swapTotal: ?int, swapFree: ?int, cpus: ?int, load: ?list<float>,
     *     containerUsed: ?int, containerLimit: ?int, diskTotal: ?int, diskFree: ?int}
     */
    public function read(): array {
        $mem = $this->meminfo();
        $load = $this->file($this->proc . '/loadavg');
        $parts = $load === null ? [] : (preg_split('~\s+~', trim($load)) ?: []);
        $cpu = $this->file($this->proc . '/cpuinfo');
        $total = @disk_total_space($this->disk);
        $free = @disk_free_space($this->disk);
        return [
            'memTotal' => $mem['MemTotal'] ?? null,
            'memAvailable' => $mem['MemAvailable'] ?? null,
            'swapTotal' => $mem['SwapTotal'] ?? null,
            'swapFree' => $mem['SwapFree'] ?? null,
            'cpus' => $cpu === null ? null : self::count($cpu),
            'load' => count($parts) >= 3 ? [(float) $parts[0], (float) $parts[1], (float) $parts[2]] : null,
            'containerUsed' => $this->number($this->cgroup . '/memory.current'),
            'containerLimit' => $this->number($this->cgroup . '/memory.max'),
            'diskTotal' => $total === false ? null : (int) $total,
            'diskFree' => $free === false ? null : (int) $free,
        ];
    }

    /** The server for the agent: one line a resource, a warning where it runs short. */
    public function text(): string {
        $r = $this->read();
        $lines = [];
        $lines[] = $r['memTotal'] === null || $r['memAvailable'] === null
            ? sprintf(t('self.resources.memory'), t('self.resources.unknown'))
            : sprintf(t('self.resources.memory'), self::share($r['memAvailable'], $r['memTotal'])) . self::warn($r['memAvailable'], $r['memTotal'], 'self.resources.memory_low');
        if ($r['swapTotal'] !== null && $r['swapTotal'] > 0 && $r['swapFree'] !== null) {
            $lines[] = sprintf(t('self.resources.swap'), self::share($r['swapFree'], $r['swapTotal'])) . self::warn($r['swapFree'], $r['swapTotal'], 'self.resources.swap_low');
        }
        if ($r['cpus'] !== null && $r['load'] !== null) {
            $lines[] = sprintf(t('self.resources.cpu'), $r['cpus'], $r['load'][0], $r['load'][1], $r['load'][2])
                . ($r['load'][1] > $r['cpus'] ? ' ' . t('self.resources.load_high') : '');
        } else {
            $lines[] = sprintf(t('self.resources.cpu_unknown'), t('self.resources.unknown'));
        }
        $lines[] = $r['diskTotal'] === null || $r['diskFree'] === null
            ? sprintf(t('self.resources.disk'), t('self.resources.unknown'))
            : sprintf(t('self.resources.disk'), self::share($r['diskFree'], $r['diskTotal'])) . self::warn($r['diskFree'], $r['diskTotal'], 'self.resources.disk_low');
        if ($r['containerUsed'] !== null) {
            $lines[] = $r['containerLimit'] === null
                ? sprintf(t('self.resources.container_free'), self::size($r['containerUsed']))
                : sprintf(t('self.resources.container'), self::size($r['containerUsed']), self::size($r['containerLimit']))
                    . (($r['containerLimit'] - $r['containerUsed']) * self::SHORT < $r['containerLimit'] ? ' ' . t('self.resources.container_low') : '');
        }
        return implode("\n", $lines);
    }

    /** «3,0 ГБ из 125,0 ГБ свободно (2%)» */
    private static function share(int $free, int $total): string {
        return sprintf(t('self.resources.share'), self::size($free), self::size($total), $total > 0 ? (int) round(100 * $free / $total) : 0);
    }

    private static function warn(int $free, int $total, string $key): string {
        return $total > 0 && $free * self::SHORT < $total ? ' ' . t($key) : '';
    }

    private static function size(int $bytes): string {
        return $bytes >= 1024 ** 3 ? sprintf(t('self.resources.gb'), round($bytes / 1024 ** 3, 1)) : sprintf(t('self.resources.mb'), (int) round($bytes / 1024 ** 2));
    }

    /** The processors /proc/cpuinfo lists; null — none found. */
    private static function count(string $cpuinfo): ?int {
        $found = preg_match_all('~^processor\s*:~m', $cpuinfo);
        return is_int($found) && $found > 0 ? $found : null;
    }

    /** @return array<string, int> bytes by the name of a line of meminfo */
    private function meminfo(): array {
        $text = $this->file($this->proc . '/meminfo');
        $found = [];
        if ($text !== null && preg_match_all('~^(\w+):\s+(\d+)\s*kB~m', $text, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $row) {
                $found[$row[1]] = (int) $row[2] * 1024;
            }
        }
        return $found;
    }

    private function number(string $path): ?int {
        $text = $this->file($path);
        return $text !== null && preg_match('~^\d+$~', trim($text)) === 1 ? (int) trim($text) : null;
    }

    private function file(string $path): ?string {
        $text = is_readable($path) ? @file_get_contents($path) : false;
        return $text === false ? null : $text;
    }
}
