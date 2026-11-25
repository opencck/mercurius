<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use OpenCCK\App\Mcp\McpEntry;
use OpenCCK\App\Mcp\McpSecrets;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemorySecretRepository;
use PHPUnit\Framework\TestCase;

/**
 * spec: brief/skills-mcp.md#подстановка-секретов — a name from the narrowest layer of the context of the entry
 */
final class McpSecretsTest extends TestCase {
    private Vault $vault;
    private McpSecrets $secrets;
    private Member $owner;
    private Member $user;

    protected function setUp(): void {
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        // project 5 belongs to member 2
        $this->secrets = new McpSecrets($this->vault, static fn(int $project): ?int => $project === 5 ? 2 : null);
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $this->user = new Member(2, MemberKind::Human, Role::user(), 'User');
    }

    public function testNamesAreTakenFromTheNarrowestLayer(): void {
        $entry = McpEntry::fromArray('docs', ['url' => 'https://a.example/mcp', 'headers' => ['Authorization' => 'Bearer {token}', 'X-Region' => '{region}']]);
        $this->vault->putAgent('token', 'agent-token', $this->owner);
        $this->vault->putAgent('region', 'eu', $this->owner);
        $this->vault->put('token', 'token', 'user-token', $this->user);
        $this->vault->putProject(5, 'token', 'example.com', 'project-token', $this->owner);

        self::assertSame('Bearer project-token', $this->secrets->render($entry, 'project:5')->headers['Authorization']);
        self::assertSame('Bearer user-token', $this->secrets->render($entry, 'member:2')->headers['Authorization']);
        self::assertSame('Bearer agent-token', $this->secrets->render($entry, 'member:1')->headers['Authorization'], 'not the secret of member 2');
        self::assertSame(['token' => Vault::LAYER_PROJECT, 'region' => Vault::LAYER_AGENT], $this->secrets->layers($entry, 'project:5'));
    }

    public function testAMissingNameIsNamed(): void {
        $entry = McpEntry::fromArray('docs', ['command' => 'npx', 'env' => ['TOKEN' => '{nothing}']]);

        self::assertSame(['nothing'], $this->secrets->render($entry, 'member:2')->missing);
        self::assertSame(['nothing' => null], $this->secrets->layers($entry, 'member:2'));
    }
}
