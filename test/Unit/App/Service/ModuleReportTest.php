<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use OpenCCK\App\Service\ModuleReport;
use PHPUnit\Framework\TestCase;

use function Mercurius\I18n\t;

/**
 * spec: brief/agent-loop.md#знания-агента-о-себе — a module that is off is named, with how it is switched on
 */
final class ModuleReportTest extends TestCase {
    public function testAModuleThatIsOffIsNamedWithTheWayToSwitchItOn(): void {
        $text = implode("\n", ModuleReport::lines(['browser' => true, 'media-docs' => false, 'extra' => true]));

        self::assertStringContainsString('- browser: ' . t('self.modules.up'), $text);
        self::assertStringContainsString('- media-docs: ' . t('self.modules.down'), $text);
        self::assertStringContainsString('- mail: ' . sprintf(t('self.modules.off'), 'mail', 'MAIL_MCP_TOKEN'), $text, 'the mail module exists though it is off');
        self::assertStringContainsString('COMPOSE_PROFILES', $text);
        self::assertStringContainsString('- media-video: ', $text);
        self::assertStringContainsString('- telegram-api: ' . sprintf(t('self.modules.off'), 'telegram-api', 'TELEGRAM_API_URL'), $text, 'the local Bot API is a module too');
        self::assertStringContainsString('- extra: ' . t('self.modules.up'), $text, 'a module the list does not know is still shown');
    }
}
