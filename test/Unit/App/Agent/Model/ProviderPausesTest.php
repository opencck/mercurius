<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\Message;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\FallbackModels;
use OpenCCK\App\Agent\Model\ModelLists;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Infrastructure\Llm\ProviderRouter;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * A subscription whose window of the plan is used up is off the choice until it reopens: its calls fail at once, the
 * run moves to the next model of the same category list, and the owner hears once per window.
 */
final class ProviderPausesTest extends AsyncTestCase {
    private const RESET = 1_893_456_000;

    private int $now = 1_893_400_000;
    /** @var list<array{string, string, int}> */
    private array $told = [];

    private function pauses(): ProviderPauses {
        return new ProviderPauses(function (string $provider, string $why, int $until): void {
            $this->told[] = [$provider, $why, $until];
        }, fn(): int => $this->now);
    }

    private static function limit(): LlmException {
        return new LlmException(ErrorKind::RateLimited, 'Claude subscription: limit', 429, null, null, self::RESET);
    }

    public function testAUsedUpWindowStopsTheCallsOfItsProviderUntilItReopens(): void {
        $pauses = $this->pauses();
        $claude = new ScriptedLlm([self::limit(), ScriptedLlm::text('after the reset')]);
        $router = new ProviderRouter(['claude' => $claude, 'openrouter' => new ScriptedLlm()], null, $pauses);
        $request = new ChatRequest('claude:claude-sonnet-5-5', [Message::user('hi')]);

        try {
            $router->complete($request);
            self::fail('a used-up window was answered');
        } catch (LlmException $e) {
            self::assertSame(self::RESET, $e->resetsAt);
        }
        try {
            foreach ($router->stream($request) as $event) {
            }
            self::fail('a provider off the choice was called');
        } catch (LlmException $e) {
            self::assertSame([ErrorKind::RateLimited, self::RESET], [$e->kind, $e->resetsAt]);
        }
        self::assertCount(1, $claude->requests, 'the second call sent nothing');
        self::assertSame([['claude', ProviderPauses::LIMIT, self::RESET]], $this->told);

        $this->now = self::RESET;
        self::assertSame('after the reset', $router->complete($request)->text);
        self::assertCount(1, $this->told, 'one notice per window');
    }

    public function testALostSignInOfASubscriptionPausesItUntilTheOwnerSignsIn(): void {
        $pauses = $this->pauses();
        $auth = new LlmException(ErrorKind::Auth, 'not signed in', 401);
        $router = new ProviderRouter(['claude' => new ScriptedLlm([$auth]), 'openai' => new ScriptedLlm([$auth])], null, $pauses);

        foreach (['claude:claude-sonnet-5-5', 'openai:gpt-6-luna'] as $model) {
            try {
                $router->complete(new ChatRequest($model, [Message::user('hi')]));
            } catch (LlmException) {
            }
        }
        self::assertTrue($pauses->paused('claude'));
        self::assertFalse($pauses->paused('openai'), 'a wrong key is not a subscription to sign in to');
        self::assertSame(ProviderPauses::SIGN_IN, $this->told[0][1]);

        $pauses->resume('claude');
        self::assertFalse($pauses->paused('claude'));
    }

    public function testTheRunMovesOnlyWithinTheListOfTheCategory(): void {
        $stores = new InMemoryModelStores();
        $stores->replace('claude', [new ModelInfo('claude-sonnet-5-5', 'Sonnet', 1_000_000, 0.0, 0.0)]);
        $stores->replace('openrouter', [new ModelInfo('z-ai/glm-5.3-flash', 'GLM', 200_000, 0.000001, 0.000002), new ModelInfo('anthropic/claude-opus-5', 'Opus', 1_000_000, 0.000005, 0.000025)]);
        $lists = new ModelLists([
            ModelRole::CHEAP => [new ModelRef('claude', 'claude-sonnet-5-5')],
            ModelRole::MAIN => [new ModelRef('claude', 'claude-sonnet-5-5'), new ModelRef('openrouter', 'z-ai/glm-5.3-flash')],
            ModelRole::STRONG => [new ModelRef('openrouter', 'anthropic/claude-opus-5')],
        ]);
        $roles = new ModelRoles($stores, $stores, ['openrouter', 'claude'], null, $lists);
        $pauses = $this->pauses();
        $fallback = new FallbackModels($roles, $stores, $stores, new NullLogger(), $pauses);

        $pauses->pause('claude', self::RESET, ProviderPauses::LIMIT);

        self::assertSame('openrouter:z-ai/glm-5.3-flash', $fallback->next('claude:claude-sonnet-5-5', 1), 'the next model of the main list, not the strong role');
        self::assertStringContainsString('main', $stores->decisions[0]->reason, 'the cheap list holds no other model: the next list that holds it is used');
        $only = new FallbackModels(new ModelRoles($stores, $stores, ['openrouter', 'claude'], null, new ModelLists([
            ModelRole::CHEAP => [new ModelRef('claude', 'claude-sonnet-5-5')],
            ModelRole::MAIN => [new ModelRef('openrouter', 'z-ai/glm-5.3-flash')],
            ModelRole::STRONG => [new ModelRef('openrouter', 'anthropic/claude-opus-5')],
        ])), $stores, $stores, new NullLogger(), $pauses);
        self::assertNull($only->next('claude:claude-sonnet-5-5', 1), 'no other model in its list: no switch to another category');
    }
}
