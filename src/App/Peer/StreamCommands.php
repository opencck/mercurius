<?php

declare(strict_types=1);

namespace OpenCCK\App\Peer;

use Mercurius\Messenger\IncomingMessage;
use OpenCCK\App\Service\MessageMeta;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Peer\PlaceStream;
use OpenCCK\Domain\Repository\PlaceStreamRepository;
use OpenCCK\Domain\Repository\MemberRepository;
use OpenCCK\Domain\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;

use function Mercurius\I18n\t;

/**
 * The owner of the agent gives agents of the network the streams of places: "/stream give <agent>" in a chat, a topic
 * or the owner's private chat with the bot gives its stream, "/stream take <agent>" there takes it back, "/streams" lists them, "/stream remove <number>" takes
 * one back from anywhere. Taking back ends a subscription at once.
 *
 * spec: brief/network.md#потоки-мест-для-агентов
 */
final class StreamCommands {
    public function __construct(
        private readonly PlaceStreamRepository $streams,
        private readonly MemberRepository $principals,
        private readonly LoggerInterface $logger,
        private readonly ?ProjectRepository $projects = null,
    ) {}

    public function handle(Member $who, IncomingMessage $message, string $command, string $argument): string {
        if (!$who->isOwner()) {
            return t('peer.streams.owner_only');
        }
        if ($command === '/streams') {
            return $this->list();
        }
        $parts = preg_split('~\s+~u', trim($argument), 2) ?: [];
        $action = strtolower($parts[0] ?? '');
        $rest = trim($parts[1] ?? '');
        if ($action === 'remove') {
            $stream = $this->streams->revoke((int) ltrim($rest, '#'));
            return $stream === null ? t('peer.streams.none') : sprintf(t('peer.streams.taken'), $this->name($stream->principalId), $stream->title);
        }
        if ($action !== 'give' && $action !== 'take') {
            return t('peer.streams.usage');
        }
        $agent = $this->agent($rest);
        if ($agent === null) {
            return sprintf(t('peer.streams.no_agent'), $rest);
        }
        $thread = MessageMeta::topicKey($message);
        if ($action === 'take') {
            foreach ($this->streams->of($agent->id) as $stream) {
                if ($stream->channel === $message->channel && $stream->chatId === $message->chatId && $stream->threadId === $thread) {
                    $this->streams->revoke($stream->id);
                    $this->logger->info('Stream of a place taken back', ['stream' => $stream->id]);
                    return sprintf(t('peer.streams.taken'), $agent->displayName ?? ('#' . $agent->id), $stream->title);
                }
            }
            return t('peer.streams.none');
        }
        $project = $this->projects?->active($message->channel, $message->chatId, $thread);
        // the owner's own private chat is the owner's to give: only the owner writes these commands
        $title = $message->isPrivateChat ? t('peer.streams.title_private') : ($project !== null ? sprintf(t('peer.streams.title_project'), $project->name) : ($thread === '' ? sprintf(t('peer.streams.title_chat'), $message->chatId) : sprintf(t('peer.streams.title_topic'), $message->chatId, $thread)));
        $stream = $this->streams->grant($agent->id, $message->channel, $message->chatId, $thread, $title, $who->id);
        $this->logger->info('Stream of a place given', ['stream' => $stream->id, 'agent' => $agent->id]);
        return sprintf(t('peer.streams.given'), $agent->displayName ?? ('#' . $agent->id), $title, $stream->id);
    }

    private function list(): string {
        $streams = $this->streams->of(null);
        if ($streams === []) {
            return t('peer.streams.empty') . "\n\n" . t('peer.streams.usage');
        }
        return t('peer.streams.head') . "\n" . implode("\n", array_map(fn(PlaceStream $s): string => sprintf(
            '#%d · %s · %s%s',
            $s->id,
            $this->name($s->principalId),
            $s->title,
            $s->subscribed ? ' · ' . t('peer.streams.listening') : '',
        ), $streams)) . "\n\n" . t('peer.streams.usage');
    }

    private function name(int $id): string {
        return $this->principals->find($id)->displayName ?? ('#' . $id);
    }

    /** An agent of the network by "#id" or its name. */
    private function agent(string $named): ?Member {
        $id = ltrim($named, '#');
        foreach ($this->principals->all() as $principal) {
            if ($principal->kind === MemberKind::Agent && (($id !== '' && ctype_digit($id) && $principal->id === (int) $id) || ($named !== '' && mb_strtolower((string) $principal->displayName) === mb_strtolower($named)))) {
                return $principal;
            }
        }
        return null;
    }
}
