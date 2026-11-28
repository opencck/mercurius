<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App\Service;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\Llm\Moonshot\KimiCodeUsage;
use OpenCCK\App\Agent\Model\ProviderPauses;
use OpenCCK\App\Service\KimiCommands;
use OpenCCK\Test\Support\QueuedHttp;

use function Mercurius\I18n\t;

final class KimiCommandsTest extends AsyncTestCase {
    public function testWithoutAKeyTheCommandSaysHowToConnect(): void {
        $kimi = new KimiCommands(null, 'https://api.kimi.ai/coding/v1', new ProviderPauses());

        self::assertSame(t('kimi.no_key'), $kimi->handle(''));
    }

    public function testTheQuotaShowsTheWindowsTheWalletAndThePause(): void {
        // spec: brief/models.md#подписка-kimi-code-условия-и-лимиты
        $http = (new QueuedHttp())->stream('{"usages":{"limit_5h":{"used_ratio":1,"reset_time":"2026-11-28T15:00:00Z"},"limit_month_total":{"used_ratio":0.4}},'
            . '"boosterWallet":{"balance":{"type":"BOOSTER","amount":"1000000000","amountLeft":"250000000"}}}');
        $pauses = new ProviderPauses();
        $pauses->pause('kimi', (int) strtotime('2026-11-28T15:00:00Z'), ProviderPauses::LIMIT);
        $kimi = new KimiCommands(new KimiCodeUsage($http->client(), 'sk-kimi', 'dev'), 'https://api.kimi.ai/coding/v1', $pauses);

        $text = $kimi->handle('');

        self::assertStringContainsString(sprintf(t('kimi.state'), 'https://api.kimi.ai/coding/v1'), $text);
        self::assertStringContainsString('• ' . t('kimi.window.5h') . ': 100% (2026-11-28 15:00 UTC)', $text);
        self::assertStringContainsString('• ' . t('kimi.window.month') . ': 40%', $text);
        self::assertStringContainsString(sprintf(t('kimi.extra_usage'), 2.5, 10.0), $text);
        self::assertStringContainsString(sprintf(t('kimi.paused_limit'), '2026-11-28 15:00 UTC'), $text);
        self::assertStringNotContainsString('sk-kimi', $text);

        self::assertSame(t('kimi.resumed'), $kimi->handle('resume'));
        self::assertFalse($pauses->paused('kimi'));
    }
}
