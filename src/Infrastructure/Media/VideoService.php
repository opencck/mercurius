<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Media;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use OpenCCK\Infrastructure\Services\ServiceModules;
use RuntimeException;
use Throwable;

/**
 * The media-video module (services/media-video): the frames and the sound of a video, by ffmpeg in its own container.
 *
 * spec: BRIEF.md#модули-сервисы-mcp-и-скиллы — media-video
 */
final class VideoService {
    public const MODULE = 'media-video';

    public function __construct(
        private readonly HttpClient $http,
        private readonly ServiceModules $modules,
    ) {}

    public function up(): bool {
        return $this->modules->up(self::MODULE);
    }

    /**
     * @return array{seconds: float, frames: list<string>, audio: ?string} frames as JPEG bytes, sound as M4A
     * @throws RuntimeException with a message for the model
     */
    public function frames(string $video, int $count, Cancellation $cancellation): array {
        $url = $this->modules->url(self::MODULE);
        $token = $this->modules->token(self::MODULE);
        if ($url === null || $token === null) {
            throw new RuntimeException('media-video is not set up');
        }
        $request = new Request($url . '/frames?count=' . $count, 'POST', $video);
        $request->setHeader('authorization', 'Bearer ' . $token);
        $request->setHeader('content-type', 'application/octet-stream');
        $request->setTransferTimeout(180);
        $request->setInactivityTimeout(180);
        $request->setBodySizeLimit(60_000_000);
        try {
            $response = $this->http->request($request, $cancellation);
            $text = $response->getBody()->buffer($cancellation);
        } catch (Throwable $e) {
            throw new RuntimeException('media-video did not answer: ' . (new \ReflectionClass($e))->getShortName());
        }
        /** @var mixed $data */
        $data = json_decode($text, true, 8);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('media-video answered HTTP %d', $response->getStatus()));
        }
        if ($response->getStatus() !== 200) {
            throw new RuntimeException(isset($data['error']) && is_string($data['error']) ? mb_substr($data['error'], 0, 300) : sprintf('HTTP %d', $response->getStatus()));
        }
        $frames = [];
        /** @var mixed $list */
        $list = $data['frames'] ?? null;
        /** @var mixed $frame */
        foreach (is_array($list) ? $list : [] as $frame) {
            $bytes = is_string($frame) ? base64_decode($frame, true) : false;
            if ($bytes !== false && $bytes !== '') {
                $frames[] = $bytes;
            }
        }
        /** @var mixed $sound */
        $sound = $data['audio'] ?? null;
        $audio = is_string($sound) ? base64_decode($sound, true) : false;
        /** @var mixed $seconds */
        $seconds = $data['seconds'] ?? 0;
        return ['seconds' => is_int($seconds) || is_float($seconds) ? (float) $seconds : 0.0, 'frames' => $frames, 'audio' => $audio === false || $audio === '' ? null : $audio];
    }
}
