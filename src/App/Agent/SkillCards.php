<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent;

use Mercurius\Messenger\Button;
use Mercurius\Messenger\CallbackHandler;
use Mercurius\Messenger\Channel;
use Mercurius\Messenger\IncomingCallback;
use Mercurius\Messenger\OutgoingMessage;
use OpenCCK\Domain\Repository\SkillRepository;
use OpenCCK\Domain\Skill\SkillVersion;

use function Mercurius\I18n\t;

/**
 * A proposed skill or a new version of one is a card in the owner's chat: accept or reject. Until accepted it is not
 * in the index and cannot be loaded.
 *
 * spec: BRIEF.md#скиллы-и-mcp — a draft skill is not used before the owner approves it
 */
final class SkillCards implements CallbackHandler {
    public const PREFIX = 'sk';
    private const PREVIEW = 2500;

    public function __construct(
        private readonly SkillRepository $skills,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
    ) {}

    public function show(SkillVersion $version): bool {
        $body = mb_strlen($version->body) > self::PREVIEW ? mb_substr($version->body, 0, self::PREVIEW) . sprintf(t('agent.skill_cards.full_after'), $version->name) : $version->body;
        $text = sprintf(
            t('agent.skill_cards.skill_versiya_opisanie_prichina_slova'),
            $version->name,
            $version->version,
            $version->version === 1 ? t('agent.skill_cards.novyy') : '',
            $version->description,
            $body,
            $version->reason,
        );
        return $this->channel->send(new OutgoingMessage($this->ownerChatId, $text, buttons: [[
            new Button(t('agent.skill_cards.prinyat'), sprintf('%s:%d:a', self::PREFIX, $version->id)),
            new Button(t('agent.skill_cards.otklonit'), sprintf('%s:%d:r', self::PREFIX, $version->id)),
        ]]))->success;
    }

    public function handleCallback(IncomingCallback $callback): void {
        if (preg_match('/^sk:(\d+):([ar])$/', $callback->data, $match) !== 1) {
            $this->channel->answerCallback($callback->callbackId);
            return;
        }
        $version = $this->skills->version((int) $match[1]);
        if ($match[2] === 'a') {
            $skill = $this->skills->accept((int) $match[1]);
            $outcome = $skill === null ? null : sprintf(t('agent.skill_cards.prinyato_skill_versii_v_kataloge'), $skill->name, $skill->version);
        } else {
            $outcome = $this->skills->reject((int) $match[1]) ? t('agent.skill_cards.otkloneno') : null;
        }
        $this->channel->answerCallback($callback->callbackId, $outcome ?? t('agent.skill_cards.uzhe_resheno'));
        if ($outcome !== null && $callback->messageId !== null && $version !== null) {
            $this->channel->edit($callback->chatId, $callback->messageId, sprintf(t('agent.skill_cards.skill_versiya'), $version->name, $version->version, $outcome));
        }
    }
}
