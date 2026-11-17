<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Web;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\BrowserTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Browser\BrowserClient;
use OpenCCK\Test\Support\FakeChannel;
use OpenCCK\Test\Support\FakeHttpClient;

final class BrowserToolTest extends AsyncTestCase {
    private FakeChannel $channel;

    private function tool(FakeHttpClient $http): BrowserTool {
        $this->channel = new FakeChannel();
        $guard = new \OpenCCK\App\Agent\Tool\AddressGuard(static fn(string $host): array => $host === 'nas.home.lan' ? ['192.168.1.10'] : ['93.184.216.34']);
        return new BrowserTool(new BrowserClient(new HttpClient($http, []), 'http://browser:3000', 'token-token-token'), $this->channel, '42', $guard);
    }

    public function testAnInternalPageOpensOnlyWithTheOwnersPermission(): void {
        $http = new FakeHttpClient(['http://browser:3000/navigate' => [200, ['content-type' => 'application/json'], '{"url":"http://nas.home.lan/","title":"NAS","text":"ok","elements":[]}']]);
        $tool = $this->tool($http);
        $args = ['action' => 'open', 'url' => 'http://nas.home.lan/'];

        self::assertSame(DangerClass::Irreversible, $tool->danger($args));
        self::assertSame('addr:nas.home.lan', $tool->grantKey($args));
        self::assertNull($tool->grantKey(['action' => 'open', 'url' => 'https://example.com/']));

        $tool->execute($args, self::context());
        $tool->execute($args, new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::Owner, null), new NullCancellation(), approved: true));

        self::assertStringNotContainsString('allow', $http->bodies[0], 'without the owner nothing is let through');
        self::assertStringContainsString('"allow":["nas.home.lan"]', $http->bodies[1]);
    }

    private static function context(): ToolContext {
        return new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::Owner, null), new NullCancellation());
    }

    public function testActingWaitsForTheOwnerReadingDoesNot(): void {
        $tool = $this->tool(new FakeHttpClient());

        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'open', 'url' => 'https://example.com']));
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'read']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'type', 'ref' => 1, 'text' => 'x']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'type', 'ref' => 1, 'text' => 'x', 'submit' => true]));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'click', 'ref' => 3]));
    }

    public function testAPageComesBackWithItsElements(): void {
        $page = json_encode(['url' => 'https://example.com/', 'title' => 'Example', 'text' => "Hello\nkey=sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef", 'elements' => ['[1] a More → https://iana.org']], JSON_THROW_ON_ERROR);
        $http = new FakeHttpClient(['http://browser:3000/navigate' => [200, ['content-type' => 'application/json'], $page]]);

        $result = $this->tool($http)->execute(['action' => 'open', 'url' => 'https://example.com'], self::context());

        self::assertStringContainsString("https://example.com/\nЗаголовок: Example", $result->content);
        self::assertStringContainsString('[1] a More → https://iana.org', $result->content);
        self::assertStringNotContainsString('0123456789abcdef0123', $result->content);
    }

    public function testARefusalAndAScreenshot(): void {
        $http = new FakeHttpClient([
            'http://browser:3000/navigate' => [403, [], '{"error":"адрес запрещён: внутренний адрес"}'],
            'http://browser:3000/screenshot' => [200, [], json_encode(['url' => 'https://example.com/', 'png' => base64_encode('PNGDATA')], JSON_THROW_ON_ERROR)],
        ]);
        $tool = $this->tool($http);

        $refused = $tool->execute(['action' => 'open', 'url' => 'http://10.0.0.1/'], self::context());
        self::assertTrue($refused->isError);
        self::assertStringContainsString('внутренний адрес', $refused->content);

        $shot = $tool->execute(['action' => 'screenshot'], self::context());
        self::assertSame('Снимок страницы https://example.com/ отправлен владельцу.', $shot->content);
        self::assertSame(['42', 'screenshot.png', 'PNGDATA'], $this->channel->files[0]);
    }
}
