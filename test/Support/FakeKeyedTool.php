<?php

declare(strict_types=1);

namespace OpenCCK\Test\Support;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;

/** An irreversible tool with a fixed grant key. */
final class FakeKeyedTool implements Tool, GrantKeyed {
    public int $runs = 0;
    /** the context of the last execution */
    public ?ToolContext $context = null;

    public function __construct(private readonly string $name, private readonly ?string $key) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition($this->name, 'Keyed tool', ['type' => 'object']);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $this->runs++;
        $this->context = $context;
        return ToolResult::ok('done');
    }

    public function grantKey(array $args): ?string {
        return $this->key;
    }

    public function describe(array $args): string {
        return $this->name . ' действие';
    }
}
