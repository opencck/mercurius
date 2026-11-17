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
}
