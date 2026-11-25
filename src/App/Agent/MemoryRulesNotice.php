<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Amp\TimeoutCancellation;
use Closure;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Agent\Run;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Repository\ConversationRepository;
use OpenCCK\Domain\Repository\MemoryRuleRepository;
use OpenCCK\Domain\Repository\MemberRepository;
use Psr\Log\LoggerInterface;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The notice of a change of the rules of a MEMORY.md, when the owner keeps it on: after a run, the section «Правила» of
 * each file the conversation reads is compared with what was seen last; a change is told — a person's own file to that
 * person, a project's or a place's to the owner — with «Убрать», which puts the section back as it was before that
 * change, unless it changed again since. Any tool may have written the file: the check reads the file itself. With the
 * notice off nothing is kept and nothing can be taken back.
 *
 * spec: brief/memory.md#сообщение-об-изменении-правил
 */
final class MemoryRulesNotice implements CallbackHandler {
    public const PREFIX = 'mr';
    private const LINE = 200;
    private const LINES = 10;

    /** @param Closure(): bool $enabled the owner's setting, read at each check */
    public function __construct(
        private readonly ContextMemory $files,
        private readonly MemoryRuleRepository $rules,
        private readonly ConversationRepository $conversations,
        private readonly ConversationAudience $audience,
        private readonly Channel $channel,
        private readonly MemberRepository $principals,
        private readonly OwnerDirectory $owner,
        private readonly Closure $enabled,
        private readonly LoggerInterface $logger,
        /** the channel of private chats, where a person's own file is told */
        private readonly string $channelName = 'telegram',
    ) {}

    public function afterRun(Run $run, Member $principal): void {
        try {
            if (!($this->enabled)()) {
                $this->rules->forget();
                return;
            }
            $meta = $this->conversations->meta($run->conversationId);
            if ($meta === null) {
                return;
            }
            $private = $this->audience->isPrivate($run->conversationId);
            foreach ([$private ? $this->files->personal($principal) : null, $this->files->shared($principal, $meta->channel, $meta->chatId, $meta->threadId, $private)] as $place) {
                if ($place !== null) {
                    $this->check($place);
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Rules of MEMORY.md were not checked', ['run' => $run->id, 'error' => $e::class]);
        }
    }

    private function check(MemoryPlace $place): void {
        $now = MemoryFile::parse($this->files->read($place))->rules;
        $before = $this->rules->snapshot($place->key());
        $this->rules->keep($place->key(), $now);
        // the first look is the starting point: there is nothing to compare with
        if ($before === null || $before === $now) {
            return;
        }
        $id = $this->rules->record($place->holder, $place->path, $place->kind, $place->project, $before, $now);
        $chatId = $place->kind === MemoryPlace::PERSON ? $this->principals->externalId($place->holder, $this->channelName) : $this->owner->chatId();
        if ($chatId === null || $chatId === '') {
            return;
        }
        $this->channel->send(new OutgoingMessage($chatId, self::text($place->kind, $place->project, $before, $now), buttons: [[
            new Button(t('memory.rules.undo'), sprintf('%s:%d', self::PREFIX, $id)),
        ]]));
        $this->logger->info('Rules of MEMORY.md changed', ['change' => $id, 'kind' => $place->kind]);
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^mr:(\d+)$/', $callback->data, $m) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $change = $this->rules->change((int) $m[1]);
        if ($change === null || $change['undone']) {
            $this->channel->answerCallback($callback->callbackId, t('memory.rules.gone'));
            return;
        }
        $presser = $this->principals->findByIdentity($callback->channel, $callback->userId);
        $may = $presser !== null && ($presser->isOwner() || ($change['kind'] === MemoryPlace::PERSON && $presser->id === $change['holder']));
        if (!$may) {
            $this->channel->answerCallback($callback->callbackId, t('memory.rules.not_yours'), true);
            return;
        }
        $place = new MemoryPlace($change['holder'], $change['path'], self::kind($change['kind']), $change['projectId']);
        $file = MemoryFile::parse($this->files->read($place));
        if ($file->rules !== $change['after']) {
            // a newer change is not overwritten by an old button
            $this->channel->answerCallback($callback->callbackId, t('memory.rules.moved_on'), true);
            if ($callback->messageId !== null) {
                $this->channel->edit($callback->chatId, $callback->messageId, sprintf(t('memory.rules.moved_on_now'), self::cut(SecretMasker::mask($file->rules))));
            }
            return;
        }
        try {
            $this->files->write($place, MemoryFile::of($change['before'], $file->notes)->text(), new TimeoutCancellation(10));
        } catch (Throwable $e) {
            $this->logger->warning('Rules of MEMORY.md were not put back', ['change' => (int) $m[1], 'error' => $e::class]);
            $this->channel->answerCallback($callback->callbackId, t('memory.rules.failed'), true);
            return;
        }
        $this->rules->keep($place->key(), $change['before']);
        $this->rules->undone((int) $m[1]);
        $this->channel->answerCallback($callback->callbackId, t('memory.rules.undone'));
        if ($callback->messageId !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, t('memory.rules.undone'));
        }
    }

    /** What changed, line by line: added and gone. */
    public static function text(string $kind, ?int $project, string $before, string $after): string {
        $was = self::lines($before);
        $is = self::lines($after);
        $added = array_values(array_diff($is, $was));
        $gone = array_values(array_diff($was, $is));
        $head = match ($kind) {
            MemoryPlace::PERSON => t('memory.rules.changed_person'),
            MemoryPlace::PROJECT => sprintf(t('memory.rules.changed_project'), $project ?? 0),
            default => t('memory.rules.changed_place'),
        };
        $out = [$head];
        foreach (array_slice($added, 0, self::LINES) as $line) {
            $out[] = '+ ' . self::cut($line);
        }
        foreach (array_slice($gone, 0, self::LINES) as $line) {
            $out[] = '− ' . self::cut($line);
        }
        return SecretMasker::mask(implode("\n", $out));
    }

    /** @return list<string> */
    private static function lines(string $rules): array {
        return array_values(array_filter(array_map('trim', preg_split('~\R~u', $rules) ?: []), static fn(string $l): bool => $l !== ''));
    }

    private static function cut(string $text): string {
        return mb_strlen($text) > self::LINE ? mb_substr($text, 0, self::LINE) . '…' : $text;
    }

    /** @return MemoryPlace::PERSON|MemoryPlace::PROJECT|MemoryPlace::PLACE */
    private static function kind(string $kind): string {
        return match ($kind) {
            MemoryPlace::PERSON => MemoryPlace::PERSON,
            MemoryPlace::PROJECT => MemoryPlace::PROJECT,
            default => MemoryPlace::PLACE,
        };
    }
}
