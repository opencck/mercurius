<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Messaging\ForumTopicTool;
use OpenCCK\App\Agent\Tool\Projects\ProjectEditTool;
use OpenCCK\App\Agent\Tool\Self\OpenRouterByokTool;
use OpenCCK\App\Agent\Tool\Self\SetModelRoleTool;
use OpenCCK\App\Agent\Tool\Self\SettingsTool;
use OpenCCK\App\Agent\Tool\Servers\ServerAddTool;
use OpenCCK\App\Agent\Tool\Servers\SqlQueryTool;
use PHPUnit\Framework\TestCase;

/**
 * Every irreversible action can be allowed for good: the key names the action and its target, so a grant covers that
 * and nothing wider. The keys are built from the arguments alone.
 */
final class GrantKeysTest extends TestCase {
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function bare(string $class): object {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    /** @return iterable<string, array{class-string, array<string, mixed>, ?string}> */
    public static function keys(): iterable {
        yield 'forum delete' => [ForumTopicTool::class, ['action' => 'delete'], 'forum:delete'];
        yield 'forum rename is not irreversible' => [ForumTopicTool::class, ['action' => 'rename', 'name' => 'x'], null];
        yield 'byok' => [OpenRouterByokTool::class, ['provider' => 'openai', 'key_secret' => 'k'], 'openrouter-byok:openai'];
        yield 'project status' => [ProjectEditTool::class, ['action' => 'status', 'project' => 'Дом', 'status' => 'done'], 'project:status:дом'];
        yield 'project create' => [ProjectEditTool::class, ['action' => 'create', 'project' => 'Новый'], 'project:create'];
        yield 'project task is a write' => [ProjectEditTool::class, ['action' => 'add_task', 'project' => 'Дом'], null];
        yield 'server add' => [ServerAddTool::class, ['name' => 'Web', 'host' => '1.2.3.4', 'user' => 'u', 'secret' => 's'], 'server-add:web'];
        yield 'model role' => [SetModelRoleTool::class, ['role' => 'cheap', 'model' => 'a/b', 'reason' => 'r'], 'model-role:cheap:a/b'];
        yield 'settings set' => [SettingsTool::class, ['action' => 'set', 'key' => 'AGENT_MAX_TURNS', 'value' => '40'], 'settings:set:AGENT_MAX_TURNS'];
        yield 'settings restart' => [SettingsTool::class, ['action' => 'restart'], 'settings:restart'];
        yield 'settings list' => [SettingsTool::class, ['action' => 'list'], null];
        yield 'sql write on a server' => [SqlQueryTool::class, ['server' => 'DB', 'engine' => 'postgres', 'user' => 'app', 'database' => 'shop', 'query' => 'update x', 'write' => true], 'sql-write:db:postgres:127.0.0.1:5432:app:shop:' . substr(hash('sha256', 'update x'), 0, 16)];
        yield 'sql read with a secret' => [SqlQueryTool::class, ['server' => 'db', 'engine' => 'mysql', 'user' => 'app', 'database' => 'shop', 'secret' => 'pg', 'query' => 'select 1'], 'sql-read:db:mysql:127.0.0.1:3306:app:pg'];
        yield 'sql read' => [SqlQueryTool::class, ['server' => 'db', 'database' => 'shop', 'query' => 'select 1'], null];
    }

    /**
     * @dataProvider keys
     * @param class-string $class
     * @param array<string, mixed> $args
     */
    public function testTheKeyNamesTheActionAndItsTarget(string $class, array $args, ?string $key): void {
        $tool = self::bare($class);
        self::assertInstanceOf(GrantKeyed::class, $tool);

        self::assertSame($key, $tool->grantKey($args));
        self::assertNotSame('', $tool->describe($args));
    }
}
