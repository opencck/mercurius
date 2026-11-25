<?php

declare(strict_types=1);

namespace OpenCCK\Test\Integration;

use Amp\PHPUnit\AsyncTestCase;
use OpenCCK\Domain\Media\MediaPref;
use OpenCCK\Infrastructure\Repository\PostgresMediaPrefRepository;
use OpenCCK\Infrastructure\Repository\PostgresMemberRepository;
use OpenCCK\Test\Support\TestDatabase;

/**
 * @group integration
 */
final class PostgresMediaPrefRepositoryTest extends AsyncTestCase {
    public function testAWayPerKindIsKeptChangedAndRemoved(): void {
        $db = TestDatabase::connect();
        $someone = (new PostgresMemberRepository($db))->createVisitor('telegram', 'media-' . bin2hex(random_bytes(4)), 'Костя');
        $prefs = new PostgresMediaPrefRepository($db);

        $prefs->save($someone->id, new MediaPref('image', 'demand', 'openrouter:anthropic/claude-haiku-5.5', 'чеки — haiku'));
        $prefs->save($someone->id, new MediaPref('voice', 'off'));
        $kept = $prefs->of($someone->id);

        self::assertSame(['demand', 'openrouter:anthropic/claude-haiku-5.5', 'чеки — haiku'], [$kept['image']->mode, $kept['image']->model, $kept['image']->rules]);
        self::assertSame('off', $kept['voice']->mode);
        $prefs->save($someone->id, new MediaPref('image', 'auto'));
        self::assertSame(['auto', null, ''], [$prefs->of($someone->id)['image']->mode, $prefs->of($someone->id)['image']->model, $prefs->of($someone->id)['image']->rules]);
        $prefs->save($someone->id, new MediaPref('voice'));
        self::assertArrayNotHasKey('voice', $prefs->of($someone->id), 'nothing set — the row goes');
        $db->close();
    }
}
