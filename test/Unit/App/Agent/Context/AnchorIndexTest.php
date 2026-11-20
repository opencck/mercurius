<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Context;

use OpenCCK\App\Agent\Context\AnchorIndex;
use PHPUnit\Framework\TestCase;

/**
 * The exact identifiers of the compacted part stay word for word: a summary may retell, the index may not.
 *
 * spec: brief/decisions.md#решения-по-доработкам-2026-11-18
 */
final class AnchorIndexTest extends TestCase {
    public function testIdentifiersOfTheTextAreCollectedOnceWithTheirCount(): void {
        $text = "Коммит 3802092a1f, ещё раз 3802092a1f. Файл src/App/Agent/AgentLoop.php и migrations/0057_http_cookies.sql.\n"
            . "Ошибка RuntimeException: duplicate key value. Задача #1234, адрес https://mercurius.srv.rekryt.ru/a2a/ws.";

        $index = AnchorIndex::build($text);

        self::assertStringContainsString(AnchorIndex::HEADING, $index);
        self::assertStringContainsString('3802092a1f(x2)', $index);
        self::assertStringContainsString('src/App/Agent/AgentLoop.php', $index);
        self::assertStringContainsString('migrations/0057_http_cookies.sql', $index);
        self::assertStringContainsString('RuntimeException: duplicate key value', $index);
        self::assertStringContainsString('#1234', $index);
        self::assertStringContainsString('https://mercurius.srv.rekryt.ru/a2a/ws', $index);
        self::assertSame('', AnchorIndex::build('просто разговор без идентификаторов'));
    }

    public function testAnOldIndexInASummaryIsCutOffBeforeANewOneIsAdded(): void {
        $summary = "Сводка.\n\n" . AnchorIndex::HEADING . "\nfiles: a/b.php";

        self::assertSame('Сводка.', AnchorIndex::strip($summary));
    }
}
