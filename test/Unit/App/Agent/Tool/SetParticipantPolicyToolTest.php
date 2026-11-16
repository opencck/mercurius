<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use OpenCCK\App\Agent\Tool\SetParticipantPolicyTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryPolicyRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;
use PHPUnit\Framework\TestCase;

final class SetParticipantPolicyToolTest extends TestCase {
    private InMemoryPolicyRepository $policies;
    private SetParticipantPolicyTool $tool;

    protected function setUp(): void {
        $principals = new InMemoryPrincipalRepository();
        $principals->identities['telegram:7'] = new Principal(7, PrincipalKind::Human, Role::User, 'Гость');
        $principals->ensureOwner('telegram', '42', 'Владелец');
        $this->policies = new InMemoryPolicyRepository();
        $this->tool = new SetParticipantPolicyTool($principals, new PolicyService($this->policies, new RunLimits(30, 400_000, 900, 120)));
    }

    private static function context(Role $role): ToolContext {
        return new ToolContext(1, 1, new Principal($role === Role::Owner ? 1 : 7, PrincipalKind::Human, $role, null), new NullCancellation());
    }

    public function testNonOwnerCannotChangePolicies(): void {
        $result = $this->tool->execute(['principal_id' => 7, 'max_turns' => 5], self::context(Role::User));

        self::assertTrue($result->isError);
        self::assertSame([], $this->policies->policies);
    }

    public function testPolicyChangeIsCappedByOwner(): void {
        $result = $this->tool->execute(['principal_id' => 7, 'tools' => ['http_request'], 'max_turns' => 100], self::context(Role::Owner));

        self::assertFalse($result->isError);
        self::assertStringContainsString('"max_turns":30', $result->content);
        self::assertStringContainsString('"tools":["http_request"]', $result->content);
    }

    public function testOwnerPolicyIsNotEditable(): void {
        self::assertTrue($this->tool->execute(['principal_id' => 1, 'max_turns' => 5], self::context(Role::Owner))->isError);
    }

    public function testBadValueIsReported(): void {
        $result = $this->tool->execute(['principal_id' => 7, 'max_turns' => 0], self::context(Role::Owner));

        self::assertTrue($result->isError);
        self::assertStringContainsString('max_turns', $result->content);
    }

    public function testUnknownParticipant(): void {
        self::assertSame('Участник 99 не найден.', $this->tool->execute(['principal_id' => 99], self::context(Role::Owner))->content);
    }
}
