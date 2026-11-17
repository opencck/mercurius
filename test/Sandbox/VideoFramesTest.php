<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

/**
 * Frames and sound of a video come out in the sandbox: a video question without a video model goes to the vision and
 * speech models.
 *
 * @group sandbox
 */
final class VideoFramesTest extends SandboxTestCase {
    public function testFramesEvenlyAndTheSound(): void {
        $made = $this->sh(7, 'ffmpeg -nostdin -v error -f lavfi -i testsrc=duration=6:size=320x240:rate=10 -f lavfi -i sine=duration=6 -shortest -y clip.mp4', 30);
        self::assertSame(0, $made->exitCode, $made->stderr);

        $result = $this->sh(7, 'sh /opt/mercurius/video_frames.sh clip.mp4 frames 4', 30);

        self::assertSame(0, $result->exitCode, $result->stderr);
        $files = array_filter(explode("\n", trim($result->stdout)));
        self::assertContains('audio.m4a', $files);
        $frames = array_filter($files, static fn(string $f): bool => str_starts_with($f, 'frame-'));
        self::assertGreaterThanOrEqual(3, count($frames));
        self::assertLessThanOrEqual(4, count($frames));
    }

    public function testASilentVideoHasNoSound(): void {
        $this->sh(7, 'ffmpeg -nostdin -v error -f lavfi -i testsrc=duration=2:size=160x120:rate=5 -y quiet.mp4', 30);

        $result = $this->sh(7, 'sh /opt/mercurius/video_frames.sh quiet.mp4 quiet 8', 30);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringNotContainsString('audio.m4a', $result->stdout);
    }
}
