<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Infrastructure\Host;

use OpenCCK\Infrastructure\Host\HostResources;
use PHPUnit\Framework\TestCase;

use function Mercurius\I18n\t;

/**
 * What the agent learns about the server it runs on: memory, swap, processors and their load, the disk of its data,
 * the memory limit of its container — with a warning when one of them runs short.
 *
 * spec: brief/agent-loop.md#ресурсы-сервера-и-песочницы
 */
final class HostResourcesTest extends TestCase {
    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/host-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/proc', 0777, true);
        mkdir($this->dir . '/cgroup', 0777, true);
        file_put_contents($this->dir . '/proc/meminfo', "MemTotal:       131072000 kB\nMemFree:          2000000 kB\nMemAvailable:     3000000 kB\nSwapTotal:        4194304 kB\nSwapFree:          104857 kB\n");
        file_put_contents($this->dir . '/proc/loadavg', "10.50 8.20 7.00 3/2000 12345\n");
        file_put_contents($this->dir . '/proc/cpuinfo', str_repeat("processor\t: 0\nmodel name\t: x\n\n", 8));
        file_put_contents($this->dir . '/cgroup/memory.max', "2147483648\n");
        file_put_contents($this->dir . '/cgroup/memory.current', "1073741824\n");
    }

    protected function tearDown(): void {
        foreach (['proc/meminfo', 'proc/loadavg', 'proc/cpuinfo', 'cgroup/memory.max', 'cgroup/memory.current'] as $file) {
            @unlink($this->dir . '/' . $file);
        }
        @rmdir($this->dir . '/proc');
        @rmdir($this->dir . '/cgroup');
        @rmdir($this->dir);
    }

    public function testTheServerIsReadAndWhatRunsShortIsSaid(): void {
        $resources = new HostResources($this->dir . '/proc', $this->dir . '/cgroup', $this->dir);
        $read = $resources->read();

        self::assertSame(131072000 * 1024, $read['memTotal']);
        self::assertSame(3000000 * 1024, $read['memAvailable']);
        self::assertSame(8, $read['cpus']);
        self::assertSame([10.5, 8.2, 7.0], $read['load']);
        self::assertSame([1073741824, 2147483648], [$read['containerUsed'], $read['containerLimit']]);
        self::assertGreaterThan(0, $read['diskTotal']);

        $text = $resources->text();
        self::assertStringContainsString(t('self.resources.memory_low'), $text, '3 of 125 GB available');
        self::assertStringContainsString(t('self.resources.swap_low'), $text, 'the swap is almost full');
        self::assertStringContainsString(t('self.resources.load_high'), $text, 'the load is above the processors');
    }

    public function testAMissingSourceIsSkippedNotAFailure(): void {
        $resources = new HostResources($this->dir . '/none', $this->dir . '/none', $this->dir);
        $read = $resources->read();

        self::assertNull($read['memTotal']);
        self::assertNull($read['containerLimit']);
        self::assertStringContainsString(t('self.resources.unknown'), $resources->text());
    }

    public function testNoLimitOfTheContainerIsSaidSo(): void {
        file_put_contents($this->dir . '/cgroup/memory.max', "max\n");

        self::assertNull((new HostResources($this->dir . '/proc', $this->dir . '/cgroup', $this->dir))->read()['containerLimit']);
    }
}
