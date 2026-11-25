<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Vault;

use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemorySecretRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/skills-mcp.md#подстановка-секретов — a name is looked up from the narrowest layer of the context: the
 * project, its owner (in a personal context — the member), the agent
 */
final class VaultLayersTest extends TestCase {
    private Vault $vault;
    private Member $owner;
    private Member $user;

    protected function setUp(): void {
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $this->user = new Member(2, MemberKind::Human, Role::user(), 'User');
    }

    public function testTheNarrowestLayerWins(): void {
        $this->vault->putAgent('token', 'agent-value', $this->owner);
        self::assertSame(['agent-value', Vault::LAYER_AGENT], $this->vault->layered('TOKEN', 5, 2));

        $this->vault->put('token', 'token', 'user-value', $this->user);
        self::assertSame(['user-value', Vault::LAYER_OWNER], $this->vault->layered('token', 5, 2));

        $this->vault->putProject(5, 'token', 'example.com', 'project-value', $this->owner);
        self::assertSame(['project-value', Vault::LAYER_PROJECT], $this->vault->layered('token', 5, 2));
        self::assertSame(['user-value', Vault::LAYER_OWNER], $this->vault->layered('token', null, 2), 'a personal context has no project layer');
    }

    public function testAnotherMembersSecretIsNotTaken(): void {
        $this->vault->put('token', 'token', 'user-value', $this->user);

        self::assertNull($this->vault->layered('token', null, 1), 'the secret of member 2 is not in the context of member 1');
        self::assertNull($this->vault->layered('token', 6, 1));
    }

    public function testAnAgentSecretHasItsOwnName(): void {
        $this->vault->put('token', 'token', 'user-value', $this->user);
        self::assertFalse($this->vault->putAgent('token', 'agent-value', $this->owner), 'the same name in another layer is another secret');
        self::assertTrue($this->vault->putAgent('token', 'agent-value-2', $this->owner), 'again — replaced');

        self::assertSame(['agent-value-2', Vault::LAYER_AGENT], $this->vault->layered('token', null, 1));
        self::assertSame('user-value', $this->vault->reveal('token', $this->user), 'the personal one is untouched');
        self::assertSame([['name' => 'token', 'kind' => 'password']], $this->vault->agentNames());
        self::assertSame([['name' => 'token', 'kind' => 'token']], $this->vault->names($this->user), 'the agent secret is not a personal one');

        self::assertTrue($this->vault->removeAgent('token'));
        self::assertSame([], $this->vault->agentNames());
        self::assertSame('user-value', $this->vault->reveal('token', $this->user));
    }
}
