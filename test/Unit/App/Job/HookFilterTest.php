<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Job;

use OpenCCK\App\Job\HookFilter;
use PHPUnit\Framework\TestCase;

/**
 * spec: BRIEF.md#планировщик-и-проекты — a webhook of a job is filtered by its event and fields before the model runs
 */
final class HookFilterTest extends TestCase {
    private const PUSH = ['ref' => 'refs/heads/master', 'repository' => ['full_name' => 'opencck/mercurius'], 'action' => 'opened'];

    public function testEventsAndFieldsAreMatched(): void {
        $filter = HookFilter::parse('events=push,pull_request; ref=refs/heads/master; repository.full_name=opencck/mercurius');
        self::assertInstanceOf(HookFilter::class, $filter);

        self::assertTrue($filter->matches('push', self::PUSH));
        self::assertFalse($filter->matches('issues', self::PUSH), 'another event');
        self::assertFalse($filter->matches('push', ['ref' => 'refs/heads/dev'] + self::PUSH), 'another branch');
        self::assertFalse($filter->matches('push', ['repository' => []] + self::PUSH), 'a field that is not there');
    }

    public function testAlternativesNegationAndTheMergeWindow(): void {
        $filter = HookFilter::parse('action=opened|reopened; ref!=refs/heads/gh-pages; merge=30');
        self::assertInstanceOf(HookFilter::class, $filter);

        self::assertTrue($filter->matches('', self::PUSH));
        self::assertTrue($filter->matches('', ['action' => 'reopened'] + self::PUSH));
        self::assertFalse($filter->matches('', ['action' => 'closed'] + self::PUSH));
        self::assertFalse($filter->matches('', ['ref' => 'refs/heads/gh-pages'] + self::PUSH));
        self::assertSame(30, $filter->merge);
        $none = HookFilter::parse('');
        self::assertInstanceOf(HookFilter::class, $none);
        self::assertSame(0, $none->merge, 'no filter takes everything at once');
        self::assertTrue($none->matches('anything', null));
    }

    public function testABrokenFilterIsExplained(): void {
        self::assertIsString(HookFilter::parse('ref'));
        self::assertIsString(HookFilter::parse('merge=lots'));
        self::assertIsString(HookFilter::parse('merge=100000'));
        self::assertSame('events=push; ref=refs/heads/master; merge=30', (string) HookFilter::parse(' events = push ;ref=refs/heads/master;merge=30 '), 'kept in one form');
    }
}
