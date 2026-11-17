<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Infrastructure\Repository\PostgresGroupTalkRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresGroupTalkRepositoryTest extends AsyncTestCase {
    public function testTheTalkIsKeptByTopicAndForgottenWithTheChat(): void {
        $db = TestDatabase::connect();
        $talk = new PostgresGroupTalkRepository($db);
        foreach (['один', 'два', 'три'] as $line) {
            $talk->add('telegram', '-100', '12', $line);
        }
        $talk->add('telegram', '-100', '13', 'чужая тема');

        self::assertSame(['два', 'три'], $talk->recent('telegram', '-100', '12', 2, 3600), 'the latest, oldest first');
        self::assertSame(['чужая тема'], $talk->recent('telegram', '-100', '13', 10, 3600));
        self::assertSame([], $talk->recent('telegram', '-100', '', 10, 3600));

        $talk->forget('telegram', '-100');
        self::assertSame([], $talk->recent('telegram', '-100', '12', 10, 3600));
        $db->close();
    }
}
