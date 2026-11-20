<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\Cancellation;
use OpenCCK\App\Agent\Sandbox\CommandOutcome;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Service\ProjectFolders;
use OpenCCK\Domain\Agent\SandboxLimits;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * spec: brief/participants.md#доступ-к-проектам-и-передача — the folder of a project follows the project
 */
final class ProjectFoldersTest extends TestCase {
    /** @var \ArrayObject<int, string> the calls the sandbox got */
    private \ArrayObject $log;

    protected function setUp(): void {
        $this->log = new \ArrayObject();
    }

    /** A sandbox that answers the commands of a move: $parts pieces of an archive wait at the giver, $unpacks says whether the taker unpacks. */
    private function sandbox(int $parts, bool $unpacks = true): Sandbox {
        $this->log = new \ArrayObject();
        return new class($parts, $unpacks, $this->log) implements Sandbox {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly int $parts, private readonly bool $unpacks, private readonly \ArrayObject $log) {}

            public function exec(int $principalId, SandboxLimits $limits, string $callId, string $command, string $cwd, int $timeoutSeconds, Cancellation $cancellation): CommandOutcome {
                $this->log->append(sprintf('exec %d %s', $principalId, explode('-', $callId)[1]));
                $out = match (true) {
                    str_contains($callId, '-pack-') => $this->parts === 0 ? 'none' : 'parts ' . $this->parts,
                    str_contains($callId, '-unpack-') => $this->unpacks ? 'unpacked' : '',
                    default => '',
                };
                return new CommandOutcome(0, $out . "\n", $this->unpacks ? '' : 'tar: broken', false, false, 1);
            }

            public function fetch(int $principalId, SandboxLimits $limits, string $path, int $maxBytes, Cancellation $cancellation): string {
                if ($maxBytes > 25_000_000) {
                    throw new RuntimeException('max_bytes must be up to 25000000');
                }
                $this->log->append(sprintf('fetch %d %s', $principalId, basename($path)));
                return 'bytes of ' . basename($path);
            }

            public function put(int $principalId, SandboxLimits $limits, string $path, string $bytes, Cancellation $cancellation): void {
                $this->log->append(sprintf('put %d %s', $principalId, basename($path)));
            }

            public function read(int $principalId, SandboxLimits $limits, string $path, int $startLine, int $maxLines, Cancellation $cancellation): array {
                throw new RuntimeException('not used');
            }

            public function write(int $principalId, SandboxLimits $limits, string $path, string $content, Cancellation $cancellation): void {}

            public function replace(int $principalId, SandboxLimits $limits, string $path, string $old, string $new, Cancellation $cancellation): void {}

            public function list(int $principalId, SandboxLimits $limits, string $path, int $depth, Cancellation $cancellation): array {
                return [];
            }
        };
    }

    private function folders(Sandbox $sandbox): ProjectFolders {
        return new ProjectFolders($sandbox, static fn(int $id): SandboxLimits => new SandboxLimits(1_000_000_000, 64, 60, 1_000_000_000, 2));
    }

    public function testAFolderGoesOverInPiecesTheSandboxCanCarry(): void {
        $sandbox = $this->sandbox(3);

        $this->folders($sandbox)->move(2, 1, 7);

        self::assertSame([
            'exec 2 pack',
            'fetch 2 handover-7.part-0000', 'put 1 handover-7.part-0000',
            'fetch 2 handover-7.part-0001', 'put 1 handover-7.part-0001',
            'fetch 2 handover-7.part-0002', 'put 1 handover-7.part-0002',
            'exec 1 unpack',
            'exec 2 drop',
        ], $this->log->getArrayCopy(), 'each piece within the limit of one read; the source goes last');
    }

    public function testAProjectWithoutAFolderHasNothingToMove(): void {
        $sandbox = $this->sandbox(0);

        $this->folders($sandbox)->move(2, 1, 7);

        self::assertSame(['exec 2 pack'], $this->log->getArrayCopy());
    }

    public function testAFolderThatDidNotUnpackStaysWithItsOwner(): void {
        $sandbox = $this->sandbox(1, false);

        try {
            $this->folders($sandbox)->move(2, 1, 7);
            self::fail('the taker did not unpack');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('unpacked', $e->getMessage());
        }
        self::assertNotContains('exec 2 drop', $this->log->getArrayCopy(), 'the source is not removed');
        self::assertContains('exec 2 clean', $this->log->getArrayCopy(), 'only the pieces are');
    }
}
