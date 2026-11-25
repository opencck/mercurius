<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Mcp;

use OpenCCK\App\Mcp\McpEntry;
use OpenCCK\App\Mcp\McpSecrets;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\InMemorySecretInheritanceRepository;
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
    private InMemorySecretInheritanceRepository $inherit;

    protected function setUp(): void {
        $this->vault = new Vault(new InMemorySecretRepository(), random_bytes(32));
        $this->inherit = new InMemorySecretInheritanceRepository();
        // project 5 belongs to member 2
        $this->secrets = new McpSecrets($this->vault, static fn(int $project): ?int => $project === 5 ? 2 : null, $this->inherit);
        $this->owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');
        $this->user = new Member(2, MemberKind::Human, Role::user(), 'User');
    }

    public function testNamesAreTakenFromTheNarrowestLayer(): void {
        $entry = McpEntry::fromArray('docs', ['url' => 'https://a.example/mcp', 'headers' => ['Authorization' => 'Bearer {token}', 'X-Region' => '{region}']]);
        $this->vault->putAgent('token', 'agent-token', $this->owner);
        $this->vault->putAgent('region', 'eu', $this->owner);
        $this->vault->put('token', 'token', 'user-token', $this->user);
        $this->vault->putProject(5, 'token', 'example.com', 'project-token', $this->owner);
        $this->inherit->set('project:5', 'agent', true);
        $this->inherit->set('member:1', 'agent', true);

        self::assertSame('Bearer project-token', $this->secrets->render($entry, 'project:5')->headers['Authorization']);
        self::assertSame('Bearer user-token', $this->secrets->render($entry, 'member:2')->headers['Authorization']);
        self::assertSame('Bearer agent-token', $this->secrets->render($entry, 'member:1')->headers['Authorization'], 'not the secret of member 2');
        self::assertSame(['token' => Vault::LAYER_PROJECT, 'region' => Vault::LAYER_AGENT], $this->secrets->layers($entry, 'project:5'));
    }

    public function testOnlyTurnedOnLayersAreInherited(): void {
        $entry = McpEntry::fromArray('docs', ['url' => 'https://a.example/mcp', 'headers' => ['X-Key' => '{key}']]);
        $this->vault->put('key', 'token', 'user-key', $this->user);
        $this->vault->putAgent('key', 'agent-key', $this->owner);

        self::assertSame(['key'], $this->secrets->render($entry, 'project:5')->missing, 'nothing of the owner or the agent by default');
        self::assertSame(['key' => null], $this->secrets->layers($entry, 'member:1'), 'nor of the agent in a personal context');
        self::assertSame('user-key', $this->secrets->render($entry, 'member:2')->headers['X-Key'], 'one\'s own always');

        $this->inherit->set('project:5', 'owner', true, 2);
        self::assertSame('user-key', $this->secrets->render($entry, 'project:5')->headers['X-Key']);
        $this->inherit->set('project:5', 'owner', false);
        $this->inherit->set('project:5', 'agent', true);
        self::assertSame('agent-key', $this->secrets->render($entry, 'project:5')->headers['X-Key']);
    }

    public function testTheConsentOfAFormerProjectOwnerDoesNotPassToTheNextOne(): void {
        $owners = new \ArrayObject(['owner' => 2]);
        $secrets = new McpSecrets($this->vault, static fn(int $project): ?int => $project === 5 ? $owners['owner'] : null, $this->inherit);
        $entry = McpEntry::fromArray('docs', ['url' => 'https://a.example/mcp', 'headers' => ['X-Key' => '{key}']]);
        $this->vault->put('key', 'token', 'owner-key', $this->owner);
        $this->inherit->set('project:5', 'owner', true, 2);

        $owners['owner'] = 1;

        self::assertSame(['key'], $secrets->render($entry, 'project:5')->missing, 'member 1 never opened their secrets to the project');
    }

    public function testTheOwnersContextTakesTheAgentsSecrets(): void {
        $entry = McpEntry::fromArray('docs', ['url' => 'https://a.example/mcp', 'headers' => ['X-Key' => '{key}']]);
        $this->vault->putAgent('key', 'agent-key', $this->owner);

        self::assertSame('agent-key', $this->secrets->render($entry, 'owners')->headers['X-Key'], 'the owners keep the agent\'s secrets: no switch');
    }

    public function testAMissingNameIsNamed(): void {
        $entry = McpEntry::fromArray('docs', ['command' => 'npx', 'env' => ['TOKEN' => '{nothing}']]);

        self::assertSame(['nothing'], $this->secrets->render($entry, 'member:2')->missing);
        self::assertSame(['nothing' => null], $this->secrets->layers($entry, 'member:2'));
    }
}
