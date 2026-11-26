<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Tool\Self;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Tool\Self\ResourcesTopic;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Host\HostResources;
use OpenCCK\Test\Support\FakeSandbox;

use function Mercurius\I18n\t;

/**
 * The topic resources: the server and the sandbox of the one who asks, with how much of its disk is used.
 *
 * spec: brief/agent-loop.md#ресурсы-сервера-и-песочницы
 */
final class ResourcesTopicTest extends AsyncTestCase {
    public function testTheSandboxOfTheOneWhoAsksIsCountedAgainstItsLimits(): void {
        $sandbox = new FakeSandbox();
        $sandbox->files[5]['notes.txt'] = str_repeat('x', 25 * 1024 * 1024);
        $limits = new SandboxLimits(100 * 1024 * 1024, 64, 60, 0, 4);
        $context = new ToolContext(1, 1, new Member(5, MemberKind::Human, Role::user(), 'Аня'), new NullCancellation(), new ParticipantPolicy([], new RunLimits(30, 1000, 900, 120), $limits, false));

        $text = (new ResourcesTopic(new HostResources('/nonexistent', '/nonexistent', sys_get_temp_dir()), $sandbox))->text($context);

        self::assertStringContainsString(sprintf(t('self.resources.sandbox'), sprintf(t('self.resources.mb'), 25), sprintf(t('self.resources.mb'), 100), 25, 64, 60, t('self.resources.no_limit'), 4), $text);
        self::assertStringContainsString(sprintf(t('self.resources.memory'), t('self.resources.unknown')), $text, 'the server comes first');
    }

    public function testWithoutASandboxItIsSaidSo(): void {
        $context = new ToolContext(1, 1, new Member(5, MemberKind::Human, Role::user(), 'Аня'), new NullCancellation());

        self::assertStringContainsString(t('self.resources.no_sandbox'), (new ResourcesTopic(new HostResources('/nonexistent', '/nonexistent', sys_get_temp_dir()), null))->text($context));
    }
}
