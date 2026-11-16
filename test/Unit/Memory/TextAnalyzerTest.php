<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\Memory;

use OpenCCK\Memory\Domain\TextAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Keys of a text: what search compares. Word forms meet, chatter has none, identifiers stay whole.
 */
final class TextAnalyzerTest extends TestCase {
    public function testWordFormsGiveOneKey(): void {
        $analyzer = new TextAnalyzer();

        self::assertSame($analyzer->keys('сервер'), $analyzer->keys('серверами'));
        self::assertSame($analyzer->keys('servers'), $analyzer->keys('server'));
        self::assertSame($analyzer->keys('ёлка'), $analyzer->keys('елки'));
    }

    public function testChatterHasNoKeys(): void {
        self::assertSame([], (new TextAnalyzer())->keys('ну и что это же было бы, а?'));
        self::assertSame([], (new TextAnalyzer())->keys('ok, and so it is'));
    }

    public function testIdentifiersStayWhole(): void {
        $keys = (new TextAnalyzer())->keys('Перезапусти pve-01 на 10.0.0.1, лог в /var/log/app.log, ключ deploy_key');

        self::assertContains('pve-01', $keys);
        self::assertContains('10.0.0.1', $keys);
        self::assertContains('/var/log/app.log', $keys);
        self::assertContains('deploy_key', $keys);
    }

    public function testKeysAreUniqueAndOrdered(): void {
        $keys = (new TextAnalyzer())->keys('Сервер, сервер и ещё раз серверы');

        self::assertSame(array_values(array_unique($keys)), $keys);
        self::assertCount(1, array_filter($keys, static fn(string $k): bool => str_starts_with($k, 'сервер')));
    }
}
