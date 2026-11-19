<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Model;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\App\Agent\Model\ModelRoles;
use OpenCCK\App\Agent\Tool\Self\SetModelRoleTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ModelRole;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryModelStores;

final class ModelRolesTest extends AsyncTestCase {
    private InMemoryModelStores $stores;
    private ModelRoles $roles;
    private Principal $owner;
    private Principal $user;

    protected function setUp(): void {
        parent::setUp();
        $this->stores = new InMemoryModelStores();
        $this->stores->replace('openrouter', [
            new ModelInfo('x/tools', 'tools', 100_000, 0.0000001, 0.0000004, ['text'], ['text'], ['tools']),
            new ModelInfo('x/plain', 'plain', 100_000, null, null, ['text'], ['text'], []),
            new ModelInfo('x/other', 'other', 100_000, 0.0000001, 0.0000004, ['text'], ['text'], ['tools']),
            new ModelInfo('x/pricey', 'pricey', 100_000, 0.00015, 0.0006, ['text'], ['text'], ['tools']),
            new ModelInfo('z-ai/glm-5.3-flash', 'glm', 100_000, 0.00000015, 0.0000005, ['text'], ['text'], ['tools']),
        ]);
        $this->roles = new ModelRoles($this->stores, $this->stores);
        $this->roles->seed();
        $this->owner = new Principal(1, PrincipalKind::Human, Role::owner(), 'Владелец');
        $this->user = new Principal(2, PrincipalKind::Human, Role::user(), 'Помощник');
    }

    /** @param array<string, mixed> $args */
    private function call(Principal $by, array $args): \OpenCCK\App\Agent\Tool\ToolResult {
        return (new SetModelRoleTool($this->roles))->execute($args, new ToolContext(1, 1, $by, new NullCancellation()));
    }

    public function testSeedValuesComeFirst(): void {
        self::assertSame('openrouter:z-ai/glm-5.3-flash', (string) $this->roles->model(ModelRole::MAIN));
        self::assertSame('openrouter:openai/gpt-5.6-luna', (string) $this->roles->model(ModelRole::CHEAP));
        self::assertSame('openrouter:anthropic/claude-opus-5', (string) $this->roles->model(ModelRole::STRONG));
        self::assertSame('openrouter:google/gemini-3.8-flash', (string) $this->roles->model(ModelRole::MEDIA));
    }

    public function testOnlyOpenAiGetsOpenAiRoles(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $roles = new ModelRoles($stores, $stores, ['openai']);
        $roles->seed();

        self::assertSame('openai:gpt-6.1-terra', (string) $roles->all()[ModelRole::MAIN]->model, 'stored, not a fallback');

        self::assertSame('openai:gpt-6-luna', (string) $roles->model(ModelRole::CHEAP));
        self::assertSame('openai:gpt-6.1-terra', (string) $roles->model(ModelRole::MAIN));
        self::assertSame('openai:gpt-6-sol', (string) $roles->model(ModelRole::STRONG), 'gpt-6.1-sol always reasons: no tools in Chat Completions');
    }

    public function testOnlyClaudeGetsClaudeRolesOfItsProvider(): void {
        // spec: BRIEF.md#провайдеры-моделей-openrouter-и-openai — default roles by provider
        foreach (['anthropic' => 'anthropic:claude-sonnet-5-5', 'bedrock' => 'bedrock:anthropic.claude-sonnet-5-5'] as $provider => $main) {
            $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
            $roles = new ModelRoles($stores, $stores, [$provider]);
            $roles->seed();

            self::assertSame($main, (string) $roles->model(ModelRole::MAIN), 'not an OpenAI model without an OpenAI key');
            self::assertSame($provider, $roles->model(ModelRole::STRONG)->provider);
        }
    }

    public function testRoleOfAProviderWithoutAKeyFallsBack(): void {
        // The table was filled with OpenRouter; then only the OpenAI key is left.
        $roles = new ModelRoles($this->stores, $this->stores, ['openai']);

        self::assertSame('openai:gpt-6.1-terra', (string) $roles->model(ModelRole::MAIN));
    }

