<?php

declare(strict_types=1);

namespace OpenCCK\Infrastructure\Browser;

use Amp\Cancellation;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use RuntimeException;
use SensitiveParameter;
use Throwable;

use function Mercurius\I18n\t;

/**
 * The agent's side of the browser service (browser/): navigate, read, click, type, screenshot, each in a named profile
 * whose logins survive restarts.
 *
 * spec: BRIEF.md#интернет-и-браузер
 */
final class BrowserClient {
    private readonly string $token;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        #[SensitiveParameter] string $token,
    ) {
        $this->token = $token;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     * @throws RuntimeException with a message for the model
     */
    public function call(string $action, array $body, Cancellation $cancellation): array {
        $request = new Request(rtrim($this->baseUrl, '/') . '/' . $action, 'POST', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $request->setHeader('authorization', 'Bearer ' . $this->token);
        $request->setHeader('content-type', 'application/json');
        $request->setTransferTimeout(90);
        $request->setInactivityTimeout(90);
        $request->setBodySizeLimit(20_000_000);
        try {
            $response = $this->http->request($request, $cancellation);
            $text = $response->getBody()->buffer($cancellation);
        } catch (Throwable $e) {
            throw new RuntimeException(t('browser.browser_client.brauzer_ne_otvetil') . (new \ReflectionClass($e))->getShortName());
        }
        /** @var mixed $data */
        $data = json_decode($text, true, 16);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf(t('browser.browser_client.brauzer_otvetil_neponyatno_http'), $response->getStatus()));
        }
        if ($response->getStatus() !== 200) {
            throw new RuntimeException(isset($data['error']) && is_string($data['error']) ? $data['error'] : sprintf('HTTP %d', $response->getStatus()));
        }
        return $data;
    }
}
