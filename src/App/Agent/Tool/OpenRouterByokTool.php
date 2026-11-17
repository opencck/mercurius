<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Vault\Vault;
use OpenCCK\Domain\Agent\DangerClass;
use Throwable;

/**
 * Registers a provider's key in the owner's OpenRouter account (bring your own key), for example a key someone sent.
 * The key and the management key come from the vault; the call changes the account, so it waits for the owner.
 *
 * spec: BRIEF.md#продукт-и-архитектура — a provider key through OpenRouter as an agent tool with the owner's confirmation
 */
final class OpenRouterByokTool implements Tool {
    public const NAME = 'openrouter_byok';
    /** the secret with the OpenRouter management key */
    public const MANAGEMENT_SECRET = 'openrouter-management';
    private const URL = 'https://openrouter.ai/api/v1/byok';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Vault $vault,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'Регистрирует ключ провайдера (openai, anthropic…) в аккаунте OpenRouter владельца (BYOK); ждёт '
            . 'подтверждения владельца. key_secret — имя секрета с ключом провайдера; ключ управления OpenRouter — в секрете '
            . '«' . self::MANAGEMENT_SECRET . '». Модели самого агента этим не переключаются.', [
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

    public function untrusted(): bool {
        return false;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $management = $this->vault->reveal(self::MANAGEMENT_SECRET, $context->principal);
        if ($management === null) {
            return ToolResult::error(sprintf('Нет секрета «%s» с ключом управления OpenRouter: владелец кладёт его командой /secret.', self::MANAGEMENT_SECRET));
        }
        $key = $this->vault->reveal(Args::string($args, 'key_secret') ?? '', $context->principal);
        if ($key === null) {
            return ToolResult::error('Секрет с ключом провайдера недоступен.');
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
            return ToolResult::error('OpenRouter не ответил: ' . (new \ReflectionClass($e))->getShortName());
        }
        /** @var mixed $data */
        $data = json_decode($text, true, 8);
        if ($response->getStatus() !== 201 && $response->getStatus() !== 200) {
            return ToolResult::error('OpenRouter отказал: ' . SecretMasker::mask(self::field($data, 'error', 'message') ?? 'HTTP ' . $response->getStatus()));
        }
        return ToolResult::ok(sprintf(
            'Ключ %s зарегистрирован в OpenRouter: id %s, метка %s.',
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
