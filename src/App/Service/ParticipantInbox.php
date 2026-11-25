<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Closure;
use Mercurius\Messenger\Button;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\Domain\Entity\Member;
use OpenCCK\Domain\Entity\MemberKind;
use OpenCCK\Domain\Repository\MemberRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The private place of a participant, whoever they are: a person gets a message in their private chat, an agent gets
 * it over the network of agents. What the code itself has to tell someone — an offer of a project, the report of a
 * job — goes through here, so people and agents are told alike.
 *
 * spec: brief/participants.md#доступ-к-проектам-и-передача — the taker of a project is told wherever they are
 */
final class ParticipantInbox {
    /** @var (Closure(Member, string): bool)|null sends a text to an agent; false — the agent is not reachable now */
    private ?Closure $toAgent = null;

    public function __construct(
        private readonly Channel $channel,
        private readonly MemberRepository $principals,
        /** the channel where people have private chats */
        private readonly string $channelName,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @param Closure(Member, string): bool $toAgent */
    public function useAgents(Closure $toAgent): void {
        $this->toAgent = $toAgent;
    }

    /**
     * @param list<list<Button>> $buttons shown to a person; an agent answers in words
     * @return bool whether it went out
     */
    public function tell(Member $to, string $text, array $buttons = []): bool {
        $text = SecretMasker::mask($text);
        try {
            if ($to->kind === MemberKind::Agent) {
                return $this->toAgent !== null && ($this->toAgent)($to, $text);
            }
            $chat = $this->principals->externalId($to->id, $this->channelName);
            return $chat !== null && $this->channel->send(new OutgoingMessage($chat, $text, buttons: $buttons))->success;
        } catch (Throwable $e) {
            $this->logger->warning('A participant was not told', ['principal' => $to->id, 'error' => $e::class]);
            return false;
        }
    }
}
