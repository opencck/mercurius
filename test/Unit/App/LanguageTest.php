<?php

declare(strict_types=1);

namespace OpenCCK\Test\Unit\App;

use Mercurius\I18n\CatalogTranslator;
use Mercurius\I18n\Catalogs;
use Mercurius\I18n\Lang;
use OpenCCK\App\Service\SystemPrompt;
use OpenCCK\App\Settings\SettingsService;
use OpenCCK\Domain\Group\PublicChannel;
use PHPUnit\Framework\TestCase;

/**
 * Everything the agent writes itself comes from the catalogs, in the language set by AGENT_LANGUAGE.
 *
 * spec: BRIEF.md#язык-агента
 */
final class LanguageTest extends TestCase {
    protected function tearDown(): void {
        Lang::reset();
        parent::tearDown();
    }

    public function testEveryKeyTheCodeAsksForIsInTheCatalog(): void {
        $catalog = Catalogs::bundled()['ru'];
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            preg_match_all("/\\bt\\('([a-z0-9_.]+)'\\)/", $code, $m);
            preg_match_all("/const [A-Z_]+ = '([a-z0-9_]+\\.[a-z0-9_.]+)';/", $code, $c);
            foreach ([...$m[1], ...$c[1]] as $key) {
                if (!isset($catalog[$key])) {
                    $missing[] = $file->getFilename() . ': ' . $key;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testAnEnglishAgentSpeaksEnglish(): void {
        Lang::use(new CatalogTranslator('en', Catalogs::bundled()));

        $prompt = SystemPrompt::text();
        self::assertStringContainsString('Mercurius', $prompt);
        self::assertDoesNotMatchRegularExpression('/[А-Яа-яЁё]/u', $prompt);
        self::assertDoesNotMatchRegularExpression('/[А-Яа-яЁё]/u', (new PublicChannel('telegram', '-1', 'Blog', true, true, true, true))->rights());
        self::assertDoesNotMatchRegularExpression('/[А-Яа-яЁё]/u', \Mercurius\I18n\t('settings.about.agent_language'));
    }

    public function testRussianStaysTheDefault(): void {
        self::assertStringStartsWith('Ты — Меркурий', SystemPrompt::text());
        self::assertSame('публикация, правка', (new PublicChannel('telegram', '-1', 'Блог', true, true, false, true))->rights());
        self::assertTrue(class_exists(SettingsService::class));
    }
}
