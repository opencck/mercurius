<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Model\ModelRoles;
use Mercurius\Llm\ModelCatalog\ModelInfo;
use OpenCCK\Test\Support\AgentStack;
use OpenCCK\Test\Support\InMemoryModelStores;
use OpenCCK\Test\Support\ScriptedLlm;

/**
 * spec: brief/decisions.md#самоулучшение-решено-2026-11-18 — /models shows the roles and their history, rollback brings one back
 */
final class ModelsCommandTest extends AsyncTestCase {
    public function testTheOwnerSeesTheHistoryAndRollsARoleBack(): void {
        $stack = new AgentStack(new ScriptedLlm([]));
        $owner = $stack->principals->ensureOwner('telegram', '42', 'Owner');
        $stores = new InMemoryModelStores();
        $stores->replace('openrouter', [
            new ModelInfo('x/tools', 'tools', 100_000, 0.0000001, 0.0000004, ['text'], ['text'], ['tools']),
            new ModelInfo('x/other', 'other', 100_000, 0.0000001, 0.0000004, ['text'], ['text'], ['tools']),
        ]);
        $roles = new ModelRoles($stores, $stores);
        $roles->seed();
        $stack->chat->useModelRoles($roles);
        $roles->set('cheap', 'openrouter:x/tools', 'первая модель для простого', $owner, false);
        $roles->set('cheap', 'openrouter:x/other', 'вторая модель для сравнения', $owner, false);

        $stack->say('/models');
        $list = implode("\n", $stack->channel->texts('send'));
        self::assertStringContainsString('cheap: openrouter:x/other', $list);
        self::assertStringContainsString('#1 cheap → openrouter:x/tools', $list);

        $stack->say('/models rollback 1');
        self::assertSame('openrouter:x/tools', (string) $roles->model('cheap'));
        self::assertStringContainsString('#1', $stack->channel->texts('send')[1] ?? '');

        $lists = static fn(): int => count(array_filter($stack->channel->texts('send'), static fn(string $t): bool => str_contains($t, 'cheap: openrouter')));
        $before = $lists();
        $stack->say('/models', '77');
        self::assertSame($before, $lists(), 'someone else does not get the list');
    }
}
