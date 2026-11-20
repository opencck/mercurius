<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Self;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use Throwable;

use function Mercurius\I18n\t;

/**
 * Registers a provider's key in the owner's OpenRouter account (bring your own key), for example a key someone sent.
 * The key and the management key come from the vault; the call changes the account, so it waits for the owner.
 *
 * spec: brief/decisions.md#продукт-и-архитектура — a provider key through OpenRouter as an agent tool with the owner's confirmation
 */
final class OpenRouterByokTool implements Tool, GrantKeyed {
    public const NAME = 'openrouter_byok';
    /** the secret with the OpenRouter management key */
    public const MANAGEMENT_SECRET = 'openrouter-management';
    private const URL = 'https://openrouter.ai/api/v1/byok';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Vault $vault,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.open_router_byok.registriruet_klyuch_provaydera_openai_an')
            . t('tool.open_router_byok.podtverzhdeniya_vladeltsa_key_secret_imy')
            . '«' . self::MANAGEMENT_SECRET . t('tool.open_router_byok.modeli_samogo_agenta_etim_ne'), [
            'type' => 'object',
            'properties' => [
                'provider' => ['type' => 'string', 'pattern' => '^[a-z0-9-]{2,40}$'],
                'key_secret' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            ],
            'required' => ['provider', 'key_secret'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return DangerClass::Irreversible;
    }

    /**
     * spec: brief/agent-loop.md#разрешения-навсегда — every irreversible action can be allowed for good; the key names the
     * action and its target (owner's decision 2026-11-17)
     */
    public function grantKey(array $args): ?string {
        $provider = Args::string($args, 'provider') ?? '';
        return $provider === '' ? null : 'openrouter-byok:' . $provider;
    }

    public function describe(array $args): string {
        return sprintf('openrouter_byok %s ← %s', Args::string($args, 'provider') ?? '', Args::string($args, 'key_secret') ?? '');
    }

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $management = $this->vault->reveal(self::MANAGEMENT_SECRET, $context->principal);
        if ($management === null) {
            return ToolResult::error(sprintf(t('tool.open_router_byok.net_sekreta_s_klyuchom_upravleniya'), self::MANAGEMENT_SECRET));
        }
        $key = $this->vault->reveal(Args::string($args, 'key_secret') ?? '', $context->principal);
        if ($key === null) {
            return ToolResult::error(t('tool.open_router_byok.sekret_s_klyuchom_provaydera_nedostupen'));
        }
        $body = ['provider' => Args::string($args, 'provider') ?? '', 'key' => trim($key)];
        $name = Args::string($args, 'name');
        if ($name !== null) {
            $body['name'] = $name;
        }
        $request = new Request(self::URL, 'POST', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $request->setHeader('authorization', 'Bearer ' . trim($management));
        $request->setHeader('content-type', 'application/json');
        try {
            $response = $this->http->request($request, $context->cancellation);
            $text = $response->getBody()->buffer($context->cancellation);
        } catch (Throwable $e) {
            return ToolResult::error(t('tool.open_router_byok.openrouter_ne_otvetil') . (new \ReflectionClass($e))->getShortName());
        }
        /** @var mixed $data */
        $data = json_decode($text, true, 8);
        if ($response->getStatus() !== 201 && $response->getStatus() !== 200) {
            return ToolResult::error(t('tool.open_router_byok.openrouter_otkazal') . SecretMasker::mask(self::field($data, 'error', 'message') ?? 'HTTP ' . $response->getStatus()));
        }
        return ToolResult::ok(sprintf(
            t('tool.open_router_byok.klyuch_zaregistrirovan_v_openrouter_id'),
            $body['provider'],
            self::field($data, 'data', 'id') ?? '?',
            self::field($data, 'data', 'label') ?? '?',
        ));
    }

    private static function field(mixed $data, string $outer, string $inner): ?string {
        if (!is_array($data) || !isset($data[$outer]) || !is_array($data[$outer])) {
            return null;
        }
        return isset($data[$outer][$inner]) && is_string($data[$outer][$inner]) ? $data[$outer][$inner] : null;
    }
}
