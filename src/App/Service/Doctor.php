<?php

declare(strict_types=1);

namespace OpenCCK\App\Service;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Postgres\PostgresLink;
use Amp\TimeoutCancellation;
use DateTimeZone;
use OpenCCK\Infrastructure\Config\Env;
use Throwable;

/**
 * Checks of an installation: settings, the database and its migrations, the disk, the sandbox and the browser. Each
 * check says what is wrong and what to do; values of secrets are never shown.
 *
 * spec: BRIEF.md#решено-в-фазе-12 — doctor finds typical mistakes of an installation
 * ref: references/hermes-agent/hermes_cli/doctor.py@e96221fa:L114-L134 (DOCTOR_CHECKS) — an ordered list of checks;
 *      here the ones that matter for a Docker install: .env, database and migrations, disk, services
 */
final class Doctor {
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * @param array<array-key, mixed> $env
     */
    public function __construct(
        private readonly array $env,
        private readonly ?PostgresLink $db,
        private readonly string $migrations,
        private readonly string $storage,
        private readonly ?HttpClient $http = null,
    ) {}

    /** @return list<array{string, string, string}> status, what, details */
    public function run(): array {
        return [...$this->settings(), $this->database(), $this->disk(), ...$this->services()];
    }

    /** @param list<array{string, string, string}> $results */
    public static function render(array $results): string {
        $icon = [self::OK => '✅', self::WARN => '⚠️', self::FAIL => '❌'];
        $lines = array_map(static fn(array $r): string => sprintf('%s %s: %s', $icon[$r[0]] ?? '?', $r[1], $r[2]), $results);
        $failed = count(array_filter($results, static fn(array $r): bool => $r[0] === self::FAIL));
        $warned = count(array_filter($results, static fn(array $r): bool => $r[0] === self::WARN));
        return implode("\n", $lines) . "\n\n" . ($failed + $warned === 0 ? 'Всё в порядке.' : sprintf('Ошибок: %d, предупреждений: %d.', $failed, $warned));
    }

