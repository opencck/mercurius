<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Amp\Cancellation;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\Domain\Agent\SandboxLimits;

/**
 * Files in memory per participant; commands answer with $outcome. $failure makes every call fail.
 */
final class FakeSandbox implements Sandbox {
    /** @var array<int, array<string, string>> */
    public array $files = [];
    /** @var list<string> */
    public array $calls = [];
    public ?CommandOutcome $outcome = null;
    public ?string $failure = null;

    public function exec(int $principalId, SandboxLimits $limits, string $callId, string $command, string $cwd, int $timeoutSeconds, Cancellation $cancellation): CommandOutcome {
        $this->record(sprintf('exec %d %s @%s t%d', $principalId, $command, $cwd, $timeoutSeconds));
        return $this->outcome ?? new CommandOutcome(0, 'ok', '', false, false, 1);
    }

    public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines, Cancellation $cancellation): array {
        $this->record(sprintf('read %d %s %d %d', $principalId, $path, $startLine, $maxLines));
        if (!isset($this->files[$principalId][$path])) {
            throw new SandboxFailure('Не удалось прочитать ' . $path . ': нет файла');
        }
        $lines = explode("\n", rtrim($this->files[$principalId][$path], "\n"));
        $slice = array_slice($lines, $startLine - 1, $maxLines);
        return ['text' => $slice === [] ? '' : implode("\n", $slice) . "\n", 'totalLines' => count($lines)];
    }

    public function write(int $principalId, SandboxLimits $limits, string $path, string $content, Cancellation $cancellation): void {
        $this->record(sprintf('write %d %s', $principalId, $path));
        $this->files[$principalId][$path] = $content;
    }

    public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new, Cancellation $cancellation): void {
        $this->record(sprintf('replace %d %s', $principalId, $path));
        $this->files[$principalId][$path] = str_replace($old, $new, $this->files[$principalId][$path] ?? '');
    }

    public function list(int $principalId, SandboxLimits $limits, string $path, int $depth, Cancellation $cancellation): array {
        $this->record(sprintf('list %d %s %d', $principalId, $path, $depth));
        $entries = [];
        foreach ($this->files[$principalId] ?? [] as $name => $content) {
            $entries[] = ['path' => $name, 'type' => 'file', 'size' => strlen($content)];
        }
        return $entries;
    }

    private function record(string $call): void {
        $this->calls[] = $call;
        if ($this->failure !== null) {
            throw new SandboxFailure($this->failure);
        }
    }
}
