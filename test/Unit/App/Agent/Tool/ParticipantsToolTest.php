<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\ParticipantsTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemoryPolicyRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

final class ParticipantsToolTest extends AsyncTestCase {
    public function testTheOwnerDecidesTheRoleOfAParticipantWithoutACard(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Сергей Крупкин');
        $roman = $principals->createVisitor('telegram', '529769761', 'Roman');
        $policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 100_000, 900, 120));
        $tool = new ParticipantsTool($principals, $policies);
        $context = new ToolContext(1, 1, $owner, new NullCancellation());

        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Read, $tool->danger([]));
        // spec: BRIEF.md#решения-по-доработкам-2026-11-18 — granting rights is always a card, in the owner's private chat
        self::assertSame(\OpenCCK\Domain\Agent\DangerClass::Irreversible, $tool->danger(['action' => 'decide']));
        $inGroup = new ParticipantsTool($principals, $policies, privateChat: static fn(int $conversation): bool => false);
        self::assertTrue($inGroup->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'user'], $context)->isError, 'only in the private chat of the owner');
        self::assertFalse($tool->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'user'], $context)->isError);
        self::assertSame([Role::User, PrincipalStatus::Active], [$principals->find($roman->id)?->role, $principals->find($roman->id)?->status]);

        $tool->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'rejected'], $context);
        self::assertSame(PrincipalStatus::Rejected, $principals->find($roman->id)?->status, 'a decision can be changed');
        self::assertTrue($tool->execute(['action' => 'decide', 'id' => $owner->id, 'role' => 'user'], $context)->isError, 'the owner stays the owner');
        self::assertTrue($tool->execute(['action' => 'decide', 'id' => $roman->id, 'role' => 'admin'], new ToolContext(1, 2, $roman, new NullCancellation()))->isError, 'only the owner decides');
    }

    public function testTheOwnerFindsAParticipantByNameWithHisRights(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Сергей Крупкин');
        $visitor = $principals->createVisitor('telegram', '451898468', 'Сергей Аринин');
        $principals->decideVisitor($visitor->id, Role::User, PrincipalStatus::Active);
        $policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 100_000, 900, 120));
        $tool = new ParticipantsTool($principals, $policies);
        $context = new ToolContext(1, 1, $owner, new NullCancellation());

        $found = $tool->execute(['query' => 'аринин'], $context)->content;
        self::assertStringStartsWith('#2 Сергей Аринин — человек, роль user, active', $found);
        self::assertStringContainsString('инструменты: нет', $found, 'a new user starts without tools');
        self::assertStringContainsString('#1 Сергей Крупкин', $tool->execute([], $context)->content);
        self::assertSame('Участника «петров» нет.', $tool->execute(['query' => 'Петров'], $context)->content);

        $user = $principals->find($visitor->id);
        self::assertNotNull($user);
        self::assertTrue($tool->execute([], new ToolContext(1, 2, $user, new NullCancellation()))->isError, 'only the owner sees the list');
    }

    public function testAnAgentIsShownWithItsKeyAndFoundByIt(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $main = $principals->createAgent('mercurius', Role::User);
        $orphan = $principals->createAgent('mercurius-03b8330c', Role::Admin);
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
