<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Media;

use Amp\Cancellation;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Mercurius\Messenger\Attachment;
use Mercurius\Messenger\AttachmentKind;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Agent\Sandbox\Sandbox;
use OpenCCK\App\Agent\Sandbox\SandboxFailure;
use OpenCCK\App\Agent\Tool\SecretMasker;
use OpenCCK\App\Agent\Tool\UntrustedWrapper;
use OpenCCK\Domain\Agent\ParticipantPolicy;
use OpenCCK\Domain\Agent\SandboxLimits;
use OpenCCK\Domain\Entity\Principal;
use OpenCCK\Infrastructure\Config\MediaSettings;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Mercurius\I18n\t;

/**
 * The files of an incoming message: each is downloaded and kept in the participant's sandbox (inbox/<date>/), and the
 * agent gets a line per file — the text of a document, or a note what the file is and that its model is not set yet
 * with how to set it. A document is read in the sandbox as the participant, a plain text file here; every content is
 * data from outside, in the frame of untrusted data and with secrets masked.
 *
 * spec: BRIEF.md#входящие-вложения — reception, what happens with each kind by default, limits, no sandbox
 * ref: references/hermes-agent/gateway/run_inbound_transcription.py@e96221fa:L1-L40 — hermes handles a voice clip when
 *      it comes in; here every kind is taken in, and the models come in the next part (until then — a note)
 */
