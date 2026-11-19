<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\CommandPolicy;

use OpenCCK\CommandPolicy\Classification;
use OpenCCK\CommandPolicy\SshCommandClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The ssh-mcp corpus (about 6 thousand probes and known bypasses) has no expected classes: it is held to properties.
 * Nothing throws; a read never carries shell syntax, a name pattern or an elevation; an elevation is never a read.
 *
 * ref: references/ssh-mcp/test/differential/corpus.json@e04ebf2 — the probes; their runner only compares two revisions
 *      (copied to test/Fixtures/ssh-mcp/ with the licence)
 */
final class CorpusTest extends TestCase {
    private const ELEVATE = ['sudo', 'su', 'doas', 'pkexec', 'su-exec', 'gosu', 'sudo-rs', 'run0', 'pfexec', 'runuser', 'setpriv'];

    public function testTheCorpusHoldsTheProperties(): void {
        // a copy in the tests, so CI checks it too (the references submodules are not checked out there)
        $file = dirname(__DIR__, 3) . '/test/Fixtures/ssh-mcp/corpus.json';
        /** @var list<string> $corpus */
        $corpus = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
        $classifier = new SshCommandClassifier();
        $reads = 0;
        foreach ($corpus as $command) {
            $result = $classifier->classify($command);
            if (!$result->isRead()) {
                continue;
            }
            $reads++;
            self::assertDoesNotMatchRegularExpression('~[;&|<>`$(){}\\\\*?\[\n\r]~', $command, $command);
            $parts = preg_split('~\s+~', trim($command));
            $first = strtolower($parts === false ? '' : ($parts[0] ?? ''));
            self::assertNotContains(basename($first), self::ELEVATE, $command);
            // options that write a file or start a program never leave a command a read, in any spelling
            self::assertDoesNotMatchRegularExpression('~(^|\s)(--?(o|output|out|compress-prog\w*|set|file|cursor-file|vacuum\S*|rotate|clear|clea|exec|delete|fprint\w*)(=|\s|$))~', $command, $command);
        }
        self::assertGreaterThan(5000, count($corpus));
        self::assertLessThan(count($corpus) / 4, $reads, 'reads are the exception');
        foreach (self::ELEVATE as $prefix) {
            self::assertSame(Classification::PRIVILEGED, $classifier->classify($prefix . ' ls')->class, $prefix);
        }
    }
}
