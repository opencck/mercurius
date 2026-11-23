<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use function Mercurius\I18n\t;

/**
 * What the agent tells about its modules: the ones that run, the ones that do not answer and the ones that are switched
 * off — with who switches them on and how. A module that is off is still a module: without this line a model takes it
 * for one that does not exist.
 *
 * spec: brief/agent-loop.md#знания-агента-о-себе — the state is counted at the call
 * spec: brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы — a module is switched on in .env by its profile and token
 */
final class ModuleReport {
    /** the modules of an installation: name (the compose profile) => the variable of .env with its token */
    public const KNOWN = [
        'browser' => 'BROWSER_TOKEN',
        'media-video' => 'MEDIA_VIDEO_TOKEN',
        'media-docs' => 'MEDIA_DOCS_TOKEN',
        // spec: brief/models.md#claude-по-подписке — Claude by the owner's subscription
        'claude-code' => 'CLAUDE_CODE_TOKEN',
        'mail' => 'MAIL_MCP_TOKEN',
        // spec: brief/channels.md#здоровье-локального-сервера-bot-api — up while the bot answers through it
        'telegram-api' => 'TELEGRAM_API_URL',
    ];

    /**
     * @param array<string, bool> $states the modules that are set up: whether each is up
     * @return list<string>
     */
    public static function lines(array $states): array {
        $lines = [];
        foreach (self::KNOWN as $name => $variable) {
            $lines[] = '- ' . $name . ': ' . match ($states[$name] ?? null) {
                true => t('self.modules.up'),
                false => t('self.modules.down'),
                null => sprintf(t('self.modules.off'), $name, $variable),
            };
        }
        foreach ($states as $name => $up) {
            if (!isset(self::KNOWN[$name])) {
                $lines[] = '- ' . $name . ': ' . ($up ? t('self.modules.up') : t('self.modules.down'));
            }
        }
        return $lines;
    }
}
