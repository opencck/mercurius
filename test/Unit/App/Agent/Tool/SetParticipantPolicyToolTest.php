<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use OpenCCK\App\Agent\Tool\Agents\SetParticipantPolicyTool;
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
        $principals->put(new Principal(7, PrincipalKind::Human, Role::user(), 'Гость'), 'telegram', '7');
        $principals->ensureOwner('telegram', '42', 'Владелец');
        $this->policies = new InMemoryPolicyRepository();
        $this->tool = new SetParticipantPolicyTool($principals, new PolicyService($this->policies, new RunLimits(30, 400_000, 900, 120)));
    }

    private static function context(Role $role): ToolContext {
        return new ToolContext(1, 1, new Principal($role === Role::owner() ? 1 : 7, PrincipalKind::Human, $role, null), new NullCancellation());
    }

    public function testAHolderOfThePowerChangesOthersWithinTheirOwnTools(): void {
        // spec: brief/participants.md#свои-роли
        $steward = Role::define('steward', 'Распорядитель', 16, [\OpenCCK\Domain\Entity\Permission::Tools, \OpenCCK\Domain\Entity\Permission::Participants]);
        try {
            $own = new \OpenCCK\Domain\Agent\ParticipantPolicy(['web_search', 'set_participant_policy'], new RunLimits(30, 400_000, 900, 120), null, false);
            $as = static fn(int $id): ToolContext => new ToolContext(1, 1, new Principal(max(1, $id), PrincipalKind::Human, $steward, null), new NullCancellation(), $own);

            self::assertFalse($this->tool->execute(['principal_id' => 7, 'tools' => ['web_search']], $as(8))->isError);
            self::assertTrue($this->tool->execute(['principal_id' => 7, 'tools' => ['run_command']], $as(8))->isError, 'not a tool they lack');
            self::assertTrue($this->tool->execute(['principal_id' => 8, 'max_turns' => 5], $as(8))->isError, 'not their own');
            self::assertTrue($this->tool->execute(['principal_id' => 1, 'max_turns' => 5], $as(8))->isError, 'not the owner');
            self::assertTrue($this->tool->execute(['principal_id' => 7, 'max_turns' => 5], self::context(Role::user()))->isError, 'not without the permission');
        } finally {
            Role::reset();
        }
    }

    public function testRightsAreGivenOnlyWithACardAndOnlyInTheOwnersPrivateChat(): void {
        $principals = new InMemoryPrincipalRepository();
        $principals->put(new Principal(7, PrincipalKind::Human, Role::user(), 'Гость'), 'telegram', '7');
        // spec: brief/participants.md#выдача-прав-и-владение — granting rights is always a card, in the owner's private chat
        $tool = new SetParticipantPolicyTool($principals, new PolicyService($this->policies, new RunLimits(30, 400_000, 900, 120)), static fn(int $conversation): bool => $conversation === 1);

        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Irreversible, $tool->danger(['principal_id' => 7]));
        $inGroup = new ToolContext(1, 2, new Principal(1, PrincipalKind::Human, Role::owner(), null), new NullCancellation(), approved: true);
        self::assertTrue($tool->execute(['principal_id' => 7, 'tools' => null], $inGroup)->isError, 'not in a group, even approved');
        self::assertSame([], $this->policies->policies);
        self::assertFalse($tool->execute(['principal_id' => 7, 'max_turns' => 5], self::context(Role::owner()))->isError);
    }

    public function testNonOwnerCannotChangePolicies(): void {
        $result = $this->tool->execute(['principal_id' => 7, 'max_turns' => 5], self::context(Role::user()));

        self::assertTrue($result->isError);
        self::assertSame([], $this->policies->policies);
    }

    public function testPolicyChangeIsCappedByOwner(): void {
        $result = $this->tool->execute(['principal_id' => 7, 'tools' => ['http_request'], 'max_turns' => 100], self::context(Role::owner()));

        self::assertFalse($result->isError);
        self::assertStringContainsString('"max_turns":30', $result->content);
        self::assertStringContainsString('"tools":["http_request"]', $result->content);
    }

    public function testOwnerPolicyIsNotEditable(): void {
        self::assertTrue($this->tool->execute(['principal_id' => 1, 'max_turns' => 5], self::context(Role::owner()))->isError);
    }

    public function testBadValueIsReported(): void {
        $result = $this->tool->execute(['principal_id' => 7, 'max_turns' => 0], self::context(Role::owner()));

        self::assertTrue($result->isError);
        self::assertStringContainsString('max_turns', $result->content);
    }

    public function testUnknownParticipant(): void {
        self::assertSame('Участник 99 не найден.', $this->tool->execute(['principal_id' => 99], self::context(Role::owner()))->content);
    }
}
