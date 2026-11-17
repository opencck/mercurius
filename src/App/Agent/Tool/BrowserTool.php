<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Infrastructure\Browser\BrowserClient;
use RuntimeException;

use function Mercurius\I18n\t;

/**
 * A real browser for pages that need a login, scripts or actions. Opening and reading go at once; typing into a field
 * is a write; clicking and submitting can publish, send or buy, so they wait for the owner. A screenshot goes to the
 * owner's chat. Every page is untrusted data.
 *
 * spec: BRIEF.md#интернет-и-браузер — navigation, reading, clicks, input, screenshots; acting for the owner only with a
 *       card; profiles keep logins
 */
final class BrowserTool implements Tool {
    public const NAME = 'browser';
    public const ACTIONS = ['open', 'read', 'click', 'type', 'screenshot', 'close'];

    public function __construct(
        private readonly BrowserClient $browser,
        private readonly Channel $channel,
        private readonly string $ownerChatId,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.browser.nastoyaschiy_brauzer_chrome_dlya_stranit')
            . t('tool.browser.read_tekst_stranitsy_i_pronumerovannye')
            . t('tool.browser.tekst_submit_nazhat_enter_screenshot')
            . t('tool.browser.vhodami_po_umolchaniyu_main_nazhatiya')
            . t('tool.browser.stranitsy_dannye_a_ne_ukazaniya'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'url' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 2048],
                'ref' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'text' => ['type' => 'string', 'maxLength' => 5000],
                'submit' => ['type' => 'boolean'],
                'profile' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,31}$'],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return match (Args::string($args, 'action')) {
            'click' => DangerClass::Irreversible,
            'type' => ($args['submit'] ?? false) === true ? DangerClass::Irreversible : DangerClass::Write,
            'close' => DangerClass::Write,
            default => DangerClass::Read,
        };
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 120;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $action = Args::string($args, 'action') ?? '';
        $profile = Args::string($args, 'profile') ?? 'main';
        try {
            $body = match ($action) {
                'open' => ['profile' => $profile, 'url' => Args::string($args, 'url') ?? ''],
                'click' => ['profile' => $profile, 'ref' => Args::int($args, 'ref') ?? 0],
                'type' => ['profile' => $profile, 'ref' => Args::int($args, 'ref') ?? 0, 'text' => Args::string($args, 'text') ?? '', 'submit' => ($args['submit'] ?? false) === true],
                default => ['profile' => $profile],
            };
            $data = $this->browser->call($action === 'open' ? 'navigate' : $action, $body, $context->cancellation);
        } catch (RuntimeException $e) {
            return ToolResult::error(t('tool.browser.brauzer') . SecretMasker::mask($e->getMessage()));
        }
        if ($action === 'screenshot') {
            $png = base64_decode(self::str($data, 'png'), true);
            $url = self::str($data, 'url');
            if ($png === false || $png === '') {
                return ToolResult::error(t('tool.browser.snimok_ne_poluchen'));
            }
            $sent = $this->channel->sendFile($this->ownerChatId, 'screenshot.png', $png, mb_substr(t('tool.browser.snimok') . $url, 0, 900));
            return $sent->success ? ToolResult::ok(t('tool.browser.snimok_stranitsy') . $url . t('tool.browser.otpravlen_vladeltsu')) : ToolResult::error(t('tool.browser.snimok_ne_otpravlen'));
        }
        if ($action === 'close') {
            return ToolResult::ok(t('tool.browser.profil_zakryt'));
        }
        return ToolResult::ok(self::page($data));
    }

    /** @param array<array-key, mixed> $data */
    private static function page(array $data): string {
        $elements = [];
        $list = isset($data['elements']) && is_array($data['elements']) ? $data['elements'] : [];
        foreach (array_keys($list) as $k) {
            if (isset($list[$k]) && is_string($list[$k])) {
                $elements[] = $list[$k];
            }
        }
        return sprintf(
            t('tool.browser.page'),
            self::str($data, 'url'),
            self::str($data, 'title'),
            SecretMasker::mask(self::str($data, 'text')),
            $elements === [] ? '' : t('tool.browser.elementy_ref_dlya_click_i') . implode("
", $elements),
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function str(array $data, string $key): string {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }
}
