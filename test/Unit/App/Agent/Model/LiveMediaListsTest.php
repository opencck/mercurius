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
}
