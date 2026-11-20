<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Web;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\Web\WebReadTool;
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

    public function testALongPageIsReadInWindowsFromOneDownload(): void {
        // spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — offset in web_read and a cache of read pages
        $long = '<html><body><main><p>' . str_repeat('а', 20_000) . '</p><p>КОНЕЦ</p></main></body></html>';
        $http = new FakeHttpClient(['https://example.com/long' => [200, ['content-type' => 'text/html'], $long]]);
        $tool = new WebReadTool($http, new AddressGuard(static fn(string $host): array => ['93.184.216.34']), new TextExtractor());
        $context = new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation());

        $first = $tool->execute(['url' => 'https://example.com/long'], $context);
        self::assertStringNotContainsString('КОНЕЦ', $first->content);
        self::assertStringContainsString('offset=15000', $first->content, 'where to go on');

        $second = $tool->execute(['url' => 'https://example.com/long', 'offset' => 15000], $context);
        self::assertStringContainsString('КОНЕЦ', $second->content);
        self::assertStringNotContainsString('offset=30000', $second->content, 'the end: nothing more');
        self::assertCount(1, $http->requested, 'the page was downloaded once');
        self::assertTrue($tool->execute(['url' => 'https://example.com/long', 'offset' => 90000], $context)->isError, 'past the end');
    }

    public function testRedirectsAreFollowedAndCheckedAgainEachTime(): void {
        $http = new FakeHttpClient([
            'https://site.example/a' => [302, ['location' => '/b'], ''],
            'https://site.example/b' => [200, ['content-type' => 'text/html'], self::PAGE],
            'https://evil.example/' => [302, ['location' => 'http://intranet/admin'], ''],
        ]);
        $tool = new WebReadTool($http, new AddressGuard(static fn(string $host): array => ['93.184.216.34']), new TextExtractor());
        $context = new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation());

        $result = $tool->execute(['url' => 'https://site.example/a'], $context);
        self::assertStringStartsWith("https://site.example/b\nHTTP 200\nЗаголовок: Цены", $result->content);

        $refused = $tool->execute(['url' => 'https://evil.example/'], $context);
        self::assertTrue($refused->isError);
        self::assertStringContainsString('внутренний', $refused->content);
        self::assertNotContains('GET http://intranet/admin', $http->requested);
    }

    public function testAnInternalPageIsReadOnlyWithTheOwnersPermission(): void {
        $http = new FakeHttpClient(['http://nas.home.lan/' => [200, ['content-type' => 'text/plain'], 'disk ok']]);
        $guard = new AddressGuard(static fn(string $host): array => $host === 'nas.home.lan' ? ['192.168.1.10'] : ['93.184.216.34']);
        $used = null;
        $tool = new WebReadTool($http, $guard, new TextExtractor(), function (AddressGuard $permitted) use (&$used, $http): FakeHttpClient {
            $used = $permitted;
            return $http;
        });
        $owner = new Principal(1, PrincipalKind::Human, Role::owner(), null);
        $args = ['url' => 'http://nas.home.lan/'];

        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Irreversible, $tool->danger($args));
        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Read, $tool->danger(['url' => 'https://site.example/']));
        self::assertSame('addr:nas.home.lan', $tool->grantKey($args), 'the same key as http_request: one grant per host');
        self::assertTrue($tool->execute($args, new ToolContext(1, 1, $owner, new NullCancellation()))->isError);

        $result = $tool->execute($args, new ToolContext(1, 1, $owner, new NullCancellation(), approved: true));

        self::assertStringContainsString('disk ok', $result->content);
        self::assertNotNull($used);
    }

    /** @param array<string, string> $entries */
    private static function zip(array $entries): string {
        // a name that does not exist yet: replacing a file tempnam() just made fails on Windows now and then
        $file = sys_get_temp_dir() . '/webtools-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE | ZipArchive::EXCL);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $data = (string) file_get_contents($file);
        unlink($file);
        return $data;
    }
}
