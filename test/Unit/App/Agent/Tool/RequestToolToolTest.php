<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Agents\RequestToolTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolRegistry;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Domain\Entity\PrincipalKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Test\Support\FakeTool;
use OpenCCK\Test\Support\InMemoryPolicyRepository;

/**
 * A participant without a tool asks the owner for it: a card, and the tool is theirs for good once the owner agrees.
 */
final class RequestToolToolTest extends AsyncTestCase {
    private ToolRegistry $registry;
    private PolicyService $policies;
    private RequestToolTool $tool;
    private Principal $agent;

    protected function setUp(): void {
        parent::setUp();
        $this->registry = new ToolRegistry();
        $this->registry->register(new FakeTool('peer_links'));
        $this->registry->register(new FakeTool('read_file'));
        $this->policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 100_000, 900, 120));
        $this->tool = new RequestToolTool($this->registry, $this->policies);
        $this->registry->register($this->tool);
        $this->agent = new Principal(7, PrincipalKind::Agent, Role::admin(), 'mercurius-rekryt');
    }

    private function context(): ToolContext {
        return new ToolContext(1, 1, $this->agent, new NullCancellation(), $this->policies->forPrincipal($this->agent), approved: true);
    }

    public function testItAsksTheOwnerWithACardAndTheReason(): void {
        $args = ['tool' => 'peer_links', 'reason' => 'открыть подключение к doorotar'];

        self::assertSame(DangerClass::Irreversible, $this->tool->danger($args));
        self::assertNull($this->tool->grantKey($args), 'every request is the owner\'s own decision');
        self::assertStringContainsString('peer_links', $this->tool->describe($args));
        self::assertStringContainsString('открыть подключение к doorotar', $this->tool->describe($args));
        /** @var array{properties: array{tool: array{enum: list<string>}}} $schema */
        $schema = $this->tool->definition()->parameters;
        self::assertSame(['peer_links', 'read_file'], $schema['properties']['tool']['enum'], 'any tool but itself');
    }

    public function testTheToolIsTheParticipantsForGoodOnceApproved(): void {
        self::assertFalse($this->policies->forPrincipal($this->agent)->allowsTool('peer_links'));

        $result = $this->tool->execute(['tool' => 'peer_links', 'reason' => 'нужно'], $this->context());

        self::assertFalse($result->isError);
        self::assertTrue($this->policies->forPrincipal($this->agent)->allowsTool('peer_links'));
        self::assertSame('peer_links', $this->tool->grantedTool(['tool' => 'peer_links']));
        $this->tool->execute(['tool' => 'read_file', 'reason' => 'ещё'], $this->context());
        self::assertTrue($this->policies->forPrincipal($this->agent)->allowsTool('peer_links'), 'earlier grants stay');
    }

    public function testAnUnknownOrHeldToolIsNotAsked(): void {
        self::assertTrue($this->tool->execute(['tool' => 'rm_rf', 'reason' => 'x'], $this->context())->isError);
        $this->policies->update($this->agent, ['tools' => ['read_file']]);
        self::assertStringContainsString('read_file', $this->tool->execute(['tool' => 'read_file', 'reason' => 'x'], $this->context())->content);
    }

    public function testEveryParticipantSeesItButTheOwner(): void {
        self::assertContains(RequestToolTool::NAME, $this->registry->names(new ParticipantPolicy([], new RunLimits(30, 1000, 900, 120), null, false)));
        self::assertNotContains(RequestToolTool::NAME, $this->registry->names(new ParticipantPolicy(null, new RunLimits(30, 1000, 900, 120), null, true)), 'the owner has every tool');
    }
}
