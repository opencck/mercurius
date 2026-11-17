<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Agent\Media;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\App\Agent\Media\InboxCleaner;
use OpenCCK\App\Service\PolicyService;
use OpenCCK\Domain\Agent\RunLimits;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\PrincipalStatus;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Infrastructure\Config\MediaSettings;
use OpenCCK\Test\Support\FakeSandbox;
use OpenCCK\Test\Support\InMemoryPolicyRepository;
use OpenCCK\Test\Support\InMemoryPrincipalRepository;

/**
 * Files that came in stay MEDIA_INBOX_DAYS: then the days' folders of inbox/ go, as each participant, in their home.
 */
final class InboxCleanerTest extends AsyncTestCase {
    public function testOldDaysOfTheInboxGoForEveryoneWithASandbox(): void {
        $principals = new InMemoryPrincipalRepository();
        $owner = $principals->ensureOwner('telegram', '42', 'Owner');
        $visitor = $principals->createVisitor('telegram', '55', 'Гость');
        $user = $principals->decideVisitor($principals->createVisitor('telegram', '56', 'Костя')->id, Role::User, PrincipalStatus::Active);
        $sandbox = new FakeSandbox();
        $policies = new PolicyService(new InMemoryPolicyRepository(), new RunLimits(30, 1000, 900, 120), new SandboxLimits(100_000_000, 64, 60, 1_000_000_000, 4));

        $cleaned = (new InboxCleaner($principals, $policies, $sandbox, MediaSettings::fromEnv(['MEDIA_INBOX_DAYS' => '7'])))->run();

        self::assertSame(3, $cleaned, 'the owner, the user and the visitor have a home');
        self::assertContains('exec ' . $owner->id . ' find inbox -mindepth 1 -maxdepth 1 -type d -mtime +7 -exec rm -rf {} + 2>/dev/null; true @. t60', $sandbox->calls);
        self::assertNotNull($user);
        self::assertNotEmpty(array_filter($sandbox->calls, static fn(string $c): bool => str_starts_with($c, 'exec ' . $visitor->id . ' ')));
    }
}
