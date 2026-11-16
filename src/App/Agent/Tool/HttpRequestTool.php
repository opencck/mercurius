<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool;

use Amp\Http\Client\DelegateHttpClient;
use Amp\Http\Client\Request;
use Mercurius\Llm\ToolDefinition;
use OpenCCK\Domain\Agent\DangerClass;

/**
 * An HTTP request to a public URL. GET and HEAD read; other methods change something outside and are irreversible
 * (they wait for the owner's approval). The result is untrusted data. Redirects are followed by hand, at most 5, and every
 * target is checked by AddressGuard again; the client's connector checks the address it actually dials as well.
 *
 * spec: BRIEF.md#инструменты — http_request; GET/HEAD read, internal addresses are a hard ban
 * ref: references/hermes-agent/website/docs/user-guide/security.md@e96221f:L819-L833 — redirect chains re-checked at every hop
 */
final class HttpRequestTool implements Tool, GrantKeyed {
    public const NAME = 'http_request';
    private const MAX_REDIRECTS = 5;
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
    private const MAX_BODY = 1_048_576;

    public function __construct(
        private readonly DelegateHttpClient $http,
        private readonly AddressGuard $guard,
    ) {}

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, 'HTTP-запрос к публичному адресу (GET, HEAD; POST, PUT, PATCH, DELETE — только с подтверждения владельца). Возвращает код ответа, '
            . 'content-type и тело (до 1 МБ). Внутренние адреса недоступны.', [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Адрес http или https'],
                'method' => ['type' => 'string', 'enum' => self::METHODS, 'description' => 'По умолчанию GET; остальные методы меняют что-то снаружи и требуют подтверждения владельца'],
                'body' => ['type' => 'string', 'maxLength' => 1048576, 'description' => 'Тело запроса для POST, PUT, PATCH'],
                'headers' => ['type' => 'object', 'description' => 'Дополнительные заголовки: имя → строка'],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return in_array(self::method($args), ['GET', 'HEAD'], true) ? DangerClass::Read : DangerClass::Irreversible;
    }

    public function grantKey(array $args): ?string {
        $method = self::method($args);
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return null;
        }
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        return is_string($host) && $host !== '' ? sprintf('http:%s %s', $method, strtolower($host)) : null;
    }

    public function describe(array $args): string {
        return sprintf('HTTP %s %s', self::method($args), mb_substr(Args::string($args, 'url') ?? '', 0, 300));
    }

    /**
     * @param array<string, mixed> $args
     * @return 'GET'|'HEAD'|'POST'|'PUT'|'PATCH'|'DELETE'
     */
    private static function method(array $args): string {
        return match (Args::string($args, 'method')) {
            'HEAD' => 'HEAD',
            'POST' => 'POST',
            'PUT' => 'PUT',
            'PATCH' => 'PATCH',
            'DELETE' => 'DELETE',
            default => 'GET',
        };
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 30;
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $url = Args::string($args, 'url') ?? '';
        $method = self::method($args);
        $body = Args::string($args, 'body');
        $headers = Args::stringMap($args, 'headers');

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower($parts['scheme'] ?? '');
            if (($scheme !== 'http' && $scheme !== 'https') || !isset($parts['host'])) {
                return ToolResult::error('Поддерживаются только адреса http и https.');
            }
            $refusal = $this->guard->refusal($parts['host']);
            if ($refusal !== null) {
                return ToolResult::error($refusal);
            }

            $request = new Request($url, $method, $body ?? '');
            foreach ($headers as $name => $value) {
                $request->setHeader($name, $value);
            }
            $request->setBodySizeLimit(PHP_INT_MAX);
            $response = $this->http->request($request, $context->cancellation);

            $location = $response->getHeader('location');
            // A request that changes something is not repeated at another address: the model decides.
            if (in_array($method, ['GET', 'HEAD'], true) && in_array($response->getStatus(), [301, 302, 303, 307, 308], true) && $location !== null) {
                $url = self::resolveLocation($url, $location);
                continue;
            }

            $text = sprintf("HTTP %d\ncontent-type: %s\n\n", $response->getStatus(), $response->getHeader('content-type') ?? '');
            return ToolResult::ok($text . $this->readBody($response->getBody(), $context));
        }
        return ToolResult::error(sprintf('Слишком много перенаправлений (больше %d).', self::MAX_REDIRECTS));
    }

    private function readBody(\Amp\ByteStream\ReadableStream $body, ToolContext $context): string {
        $data = '';
        while (($chunk = $body->read($context->cancellation)) !== null) {
            $data .= $chunk;
            if (strlen($data) > self::MAX_BODY) {
                $body->close();
                $data = mb_strcut($data, 0, self::MAX_BODY, 'UTF-8');
                return $this->text($data) . sprintf("\n\n[ответ обрезан: больше %d байт]", self::MAX_BODY);
            }
        }
        return $this->text($data);
    }

    private function text(string $data): string {
        return mb_check_encoding($data, 'UTF-8') ? $data : sprintf('[двоичные данные, %d байт]', strlen($data));
    }

    private static function resolveLocation(string $base, string $location): string {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';
        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }
}
