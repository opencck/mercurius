<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Agents\ParticipantsTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Permission;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryPolicyRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

final class ParticipantsToolTest extends AsyncTestCase {
    public function testTheOwnerDecidesTheRoleOfAParticipantWithoutACard(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Сергей Иванов');
        $roman = $principals->createVisitor('telegram', '529769761', 'Roman');
        $policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 100_000, 900, 120));
        $tool = new ParticipantsTool($principals, $policies);
        $context = new ToolContext(1, 1, $owner, new NullCancellation());

        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Read, $tool->danger([]));
        // spec: brief/participants.md#выдача-прав-и-владение — granting rights is always a card, in the owner's private chat
        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Irreversible, $tool->danger(['action' => 'decide']));
        $inGroup = new ParticipantsTool($principals, $policies, privateChat: static fn(int $conversation): bool => false);
        self::assertTrue($inGroup->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'user'], $context)->isError, 'only in the private chat of the owner');
        self::assertFalse($tool->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'user'], $context)->isError);
        self::assertSame([Role::user(), PrincipalStatus::Active], [$principals->find($roman->id)?->role, $principals->find($roman->id)?->status]);

        $tool->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'rejected'], $context);
        self::assertSame(PrincipalStatus::Rejected, $principals->find($roman->id)?->status, 'a decision can be changed');
        self::assertTrue($tool->execute(['action' => 'decide', 'id' => $owner->id, 'role' => 'user'], $context)->isError, 'the owner stays the owner');
        self::assertTrue($tool->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'admin'], new ToolContext(1, 2, $roman, new NullCancellation()))->isError, 'only the owner decides');
    }

    public function testOwnRolesAreGivenAndAHolderOfThePowerGivesNoMoreThanTheirOwn(): void {
        // spec: brief/participants.md#свои-роли
        Role::define('trader', 'Трейдер', 15, [Permission::Tools]);
        Role::define('steward', 'Распорядитель', 16, [Permission::Tools, Permission::Participants]);
        try {
            $principals = new InMemoryPrincipalRepository();
            $owner = $principals->ensureOwner('telegram', '42', 'Владелец');
            $steward = new Principal(2, PrincipalKind::Human, Role::of('steward'), 'Распорядитель', PrincipalStatus::Active);
            $principals->put($steward, 'telegram', '2');
            $guest = $principals->createVisitor('telegram', '3', 'Гость');
            $tool = new ParticipantsTool($principals, new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 100_000, 900, 120)));
            $byOwner = new ToolContext(1, 1, $owner, new NullCancellation());
            $bySteward = new ToolContext(1, 2, $steward, new NullCancellation());

            self::assertFalse($tool->execute(['action' => 'decide', 'id' => $guest->id, 'role' => 'trader'], $byOwner)->isError);
            self::assertSame(Role::of('trader'), $principals->find($guest->id)?->role);

            self::assertFalse($tool->execute(['action' => 'list'], $bySteward)->isError, 'the holder sees the participants');
            self::assertFalse($tool->execute(['action' => 'decide', 'id' => $guest->id, 'role' => 'visitor'], $bySteward)->isError);
            self::assertTrue($tool->execute(['action' => 'decide', 'id' => $guest->id, 'role' => 'admin'], $bySteward)->isError, 'no role above their own');
            self::assertTrue($tool->execute(['action' => 'decide', 'id' => $steward->id, 'role' => 'trader'], $bySteward)->isError, 'not their own role');
            self::assertTrue($tool->execute(['action' => 'decide', 'id' => $owner->id, 'role' => 'trader'], $bySteward)->isError);
        } finally {
            Role::reset();
        }
    }

    public function testTheOwnerFindsAParticipantByNameWithHisRights(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Сергей Иванов');
        $visitor = $principals->createVisitor('telegram', '100200300', 'Сергей Петров');
        $principals->decideVisitor($visitor->id, Role::user(), PrincipalStatus::Active);
        $policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 100_000, 900, 120));
        $tool = new ParticipantsTool($principals, $policies);
        $context = new ToolContext(1, 1, $owner, new NullCancellation());

        $found = $tool->execute(['query' => 'петров'], $context)->content;
        self::assertStringStartsWith('#2 Сергей Петров — человек, роль user, active', $found);
        self::assertStringContainsString('инструменты: нет', $found, 'a new user starts without tools');
        self::assertStringContainsString('#1 Сергей Иванов', $tool->execute([], $context)->content);
        self::assertSame('Участника «сидоров» нет.', $tool->execute(['query' => 'Сидоров'], $context)->content);

        $user = $principals->find($visitor->id);
        self::assertNotNull($user);
        self::assertTrue($tool->execute([], new ToolContext(1, 2, $user, new NullCancellation()))->isError, 'only the owner sees the list');
    }

    public function testAnAgentIsShownWithItsKeyAndFoundByIt(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $main = $principals->createAgent('mercurius', Role::user());
        $orphan = $principals->createAgent('mercurius-03b8330c', Role::admin());
        $keys = [['name' => 'mercurius', 'fingerprint' => 'SHA256:03b8330cfa3e39768506b215efd119ac68fdadfcbb833e31be3545e84dd0fc0b', 'principal' => $main->id, 'revoked' => false]];
        $tool = new ParticipantsTool($principals, new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 100_000, 900, 120)), static fn(): array => $keys);
        $context = new ToolContext(1, 1, $owner, new NullCancellation());

        $found = $tool->execute(['query' => '03b8330c'], $context)->content;
        self::assertStringContainsString('#' . $main->id . ' mercurius', $found, 'found by its key');
        self::assertStringContainsString('ключ SHA256:03b8330c', $found);
        self::assertStringContainsString('#' . $orphan->id . ' mercurius-03b8330c', $found, 'the name matches too');
        self::assertStringContainsString('без ключа', $found, 'an agent without a key cannot connect: the owner sees it');
    }
}
