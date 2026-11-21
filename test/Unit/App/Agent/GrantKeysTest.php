<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent;

use OpenCCK\App\Agent\GrantKeys;
use OpenCCK\App\Agent\Tool\Self\SetModelRoleTool;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Test\Support\FakeKeyedTool;
use OpenCCK\Test\Support\FakeTool;
use PHPUnit\Framework\TestCase;

/**
 * Which saved grants let a call run.
 *
 * spec: brief/agent-loop.md#разрешения-навсегда
 */
final class GrantKeysTest extends TestCase {
    public function testEveryCallHasAKeyItsOwnOrOfTheToolAsAWhole(): void {
        $own = GrantKeys::of(new FakeKeyedTool('send', 'send:x'), [], 'send');
        self::assertSame('send:x', $own->exact);
        self::assertSame(['send:x', 'tool:send', 'area:job:5', 'job:5|send:x'], $own->candidates('send', ['job:5']));

        $none = GrantKeys::of(new FakeTool('wipe', DangerClass::Irreversible), [], 'wipe');
        self::assertSame('tool:wipe', $none->exact);
        self::assertSame(['tool:wipe'], $none->candidates('wipe', []));
    }

    public function testWhatStaysApartIsCoveredOnlyByItsOwnKey(): void {
        $never = GrantKeys::of(new FakeKeyedTool('ssh_run', 'ssh-never:v2:base:abc'), [], 'ssh_run');

        self::assertTrue($never->strict);
        self::assertSame(['ssh-never:v2:base:abc', 'job:5|ssh-never:v2:base:abc'], $never->candidates('ssh_run', ['job:5']), 'no grant of the tool or of an area covers it');
    }

    public function testAWiderGrantOfTheToolStandsNextToTheExactOne(): void {
        $stores = new \OpenCCK\Test\Support\InMemoryModelStores();
        $keys = GrantKeys::of(new SetModelRoleTool(new \OpenCCK\App\Agent\Model\ModelRoles($stores, $stores)), ['role' => 'cheap', 'model' => 'a/b'], 'set_model_role');

        self::assertSame('model-role:cheap:a/b', $keys->exact);
        self::assertSame('model-role-any:cheap', $keys->wide[0] ?? null);
        self::assertContains('model-role-any:cheap', $keys->candidates('set_model_role', []));
    }

    public function testLabelsSayWhatAKeyAllows(): void {
        self::assertSame('любой вызов wipe', GrantKeys::label('tool:wipe'));
        self::assertSame('всё в области job:5', GrantKeys::label('area:job:5'));
        self::assertSame('POST на a.example.com', GrantKeys::label('http:POST a.example.com'));
        self::assertSame('git-push:origin', GrantKeys::label('git-push:origin'), 'a key with no words of its own is shown as it is');
    }
}
