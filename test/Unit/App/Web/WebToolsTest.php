<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Web;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\AddressGuard;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\WebReadTool;
use OpenCCK\App\Web\TextExtractor;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeHttpClient;
use ZipArchive;

final class WebToolsTest extends AsyncTestCase {
    private const PAGE = '<html><head><title>Цены</title><style>.x{}</style><script>alert(1)</script></head><body>'
        . '<nav>Меню сайта</nav><main><h1>Тарифы</h1><p>Базовый — 5 $ в месяц.</p><ul><li>Один</li><li>Два</li></ul>'
        . '<p>Подробнее <a href="https://example.com/more">тут</a>.</p></main><footer>© подвал</footer></body></html>';

    public function testAPageBecomesItsText(): void {
        [$title, $text] = (new TextExtractor())->extract(self::PAGE, 'text/html; charset=utf-8');

        self::assertSame('Цены', $title);
        self::assertStringContainsString('# Тарифы', $text);
        self::assertStringContainsString('Базовый — 5 $ в месяц.', $text);
        self::assertStringContainsString('- Один', $text);
        self::assertStringContainsString('тут (https://example.com/more)', $text);
        foreach (['alert', 'Меню сайта', 'подвал', '.x{}'] as $gone) {
            self::assertStringNotContainsString($gone, $text);
        }
    }

    public function testDocxAndXlsxAreRead(): void {
        $docx = self::zip(['word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Договор &amp; счёт</w:t></w:r></w:p><w:p><w:r><w:t>Вторая строка</w:t></w:r></w:p></w:body></w:document>']);
        [, $text] = (new TextExtractor())->extract($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        self::assertSame("Договор & счёт\nВторая строка", $text);

        $xlsx = self::zip([
            'xl/sharedStrings.xml' => '<sst><si><t>Имя</t></si><si><t>Сумма</t></si></sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row><c t="s"><v>0</v></c><c t="s"><v>1</v></c></row><row><c t="inlineStr"><is><t>Анна</t></is></c><c><v>42</v></c></row></sheetData></worksheet>',
        ]);
        [, $text] = (new TextExtractor())->extract($xlsx, '', 'https://x.example/report.xlsx');
        self::assertSame("Имя\tСумма\nАнна\t42", $text);
    }

    public function testRedirectsAreFollowedAndCheckedAgainEachTime(): void {
        $http = new FakeHttpClient([
            'https://site.example/a' => [302, ['location' => '/b'], ''],
            'https://site.example/b' => [200, ['content-type' => 'text/html'], self::PAGE],
            'https://evil.example/' => [302, ['location' => 'http://intranet/admin'], ''],
        ]);
        $tool = new WebReadTool($http, new AddressGuard(static fn(string $host): array => ['93.184.216.34']), new TextExtractor());
        $context = new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::Owner, null), new NullCancellation());

        $result = $tool->execute(['url' => 'https://site.example/a'], $context);
        self::assertStringStartsWith("https://site.example/b\nHTTP 200\nЗаголовок: Цены", $result->content);

        $refused = $tool->execute(['url' => 'https://evil.example/'], $context);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('внутренний', $refused->content);
        self::assertNotContains('GET http://intranet/admin', $http->requested);
    }

    /** @param array<string, string> $entries */
    private static function zip(array $entries): string {
        $file = tempnam(sys_get_temp_dir(), 'zip');
        self::assertIsString($file);
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $data = (string) file_get_contents($file);
        unlink($file);
        return $data;
    }
}