final class AttachmentIntake {
    public const MAX_FILES = 10;
    public const MAX_FILE = 20_000_000;
    /**
     * What goes through the application into the sandbox and to the models: a file is held in memory three times (bytes,
     * base64, JSON) and the application has 512 MB. A local Bot API server gives larger files — they need streaming.
     */
    public const SANDBOX_MAX = 25_000_000;
    public const MAX_TOTAL = 50_000_000;
    public const EXTRACT_SECONDS = 30;
    public const EXTRACTOR = '/opt/mercurius/extract_text.py';
    private const TEXT = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'html', 'htm', 'log', 'yaml', 'yml', 'ini', 'conf', 'sql', 'php', 'py', 'js', 'ts', 'go', 'java', 'c', 'h', 'cpp', 'rs', 'rb', 'sh', 'css'];
    private const OFFICE = ['docx', 'pptx', 'xlsx', 'odt', 'pdf', 'rtf'];
    private const IMAGE = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'heic'];
    private const AUDIO = ['mp3', 'm4a', 'ogg', 'oga', 'opus', 'wav', 'flac', 'aac'];
    private const VIDEO = ['mp4', 'mov', 'webm', 'mkv', 'avi'];
    /** what each kind needs: the model kind of MediaSettings */
    private const MODEL = ['image' => 'vision', 'voice' => 'speech', 'audio' => 'speech', 'video' => 'video'];

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;
    private readonly int $maxFile;

    /** @param (Closure(): DateTimeImmutable)|null $now */
    public function __construct(
        private readonly Channel $channel,
        private readonly ?Sandbox $sandbox,
        private readonly MediaSettings $settings,
        private readonly DateTimeZone $zone,
        ?Closure $now = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** the largest file the channel gives: 20 MB from the cloud Bot API, more from a local server (taken up to 25 MB) */
        int $maxFile = self::MAX_FILE,
        /** vision, speech and video models; null — files are only kept and named */
        private readonly ?\OpenCCK\Infrastructure\Media\MediaModels $models = null,
        /** the participant's way with files over the owner's defaults */
        private readonly ?MediaPrefs $prefs = null,
    ) {
        $this->now = $now ?? static fn(): DateTimeImmutable => new DateTimeImmutable();
        $this->maxFile = min($maxFile, self::SANDBOX_MAX);
    }

    /** @param int|null $conversationId where the spend of the models is counted */
    public function intake(Principal $principal, ParticipantPolicy $policy, IncomingMessage $message, Cancellation $cancellation, ?int $conversationId = null): IntakeResult {
        // the files of the message, then those of the message it replies to (a question to a photo sent earlier), once each
        $files = [];
        $replied = [];
        foreach ($message->attachments as $attachment) {
            $files[$attachment->fileId] = $attachment;
        }
        foreach ($message->repliedAttachments as $attachment) {
            if (!isset($files[$attachment->fileId])) {
                $files[$attachment->fileId] = $attachment;
                $replied[$attachment->fileId] = true;
            }
        }
        if ($files === []) {
            return new IntakeResult('', false);
        }
        $limits = $this->sandbox === null ? null : $policy->sandbox;
        $folder = 'inbox/' . ($this->now)()->setTimezone($this->zone)->format('Y-m-d');
        $blocks = [];
        $total = 0;
        $outside = false;
        $call = new \OpenCCK\Infrastructure\Media\MediaCall($conversationId, null, $cancellation);
        foreach (array_values($files) as $i => $attachment) {
            // only the participant's own voice is their words; anything else — and anything not processed — is data
            $outside = $outside || !self::ownVoice($attachment) || isset($replied[$attachment->fileId]);
            $name = MediaNames::clean($attachment->fileName);
            if ($i >= self::MAX_FILES) {
                $blocks[] = $this->line($attachment, $name, null, null) . "\n" . sprintf(t('media.intake.not_downloaded'), sprintf(t('media.intake.too_many'), self::MAX_FILES));
                continue;
            }
            if ($attachment->size !== null && $attachment->size > $this->maxFile) {
                $blocks[] = $this->line($attachment, $name, null, null) . "\n" . sprintf(t('media.intake.not_downloaded'), sprintf(t('media.intake.too_big'), intdiv($this->maxFile, 1_000_000)));
                continue;
            }
            $bytes = $this->channel->download($attachment->fileId, min($this->maxFile, $this->maxTotal() - $total));
            if ($bytes === null) {
                $reason = $total >= $this->maxTotal() || ($attachment->size !== null && $total + $attachment->size > $this->maxTotal())
                    ? t('media.intake.too_much') : t('media.intake.failed');
                $blocks[] = $this->line($attachment, $name, null, null) . "\n" . sprintf(t('media.intake.not_downloaded'), $reason);
                continue;
            }
            $total += strlen($bytes);
            $path = $limits === null ? null : $this->keep($principal, $limits, $folder, $name, $bytes, $cancellation);
            $blocks[] = $this->line($attachment, $name, strlen($bytes), $path) . (isset($replied[$attachment->fileId]) ? t('media.intake.replied') : '')
                . "\n" . $this->content($principal, $limits, $attachment, $name, $bytes, $path, $cancellation, $call);
        }
        return new IntakeResult(implode("\n\n", $blocks), $outside);
    }

    /** 50 MB of files a message, or one largest file when the channel gives more. */
    private function maxTotal(): int {
        return max(self::MAX_TOTAL, $this->maxFile);
    }

    /** What the file is: text, office, image, voice, audio, video or other. */
    public static function category(Attachment $attachment): string {
        $extension = strtolower(pathinfo($attachment->fileName, PATHINFO_EXTENSION));
        $mime = strtolower($attachment->mimeType ?? '');
        return match (true) {
            $attachment->kind === AttachmentKind::Photo || in_array($extension, self::IMAGE, true) || str_starts_with($mime, 'image/') => 'image',
            $attachment->kind === AttachmentKind::Voice || $attachment->kind === AttachmentKind::VideoNote => 'voice',
            $attachment->kind === AttachmentKind::Audio || in_array($extension, self::AUDIO, true) || str_starts_with($mime, 'audio/') => 'audio',
            in_array($attachment->kind, [AttachmentKind::Video, AttachmentKind::Animation], true) || in_array($extension, self::VIDEO, true) || str_starts_with($mime, 'video/') => 'video',
            in_array($extension, self::OFFICE, true) => 'office',
            in_array($extension, self::TEXT, true) || str_starts_with($mime, 'text/') => 'text',
            default => 'other',
        };
    }

    /** A voice message or a round video of the participant themselves, not forwarded: what is said there is their words. */
    private static function ownVoice(Attachment $attachment): bool {
        return in_array($attachment->kind, [AttachmentKind::Voice, AttachmentKind::VideoNote], true) && !$attachment->forwarded;
    }

    private function content(Principal $principal, ?SandboxLimits $limits, Attachment $attachment, string $name, string $bytes, ?string $path, Cancellation $cancellation, \OpenCCK\Infrastructure\Media\MediaCall $call): string {
        $category = self::category($attachment);
        $model = self::MODEL[$category] ?? null;
        if ($model !== null) {
            if ($this->settings->models($model) === []) {
                return sprintf(t('media.intake.not_set'), t('media.model.' . $model), 'MEDIA_' . strtoupper($model) . '_MODELS', t('media.input.' . $model));
            }
            return $this->understand($principal, $attachment, $category, $model, $name, $bytes, $call);
        }
        return match ($category) {
            'text' => $this->text($name, $bytes, $path),
            'office' => $limits === null || $path === null || $this->sandbox === null
                ? t('media.intake.no_sandbox')
                : $this->extract($principal, $limits, $name, $path, $cancellation),
            default => '',
        };
    }

    /**
     * A photo, speech or a video read by the participant's preferred model or the first of the owner's list that
     * answers — at once if the participant's way for this kind is "auto" (and a recording is not too long); else a note
     * how to ask. The principles of choosing come along: the agent picks a model by them for questions about details.
     */
    private function understand(Principal $principal, Attachment $attachment, string $category, string $model, string $name, string $bytes, \OpenCCK\Infrastructure\Media\MediaCall $call): string {
        $pref = $this->prefs?->of($principal->id, $category) ?? new \OpenCCK\Domain\Media\MediaPref($category, in_array($category, $this->settings->auto, true) ? 'auto' : 'demand');
        $principles = $this->principles($model, $pref->rules);
        if ($pref->mode === 'off') {
            return t('media.intake.off') . $principles;
        }
        $long = in_array($category, ['voice', 'audio'], true) && ($attachment->duration ?? 0) > $this->settings->audioAutoMinutes * 60;
        if ($pref->mode !== 'auto' || $long || $this->models === null) {
            return sprintf(t('media.intake.on_request'), t('media.action.' . $model)) . $principles;
        }
        $refs = MediaPrefs::candidates($this->settings, $model, $pref->model);
        $models = $this->models;
        $answer = match ($model) {
            'vision' => $models->first($refs, static fn(string $ref) => $models->look($ref, [['bytes' => $bytes, 'mime' => $attachment->mimeType ?? self::mime($name, 'image/jpeg')]], t('media.prompt.describe'), $call)),
            'speech' => $models->first($refs, static fn(string $ref) => $models->transcribe($ref, $bytes, $name, $call)),
            default => $models->first($refs, static fn(string $ref) => $models->video($ref, $bytes, $attachment->mimeType ?? self::mime($name, 'video/mp4'), t('media.prompt.video'), $call)),
        };
        if ($answer->text === null) {
            return sprintf(t('media.intake.failed_models'), $answer->error ?? '') . $principles;
        }
        $text = SecretMasker::mask(mb_substr($answer->text, 0, $this->settings->textLimit));
        if ($model === 'speech' && self::ownVoice($attachment)) {
            // the participant said it: their words, not data from outside
            return '🎤 ' . $text;
        }
        return sprintf(t('media.intake.by_model'), $answer->model) . "\n" . UntrustedWrapper::wrap('file:' . $name, $text) . $principles;
    }

    /** The owner's and the participant's principles for the model kind, as a line for the agent; '' — none. */
    private function principles(string $model, string $own): string {
        $owner = $this->settings->rules($model);
        if ($owner === '' && $own === '') {
            return '';
        }
        return "\n" . sprintf(t('media.intake.principles'), $owner === '' ? '—' : $owner, $own === '' ? '—' : $own);
    }

    private static function mime(string $name, string $default): string {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'jpg', 'jpeg' => 'image/jpeg',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
            default => $default,
        };
    }

    private function text(string $name, string $bytes, ?string $path): string {
        $text = mb_check_encoding($bytes, 'UTF-8') ? $bytes : self::fromWindows($bytes);
        if ($text === null) {
            return t('media.intake.not_utf8');
        }
        return $this->framed($name, $text, $path);
    }

    private function extract(Principal $principal, SandboxLimits $limits, string $name, string $path, Cancellation $cancellation): string {
        // quoted for the POSIX shell of the sandbox whatever runs this (escapeshellarg follows the host)
        $command = sprintf('python3 %s %s %d', self::EXTRACTOR, "'" . str_replace("'", "'\''", $path) . "'", $this->settings->textLimit);
        try {
            $outcome = $this->sandbox?->exec($principal->id, $limits, 'intake-' . bin2hex(random_bytes(4)), $command, '.', self::EXTRACT_SECONDS, $cancellation);
        } catch (SandboxFailure $e) {
            return sprintf(t('media.intake.not_extracted'), mb_substr($e->getMessage(), 0, 200));
        }
        if ($outcome === null || $outcome->timedOut) {
            return sprintf(t('media.intake.not_extracted'), t('media.intake.timeout'));
        }
        return match ($outcome->exitCode) {
            0 => $this->framed($name, $outcome->stdout, $path),
            2 => t('media.intake.unsupported'),
            default => sprintf(t('media.intake.not_extracted'), mb_substr(trim($outcome->stderr), 0, 200)),
        };
    }

    /** The content as data from outside: masked, cut to the limit with the path to the whole file. */
    private function framed(string $name, string $text, ?string $path): string {
        $limit = $this->settings->textLimit;
        $cut = mb_strlen($text) > $limit;
        $shown = SecretMasker::mask($cut ? mb_substr($text, 0, $limit) : $text);
        return UntrustedWrapper::wrap('file:' . $name, $shown)
            . ($cut ? sprintf(t('media.intake.cut'), $limit, $path ?? $name) : '');
    }

    /** Keeps the file under a free name in the folder; null — not kept (the reason is logged). */
    private function keep(Principal $principal, SandboxLimits $limits, string $folder, string $name, string $bytes, Cancellation $cancellation): ?string {
        if ($this->sandbox === null) {
            return null;
        }
        try {
            $taken = [];
            try {
                foreach ($this->sandbox->list($principal->id, $limits, $folder, 1, $cancellation) as $entry) {
                    $taken[basename($entry['path'])] = true;
                }
            } catch (SandboxFailure) {
                // the folder of the day is not there yet
            }
            $number = 1;
            while (isset($taken[MediaNames::numbered($name, $number)])) {
                $number++;
            }
            $path = $folder . '/' . MediaNames::numbered($name, $number);
            $this->sandbox->put($principal->id, $limits, $path, $bytes, $cancellation);
            return $path;
        } catch (SandboxFailure $e) {
            $this->logger->notice('Attachment was not kept', ['principal' => $principal->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return null;
        }
    }

    /** "📎 photo photo-7.jpg, 120 KB, 1280×853 → inbox/2026-11-17/photo-7.jpg" */
    private function line(Attachment $attachment, string $name, ?int $bytes, ?string $path): string {
        $details = [];
        $size = $bytes ?? $attachment->size;
        if ($size !== null) {
            $details[] = self::size($size);
        }
        if ($attachment->duration !== null) {
            $details[] = sprintf(t('media.intake.seconds'), $attachment->duration);
        }
        if ($attachment->width !== null && $attachment->height !== null) {
            $details[] = $attachment->width . '×' . $attachment->height;
        }
        if ($attachment->forwarded) {
            $details[] = t('media.intake.forwarded');
        }
        return '📎 ' . t('media.kind.' . $attachment->kind->value) . ' ' . $name
            . ($details === [] ? '' : ', ' . implode(', ', $details))
            . ($path === null ? '' : ' → ' . $path);
    }

    private static function size(int $bytes): string {
        return $bytes >= 1_000_000 ? sprintf(t('media.intake.mb'), round($bytes / 1_000_000, 1)) : sprintf(t('media.intake.kb'), max(1, (int) round($bytes / 1000)));
    }

    /** A text from Windows (cp1251), as Russian files often are; null — not text. */
    private static function fromWindows(string $bytes): ?string {
        if (str_contains($bytes, "\0")) {
            return null;
        }
        $text = iconv('Windows-1251', 'UTF-8', $bytes);
        return $text !== false && mb_check_encoding($text, 'UTF-8') ? $text : null;
    }
}
