<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Web;

use Amp\Cancellation;
use Amp\NullCancellation;
use Mercurius\Search\SearchProvider;
use Mercurius\Search\SearchResult;
use Mercurius\Search\SearchUsage;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\Web\WebSearchTool;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryLlmCallRepository;
use OpenCCK\Test\Support\InMemoryRunRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — a search that calls a model is a model call of the run: in llm_calls and the budget
 */
final class WebSearchToolTest extends TestCase {
    private static function provider(?SearchUsage $usage): SearchProvider {
        return new class($usage) implements SearchProvider {
            public function __construct(private readonly ?SearchUsage $usage) {}

            public function name(): string {
                return 'openrouter';
            }

            public function search(string $query, int $limit = 5, ?Cancellation $cancellation = null): SearchResult {
                return new SearchResult('openrouter', [], 'Ответ.', $this->usage);
            }
        };
    }

    public function testASearchThroughAModelIsCountedToTheRun(): void {
        $runs = new InMemoryRunRepository();
        $run = $runs->start(5, 1, 'm', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $calls = new InMemoryLlmCallRepository();
        $tool = new WebSearchTool(self::provider(new SearchUsage('perplexity/sonar', 1200, 300, 0.0123)));
        $tool->useAccounting($calls, $runs);

        $result = $tool->execute(['query' => 'php 8.6'], new ToolContext($run->id, 5, new Member(1, MemberKind::Human, Role::admin(), null), new NullCancellation()));

        self::assertFalse($result->isError);
        self::assertCount(1, $calls->calls);
        $call = $calls->calls[0];
        self::assertSame(['web_search', 'openrouter:perplexity/sonar', 1200, 300, 0.0123, $run->id, 5], [$call->purpose, $call->model, $call->inputTokens, $call->outputTokens, $call->costUsd, $call->runId, $call->conversationId]);
        self::assertSame(1500, $runs->find($run->id)?->tokensUsed, 'in the budget of the run');
    }

    public function testASearchWithoutAModelCostsTheRunNothing(): void {
        $runs = new InMemoryRunRepository();
        $run = $runs->start(5, 1, 'm', new RunLimits(30, 100_000, 900, 120));
        self::assertNotNull($run);
        $calls = new InMemoryLlmCallRepository();
        $tool = new WebSearchTool(self::provider(null));
        $tool->useAccounting($calls, $runs);

        $tool->execute(['query' => 'php 8.6'], new ToolContext($run->id, 5, new Member(1, MemberKind::Human, Role::admin(), null), new NullCancellation()));

        self::assertSame([], $calls->calls);
        self::assertSame(0, $runs->find($run->id)?->tokensUsed);
    }
}