    public function testModelOfAProviderWithoutAKeyIsRefused(): void {
        $result = $this->call($this->user, ['role' => 'main', 'model' => 'openai:gpt-6-luna', 'reason' => 'прямой доступ к OpenAI дешевле']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('нет ключа', $result->content);
    }

    public function testAgentChangesARoleWithAReason(): void {
        $result = $this->call($this->user, ['role' => 'main', 'model' => 'x/tools', 'reason' => 'быстрее и дешевле на наших задачах']);

        self::assertFalse($result->isError, $result->content);
        self::assertSame('openrouter:x/tools', (string) $this->roles->model(ModelRole::MAIN));
        self::assertSame(ModelRole::SET_BY_AGENT, $this->stores->roles[ModelRole::MAIN]->setBy);
        self::assertSame(2, $this->stores->roles[ModelRole::MAIN]->setByPrincipal);
    }

    public function testOnlyTheOwnerFixes(): void {
        self::assertTrue($this->call($this->user, ['role' => 'main', 'model' => 'x/tools', 'reason' => 'хочу закрепить навсегда', 'fix' => true])->isError);
        self::assertFalse($this->call($this->owner, ['role' => 'main', 'model' => 'x/tools', 'reason' => 'закрепляю как основную', 'fix' => true])->isError);
        self::assertSame(ModelRole::SET_BY_OWNER, $this->stores->roles[ModelRole::MAIN]->setBy);
    }

    public function testOwnerFixedRoleIsNotChanged(): void {
        $this->call($this->owner, ['role' => 'strong', 'model' => 'x/tools', 'reason' => 'закрепляю как сильную', 'fix' => true]);

        $result = $this->call($this->user, ['role' => 'strong', 'model' => 'x/other', 'reason' => 'участник решил сэкономить']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('зафиксировал владелец', $result->content);
        self::assertSame('openrouter:x/tools', (string) $this->roles->model(ModelRole::STRONG));
    }

    public function testUnknownOrToollessModelIsRefused(): void {
        self::assertStringContainsString('нет в каталоге', $this->call($this->owner, ['role' => 'main', 'model' => 'x/unknown', 'reason' => 'просто попробовать'])->content);
        self::assertStringContainsString('инструмент', $this->call($this->owner, ['role' => 'main', 'model' => 'x/plain', 'reason' => 'просто попробовать'])->content);
        self::assertFalse($this->call($this->owner, ['role' => 'cheap', 'model' => 'x/plain', 'reason' => 'резюме не требуют инструментов'])->isError);
    }

    public function testOwnerCanUnfixARole(): void {
        $this->call($this->owner, ['role' => 'main', 'model' => 'x/tools', 'reason' => 'закрепляю как основную', 'fix' => true]);

        self::assertFalse($this->call($this->owner, ['role' => 'main', 'model' => 'x/other', 'reason' => 'снимаю закрепление'])->isError);
        self::assertSame(ModelRole::SET_BY_AGENT, $this->stores->roles[ModelRole::MAIN]->setBy);
    }

    public function testFixingOrAPricierModelNeedsApproval(): void {
        $tool = new SetModelRoleTool($this->roles);

        self::assertSame(DangerClass::Write, $tool->danger(['role' => 'main', 'model' => 'x/tools', 'reason' => 'дешевле при том же качестве']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['role' => 'main', 'model' => 'x/pricey', 'reason' => 'лучше пишет код']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['role' => 'main', 'model' => 'x/unknown', 'reason' => 'цена неизвестна']));
        self::assertSame(DangerClass::Irreversible, $tool->danger(['role' => 'main', 'model' => 'x/tools', 'reason' => 'закрепить', 'fix' => true]));
    }

    public function testShortReasonIsRefused(): void {
        self::assertTrue($this->call($this->owner, ['role' => 'main', 'model' => 'x/tools', 'reason' => 'надо'])->isError);
    }
}
