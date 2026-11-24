<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\Cancellation;
use Mercurius\Search\SearchProvider;
use Mercurius\Search\SearchResult;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Agent\Tool\Web\WebSearchTool;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Infrastructure\Config\MediaSettings;
use PHPUnit\Framework\TestCase;

/**
 * The lists of the search and of media work at once, like the text lists: a model the owner names is used without a
 * restart, and a tool of a category is offered while the category has a model.
 *
 * spec: brief/models.md#списки-моделей-по-категориям
 */
final class LiveMediaListsTest extends TestCase {
    public function testAMediaListIsReadAtEachUse(): void {
        $settings = MediaSettings::fromEnv(['MEDIA_VISION_MODELS' => 'author/eyes']);
        self::assertSame(['author/eyes'], $settings->models('vision'), 'the list of the start until the lists are followed');

        $now = ['vision' => ['author/eyes'], 'image' => []];
        $settings->follow(static function (string $kind) use (&$now): array {
            return $now[$kind] ?? [];
        });
        self::assertSame([], $settings->models('image'));

        $now['image'] = ['openrouter:author/painter', 'openai:not-an-image-provider', 'bad ref'];
        $now['vision'] = ['claude:claude-opus-5-5'];
        self::assertSame(['openrouter:author/painter'], $settings->models('image'), 'a name the category cannot use is left out, the rest works');
        self::assertSame(['claude:claude-opus-5-5'], $settings->models('vision'));
    }

    public function testTheSearchFollowsItsListWithoutARestart(): void {
        $provider = new class implements SearchProvider {
            public function name(): string {
                return 'openrouter';
            }

            public function search(string $query, int $limit = 5, ?Cancellation $cancellation = null): SearchResult {
                throw new \LogicException('not called');
            }
        };
        $now = [null, []];
        $tool = new WebSearchTool(null);
        $tool->follow(static function () use (&$now): array {
            return $now;
        });

        $registry = new ToolRegistry();
        $registry->register($tool);
        $policy = new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), null, true);

        self::assertFalse($tool->available());
        self::assertSame([], $registry->names($policy), 'no model of the search: the tool is not offered');
        $now = [$provider, ['openrouter' => $provider]];
        self::assertSame(['web_search'], $registry->names($policy), 'a model is named: the tool is there at once');
        self::assertTrue((new WebSearchTool($provider))->available(), 'a search given at the start is always there');
    }

    public function testTheWhisperModuleIsUsedWhileItAnswersAndNoModelOfSpeechIsNamed(): void {
        // spec: brief/internet.md#модуль-whisper-без-настройки
        self::assertSame([], MediaSettings::fromEnv([])->models('speech'), 'no module: no speech');

        $settings = MediaSettings::fromEnv(['WHISPER_TOKEN' => 'a-token-of-the-module', 'WHISPER_MODEL' => 'turbo']);
        self::assertSame('http://whisper:8000/v1', $settings->endpoints['whisper'], 'the module is an endpoint by itself');
        self::assertSame([], $settings->models('speech'), 'set up but not known to answer');

        $up = false;
        $settings->useLocal(static function () use (&$up): bool {
            return $up;
        });
        self::assertSame([], $settings->models('speech'), 'the module is down: nothing recognises speech');
        $up = true;
        self::assertSame(['custom:whisper/turbo'], $settings->models('speech'), 'the module answers: speech is recognised by it');
        self::assertSame([], $settings->models('vision'), 'only speech');

        $named = ['openrouter:author/ears'];
        $settings->follow(static function (string $kind) use (&$named): array {
            return $kind === 'speech' ? $named : [];
        });
        self::assertSame(['openrouter:author/ears'], $settings->models('speech'), 'a model the owner named comes first');
        $named = [];
        self::assertSame(['custom:whisper/turbo'], $settings->models('speech'));
        self::assertSame('http://my:1/v1', MediaSettings::fromEnv(['WHISPER_TOKEN' => 't', 'MEDIA_ENDPOINTS' => 'whisper=http://my:1/v1'])->endpoints['whisper'], 'an address the owner gave stays');
    }
}
