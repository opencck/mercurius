<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Improve;

use Amp\NullCancellation;
use Amp\PHPUnit\AsyncTestCase;
use DateTimeImmutable;
use OpenCCK\App\Agent\Tool\Self\TraceReviewTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Entity\Role;
use OpenCCK\Domain\Repository\TraceReviewRepository;

final class TraceReviewToolTest extends AsyncTestCase {
    public function testTheOwnerGetsStopsToolsRepeatsAfterARefusalAndSpend(): void {
        $repo = new class implements TraceReviewRepository {
            public ?DateTimeImmutable $since = null;

            public function review(DateTimeImmutable $since): array {
                $this->since = $since;
                return [
                    'stops' => [['reason' => 'answered', 'runs' => 40, 'tokens' => 900_000], ['reason' => 'token_budget', 'runs' => 3, 'tokens' => 1_200_000]],
                    'tools' => [['name' => 'web_read', 'calls' => 30, 'failed' => 9, 'denied' => 0, 'sample' => 'HTTP 403 token=abcdefghijklmnop']],
                    'retried' => 2,
                    'costly' => [['run' => 812, 'tokens' => 400_000, 'turns' => 30, 'reason' => 'token_budget']],
                    'spend' => [['purpose' => 'agent', 'usd' => 1.25, 'calls' => 300]],
                    'refused' => [['name' => 'shell', 'calls' => 3, 'runs' => 2]],
                    'afterRefusal' => [['run' => 815, 'denied' => 'ssh_exec', 'then' => 'http_request']],
                ];
            }
        };
        $tool = new TraceReviewTool($repo);
        $owner = new Member(1, MemberKind::Human, Role::admin(), 'Owner');

        $result = $tool->execute(['days' => 3], new ToolContext(1, 1, $owner, new NullCancellation()));

        self::assertFalse($result->isError);
        self::assertStringContainsString('token_budget: 3', $result->content);
        self::assertStringContainsString('web_read: 30 / 9 / 0', $result->content);
        self::assertStringContainsString('#812', $result->content);
        self::assertStringContainsString('$1.2500', $result->content);
        self::assertStringContainsString('shell: 3 (2)', $result->content, 'calls the policy refused');
        self::assertStringContainsString('#815: ssh_exec → http_request', $result->content, 'an outward call right after a refusal');
        self::assertStringContainsString('2', $result->content);
        self::assertStringNotContainsString('abcdefghijklmnop', $result->content, 'a secret in a failed result is masked');
        self::assertTrue($tool->untrusted());
        self::assertEqualsWithDelta((new DateTimeImmutable('-3 days'))->getTimestamp(), $repo->since?->getTimestamp() ?? 0, 5);

        $user = new Member(5, MemberKind::Human, Role::user(), 'Вася');
        self::assertTrue($tool->execute([], new ToolContext(1, 1, $user, new NullCancellation()))->isError);
    }
}
