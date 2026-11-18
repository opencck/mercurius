<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Model\RequestClass;
use OpenCCK\App\Agent\Model\RequestClassifier;
use OpenCCK\Domain\Agent\ModelRef;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

final class ModelRouterTest extends AsyncTestCase {
    private InMemoryModelStores $stores;
    private ModelRoles $roles;
    private ScriptedLlm $llm;

    protected function setUp(): void {
        parent::setUp();
        $this->stores = new InMemoryModelStores();
        $this->roles = new ModelRoles($this->stores, $this->stores);
        $this->roles->seed();
        $this->llm = new ScriptedLlm();
    }

    private function router(): ModelRouter {
        $classifier = new RequestClassifier($this->llm, fn(): string => (string) $this->roles->model(ModelRole::CHEAP), new NullLogger());
        return new ModelRouter($classifier, $this->roles, $this->stores, $this->stores, 131072, new NullLogger());
    }

    /** @return array{RequestClass, string, string} */
    private function classify(string $text): array {
        return (new RequestClassifier($this->llm, static fn(): string => 'openrouter:cheap/model', new NullLogger()))->classify($text);
    }

    public function testHeuristics(): void {
        self::assertSame([RequestClass::Short, 'heuristic'], array_slice($this->classify('/help'), 0, 2));
        self::assertSame([RequestClass::Short, 'heuristic'], array_slice($this->classify('Сколько будет 2+2?'), 0, 2));
        self::assertSame([RequestClass::Complex, 'heuristic'], array_slice($this->classify('Спроектируй схему базы для заказов'), 0, 2));
        self::assertSame([RequestClass::Complex, 'heuristic'], array_slice($this->classify(str_repeat('текст ', 400)), 0, 2));
        self::assertSame([], $this->llm->requests);
    }

    public function testEverydayWordsAreNotComplex(): void {
        foreach (['Хочу купить планшет', 'Какая планета больше?', 'Я планирую отпуск', 'Какой план?', 'Нравится архитектура дома', 'Исследую рынок сам'] as $text) {
            self::assertSame(RequestClass::Short, $this->classify($text)[0], $text);
        }
        self::assertSame(RequestClass::Complex, $this->classify('Составь план миграции базы')[0]);
        self::assertSame(RequestClass::Complex, $this->classify('Проанализируй логи и предложи решение')[0]);
    }

    public function testSlowClassifierFallsBackToNormal(): void {
        $this->llm = new ScriptedLlm([ScriptedLlm::text('complex')]);
        $this->llm->delay = 2.0;
        $classifier = new RequestClassifier($this->llm, static fn(): string => 'openrouter:cheap/model', new NullLogger(), 0.1);

        $started = microtime(true);
        $result = $classifier->classify(str_repeat('Расскажи про nginx. ', 15));

        self::assertSame([RequestClass::Normal, 'fallback'], array_slice($result, 0, 2));
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testTheCallOfTheClassifierIsCountedInTheCallsOfModels(): void {
        $this->llm = new ScriptedLlm([ScriptedLlm::text('normal')]);
        $calls = new \OpenCCK\Test\Support\InMemoryLlmCallRepository();
        $classifier = new RequestClassifier($this->llm, static fn(): string => 'openrouter:cheap/model', new NullLogger(), calls: $calls);

        $classifier->classify(str_repeat('Расскажи подробнее про настройку nginx. ', 8), conversationId: 5);

        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — every call of a model is counted, the classifier's too
        self::assertCount(1, $calls->calls);
        self::assertSame(['classify', 5, 'openrouter:cheap/model'], [$calls->calls[0]->purpose, $calls->calls[0]->conversationId, $calls->calls[0]->model]);
    }

    public function testUnclearTextAsksTheCheapModel(): void {
        $this->llm = new ScriptedLlm([ScriptedLlm::text('normal')]);

        $result = $this->classify(str_repeat('Расскажи подробнее про настройку nginx. ', 8));

        self::assertSame(RequestClass::Normal, $result[0]);
        self::assertSame('model', $result[1]);
        self::assertSame('openrouter:cheap/model', $this->llm->requests[0]->model);
        self::assertSame([], $this->llm->requests[0]->tools);
    }

    public function testGarbageAnswerFallsBackToNormal(): void {
        $this->llm = new ScriptedLlm([ScriptedLlm::text('Думаю, что это сложный вопрос')]);
        self::assertSame([RequestClass::Normal, 'fallback'], array_slice($this->classify(str_repeat('Расскажи про nginx. ', 15)), 0, 2));

        $this->llm = new ScriptedLlm([new LlmException(ErrorKind::Server, 'down')]);
        $failed = $this->classify(str_repeat('Расскажи про nginx. ', 15));
        self::assertSame('fallback', $failed[1]);
        self::assertCount(1, $this->llm->requests);
    }

    public function testComplexGoesToTheStrongModel(): void {
        $model = $this->router()->choose(7, 'Спроектируй архитектуру сервиса уведомлений');

        self::assertSame((string) $this->roles->model(ModelRole::STRONG), (string) $model);
        $decision = $this->stores->decisions[0];
        self::assertSame(7, $decision->conversationId);
        self::assertSame('complex', $decision->class);
        self::assertSame(ModelRole::STRONG, $decision->role);
    }

    public function testShortGoesToTheMainModel(): void {
        self::assertSame((string) $this->roles->model(ModelRole::MAIN), (string) $this->router()->choose(7, 'Привет!'));
    }

    public function testModelWithoutToolsFallsBackToMain(): void {
        $this->stores->replace('openrouter', [new ModelInfo('anthropic/claude-opus-5', 'opus', 200_000, null, null, ['text'], ['text'], [])]);

        $model = $this->router()->choose(7, 'Спроектируй архитектуру сервиса уведомлений');

        self::assertSame((string) $this->roles->model(ModelRole::MAIN), (string) $model);
        self::assertStringContainsString('инструмент', $this->stores->decisions[0]->reason);
    }

    public function testWindowComesFromTheCatalog(): void {
        $this->stores->replace('openrouter', [new ModelInfo('z-ai/glm-5.3-flash', 'glm', 32_768, null, null)]);

        self::assertSame(32_768, $this->router()->window('z-ai/glm-5.3-flash'));
        self::assertSame(32_768, $this->router()->window('openrouter:z-ai/glm-5.3-flash'));
    }

    public function testSettingCapsALargerCatalogWindow(): void {
        $this->stores->replace('openrouter', [new ModelInfo('anthropic/claude-opus-5', 'opus', 1_000_000, null, null)]);

        self::assertSame(131072, $this->router()->window('anthropic/claude-opus-5'));
    }

    public function testWindowFallsBackWithoutCatalog(): void {
        self::assertSame(131072, $this->router()->window('z-ai/glm-5.3-flash'));
    }

    public function testCheapModelFollowsTheRole(): void {
        $this->stores->replace('openrouter', [new ModelInfo('x/cheaper', 'c', 1000, null, null)]);
        $this->stores->set(ModelRole::CHEAP, ModelRef::parse('x/cheaper'), 'дешевле при том же качестве', ModelRole::SET_BY_AGENT);

        self::assertSame('openrouter:x/cheaper', $this->router()->cheapModel());
    }
}