    /** @return list<array{string, string, string}> */
    private function settings(): array {
        $raw = fn(string $name): ?string => Env::raw($this->env, $name);
        $out = [];
        $out[] = $raw('TELEGRAM_BOT_TOKEN') !== null && preg_match('~^\d+:[\w-]{30,}$~', (string) $raw('TELEGRAM_BOT_TOKEN')) === 1
            ? [self::OK, 'Токен бота', 'задан']
            : [self::FAIL, 'Токен бота', 'TELEGRAM_BOT_TOKEN пуст или не похож на токен BotFather'];
        $out[] = ctype_digit((string) $raw('TELEGRAM_ADMIN_ID'))
            ? [self::OK, 'Владелец', 'TELEGRAM_ADMIN_ID задан']
            : [self::FAIL, 'Владелец', 'TELEGRAM_ADMIN_ID — числовой id владельца в Telegram'];
        $out[] = $raw('OPENROUTER_API_KEY') !== null || $raw('OPENAI_API_KEY') !== null
            ? [self::OK, 'Модели', $raw('OPENROUTER_API_KEY') !== null ? 'ключ OpenRouter задан' : 'ключ OpenAI задан']
            : [self::FAIL, 'Модели', 'нужен OPENROUTER_API_KEY или OPENAI_API_KEY'];
        $key = $raw('SECRETS_KEY');
        $out[] = match (true) {
            $key === null => [self::WARN, 'Хранилище секретов', 'SECRETS_KEY пуст: секреты, SSH и webhook заданий выключены'],
            strlen((string) base64_decode($key, true)) === 32 => [self::OK, 'Хранилище секретов', 'ключ 32 байта'],
            default => [self::FAIL, 'Хранилище секретов', 'SECRETS_KEY — 32 байта в base64 (openssl rand -base64 32)'],
        };
        $appUrl = $raw('APP_URL');
        $out[] = $appUrl !== null
            ? ($raw('TELEGRAM_WEBHOOK_SECRET') !== null ? [self::OK, 'Telegram', 'webhook на ' . $appUrl] : [self::FAIL, 'Telegram', 'APP_URL задан, а TELEGRAM_WEBHOOK_SECRET пуст'])
            : [self::OK, 'Telegram', 'long polling (APP_URL пуст)'];
        $zone = $raw('OWNER_TIMEZONE') ?? '+03:00';
        try {
            new DateTimeZone($zone);
            $out[] = [self::OK, 'Часовой пояс', $zone];
        } catch (Throwable) {
            $out[] = [self::FAIL, 'Часовой пояс', 'OWNER_TIMEZONE не распознан: ' . $zone];
        }
        $quiet = $raw('QUIET_HOURS');
        if ($quiet !== null && preg_match('~^\d{1,2}-\d{1,2}$~', $quiet) !== 1) {
            $out[] = [self::WARN, 'Тихие часы', 'QUIET_HOURS — «с-до», например 23-8; сейчас тихих часов нет'];
        }
        $digest = $raw('DIGEST_TIME');
        if ($digest !== null && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $digest) !== 1) {
            $out[] = [self::WARN, 'Сводка', 'DIGEST_TIME — ЧЧ:ММ; сейчас сводка выключена'];
        }
        return $out;
    }

    /** @return array{string, string, string} */
    private function database(): array {
        $files = glob(rtrim($this->migrations, '/') . '/*.sql');
        $files = $files === false ? [] : $files;
        $latest = 0;
        foreach ($files as $file) {
            $latest = max($latest, (int) basename($file));
        }
        if ($this->db === null) {
            return [self::FAIL, 'База', 'нет соединения'];
        }
        try {
            $row = $this->db->query('SELECT coalesce(max(version), 0) AS v FROM schema_migrations')->fetchRow();
        } catch (Throwable $e) {
            return [self::FAIL, 'База', 'запрос не прошёл: ' . $e::class];
        }
        $applied = isset($row['v']) && is_int($row['v']) ? $row['v'] : 0;
        return $applied === $latest
            ? [self::OK, 'База', sprintf('миграции применены (%d)', $applied)]
            : [self::FAIL, 'База', sprintf('применено %d из %d миграций — перезапустите приложение', $applied, $latest)];
    }

    /** @return array{string, string, string} */
    private function disk(): array {
        $free = @disk_free_space($this->storage);
        $total = @disk_total_space($this->storage);
        if ($free === false || $total === false || $total <= 0.0) {
            return [self::WARN, 'Диск', 'место не узнать'];
        }
        $share = $free / $total;
        $text = sprintf('свободно %.0f ГБ (%.0f%%)', $free / 1e9, $share * 100.0);
        return match (true) {
            $share < 0.05 => [self::FAIL, 'Диск', $text . ' — уберите старые образы: docker image prune'],
            $share < 0.15 => [self::WARN, 'Диск', $text],
            default => [self::OK, 'Диск', $text],
        };
    }

    /** @return list<array{string, string, string}> */
    private function services(): array {
        $out = [];
        foreach ([
            'Песочница' => ['SANDBOX_TOKEN', Env::raw($this->env, 'SANDBOX_URL') ?? 'http://sandbox:9000'],
            'Браузер' => ['BROWSER_TOKEN', Env::raw($this->env, 'BROWSER_URL') ?? 'http://browser:3000'],
        ] as $name => [$token, $url]) {
            if (Env::raw($this->env, $token) === null) {
                $out[] = [self::WARN, $name, $token . ' пуст — выключен'];
                continue;
            }
            if ($this->http === null) {
                continue;
            }
            try {
                $response = $this->http->request(new Request($url . '/health'), new TimeoutCancellation(5));
                $response->getBody()->buffer();
                $out[] = $response->getStatus() === 200 ? [self::OK, $name, 'отвечает'] : [self::FAIL, $name, 'ответ ' . $response->getStatus()];
            } catch (Throwable $e) {
                $out[] = [self::FAIL, $name, 'не отвечает (' . $e::class . ') — docker compose ps'];
            }
        }
        return $out;
    }
}
