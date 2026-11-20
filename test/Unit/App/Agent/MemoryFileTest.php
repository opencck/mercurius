<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use OpenCCK\App\Agent\MemoryFile;
use PHPUnit\Framework\TestCase;

/**
 * MEMORY.md in two sections, known by their headings in three languages; anything else is notes.
 *
 * spec: brief/memory.md#правила-и-заметки-memorymd
 */
final class MemoryFileTest extends TestCase {
    public function testTheTwoSectionsAreKnownInThreeLanguages(): void {
        foreach (["## Правила\nкоротко\n## Заметки\nдочь Аня", "# RULES:\nкоротко\n### notes\nдочь Аня", "## 规则\nкоротко\n## 笔记\nдочь Аня"] as $text) {
            $file = MemoryFile::parse($text);
            self::assertSame('коротко', $file->rules, $text);
            self::assertSame('дочь Аня', $file->notes, $text);
        }
    }

    public function testAFileWithoutHeadingsIsNotes(): void {
        $file = MemoryFile::parse("Всегда отвечай коротко\nДочь — Аня");

        self::assertSame('', $file->rules, 'old text does not gain the force of rules by itself');
        self::assertSame("Всегда отвечай коротко\nДочь — Аня", $file->notes);
    }

    public function testTextBeforeTheHeadingsAndOtherHeadingsStayWhereTheyAre(): void {
        $file = MemoryFile::parse("вступление\n## Правила\n### Код\nцеликом\n## Заметки\nпроект");

        self::assertSame("### Код\nцеликом", $file->rules);
        self::assertSame("вступление\nпроект", $file->notes);
    }

    public function testWithinTheLimitTheRulesStayWhole(): void {
        $file = MemoryFile::parse("## Правила\n" . str_repeat('п', 50) . "\n## Заметки\n" . str_repeat('з', 100));

        [$rules, $notes, $cut] = $file->within(80);

        self::assertSame(50, mb_strlen($rules));
        self::assertSame(30, mb_strlen($notes));
        self::assertTrue($cut);
        self::assertSame([str_repeat('п', 50), str_repeat('з', 100), false], $file->within(200));
    }
}
