<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Model\ModelRouter;
use OpenCCK\App\Agent\Model\RequestClassifier;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ScriptedLlm;
use Psr\Log\NullLogger;

/**
 * The model of a conversation is chosen once, when it opens, by the class of the first request.
 */
final class ModelChoiceTest extends AsyncTestCase {
    private InMemoryModelStores $stores;
    private ModelRoles $roles;
    private AgentStack $stack;

    protected function setUp(): void {
        parent::setUp();
        $this->stores = new InMemoryModelStores();
        $this->roles = new ModelRoles($this->stores, $this->stores);
        $this->roles->seed();
        $classifier = new RequestClassifier(new ScriptedLlm(), fn(): string => (string) $this->roles->model(ModelRole::CHEAP), new NullLogger());
        $router = new ModelRouter($classifier, $this->roles, $this->stores, $this->stores, 131072, new NullLogger());
        $this->stack = new AgentStack(new ScriptedLlm([ScriptedLlm::text('план готов'), ScriptedLlm::text('пожалуйста')]), router: $router);
    }

    public function testComplexFirstMessageOpensWithTheStrongModel(): void {
        $this->stack->say('Спроектируй архитектуру сервиса уведомлений');
        $this->stack->scheduler->awaitIdle();

        self::assertSame((string) $this->roles->model(ModelRole::STRONG), $this->stack->llm->requests[0]->model);
        self::assertSame((string) $this->roles->model(ModelRole::STRONG), $this->stack->conversations->meta(1)?->model);
    }

    public function testModelIsChosenOnceForAConversation(): void {
        $this->stack->say('Спроектируй архитектуру сервиса уведомлений');
        $this->stack->scheduler->awaitIdle();
        $this->stack->say('спасибо');
        $this->stack->scheduler->awaitIdle();

        self::assertCount(1, $this->stores->decisions);
        self::assertSame($this->stack->llm->requests[0]->model, $this->stack->llm->requests[1]->model);
    }
}
