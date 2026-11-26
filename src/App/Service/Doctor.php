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

use function Mercurius\I18n\t;

/**
 * Checks of an installation: settings, the database and its migrations, the disk, the sandbox and the browser. Each
 * check says what is wrong and what to do; values of secrets are never shown.
 *
 * spec: brief/operations.md#как-сделано-поставка — doctor finds typical mistakes of an installation
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
        private readonly ?\OpenCCK\Domain\Repository\CommandAuditRepository $audit = null,
        /** @var (\Closure(): list<string>)|null the stored settings the catalog no longer has */
        private readonly ?\Closure $unknownSettings = null,
        /** @var (\Closure(): list<\OpenCCK\Domain\Mcp\McpServer>)|null the MCP connections of every context */
        private readonly ?\Closure $mcp = null,
    ) {}

    /** @return list<array{string, string, string}> status, what, details */
    public function run(): array {
        return [...$this->settings(), $this->database(), $this->disk(), $this->writable(), ...$this->outdated(), ...$this->audit(), ...$this->services(), ...$this->botApi(), ...$this->mcp()];
    }

    /**
     * spec: brief/skills-mcp.md#три-слоя-установленный-mcp-запись-подключение — connections in error, changed or with
     *       taken names are named with their context and why
     *
     * @return list<array{string, string, string}>
     */
    private function mcp(): array {
        try {
            $all = $this->mcp === null ? [] : ($this->mcp)();
        } catch (Throwable) {
            // the database check says what is wrong with the base
            return [];
        }
        if ($all === []) {
            return [];
        }
        $bad = array_values(array_filter($all, static fn(\OpenCCK\Domain\Mcp\McpServer $s): bool => in_array($s->status, [\OpenCCK\Domain\Mcp\McpServer::ERROR, \OpenCCK\Domain\Mcp\McpServer::CHANGED, \OpenCCK\Domain\Mcp\McpServer::NAMES], true)));
        if ($bad === []) {
            return [[self::OK, 'mcp', sprintf(t('service.doctor.mcp_ok'), count($all))]];
        }
        return [[self::WARN, 'mcp', implode('; ', array_map(static fn(\OpenCCK\Domain\Mcp\McpServer $s): string => sprintf(
            '%s (%s): %s%s',
            $s->name,
            $s->context,
            t('service.mcp_commands.state.' . $s->status),
            $s->note === '' ? '' : ' — ' . $s->note,
        ), $bad))]];
    }

    /**
     * The local Bot API server answers for the bot: getMe in 10 s. Its root page answers even when it hung.
     *
     * spec: brief/channels.md#здоровье-локального-сервера-bot-api
     *
     * @return list<array{string, string, string}>
     */
    private function botApi(): array {
        $url = Env::raw($this->env, 'TELEGRAM_API_URL');
        $token = Env::raw($this->env, 'TELEGRAM_BOT_TOKEN');
        if ($url === null || $token === null || $this->http === null) {
            return [];
        }
        $up = (new \OpenCCK\Infrastructure\Services\BotApiProbe($this->http, $url, $token))();
        return [$up ? [self::OK, 'telegram-api', t('service.doctor.otvechaet')] : [self::FAIL, 'telegram-api', t('service.doctor.ne_otvechaet') . 'getMe) — docker compose ps' . t('service.doctor.profile')]];
    }

    /**
     * spec: brief/servers.md#как-сделано-хранилище-реестр-и-ssh — the chain of hashes of the command journal holds
     *
     * @return list<array{string, string, string}>
     */
    private function audit(): array {
        if ($this->audit === null) {
            return [];
        }
        try {
            $broken = $this->audit->verify();
        } catch (Throwable $e) {
            return [[self::WARN, t('service.doctor.audit'), t('service.doctor.zapros_ne_proshel') . $e::class]];
        }
        return [$broken === null
            ? [self::OK, t('service.doctor.audit'), t('service.doctor.audit_whole')]
            : [self::FAIL, t('service.doctor.audit'), sprintf(t('service.doctor.audit_broken'), $broken)]];
    }

    /** @param list<array{string, string, string}> $results */
    public static function render(array $results): string {
        $icon = [self::OK => '✅', self::WARN => '⚠️', self::FAIL => '❌'];
        $lines = array_map(static fn(array $r): string => sprintf('%s %s: %s', $icon[$r[0]] ?? '?', $r[1], $r[2]), $results);
        $failed = count(array_filter($results, static fn(array $r): bool => $r[0] === self::FAIL));
        $warned = count(array_filter($results, static fn(array $r): bool => $r[0] === self::WARN));
        return implode("\n", $lines) . "\n\n" . ($failed + $warned === 0 ? t('service.doctor.vse_v_poryadke') : sprintf(t('service.doctor.oshibok_preduprezhdeniy'), $failed, $warned));
    }

    /** @return list<array{string, string, string}> */
    private function settings(): array {
        $raw = fn(string $name): ?string => Env::raw($this->env, $name);
        $out = [];
        $out[] = $raw('TELEGRAM_BOT_TOKEN') !== null && preg_match('~^\d+:[\w-]{30,}$~', (string) $raw('TELEGRAM_BOT_TOKEN')) === 1
            ? [self::OK, t('service.doctor.token_bota'), t('service.doctor.zadan')]
            : [self::FAIL, t('service.doctor.token_bota'), t('service.doctor.telegram_bot_token_pust_ili')];
        $out[] = ctype_digit((string) $raw('TELEGRAM_ADMIN_ID'))
            ? [self::OK, t('service.doctor.vladelets'), t('service.doctor.telegram_admin_id_zadan')]
            : [self::FAIL, t('service.doctor.vladelets'), t('service.doctor.telegram_admin_id_chislovoy_id')];
        $out[] = $raw('OPENROUTER_API_KEY') !== null || $raw('OPENAI_API_KEY') !== null || $raw('ANTHROPIC_API_KEY') !== null || $raw('AWS_BEARER_TOKEN_BEDROCK') !== null
            ? [self::OK, t('service.doctor.modeli'), match (true) {
                $raw('OPENROUTER_API_KEY') !== null => t('service.doctor.klyuch_openrouter_zadan'),
                $raw('OPENAI_API_KEY') !== null => t('service.doctor.klyuch_openai_zadan'),
                $raw('ANTHROPIC_API_KEY') !== null => t('service.doctor.klyuch_anthropic_zadan'),
                default => t('service.doctor.klyuch_bedrock_zadan'),
            }]
            : [self::FAIL, t('service.doctor.modeli'), t('service.doctor.nuzhen_openrouter_api_key_ili')];
        $key = $raw('SECRETS_KEY');
        $out[] = match (true) {
            $key === null => [self::WARN, t('service.doctor.hranilische_sekretov'), t('service.doctor.secrets_key_pust_sekrety_ssh')],
            strlen((string) base64_decode($key, true)) === 32 => [self::OK, t('service.doctor.hranilische_sekretov'), t('service.doctor.klyuch_32_bayta')],
            default => [self::FAIL, t('service.doctor.hranilische_sekretov'), t('service.doctor.secrets_key_32_bayta_v')],
        };
        $appUrl = $raw('APP_URL');
        $out[] = $appUrl !== null
            ? ($raw('TELEGRAM_WEBHOOK_SECRET') !== null ? [self::OK, 'Telegram', t('service.doctor.webhook_na') . $appUrl] : [self::FAIL, 'Telegram', t('service.doctor.app_url_zadan_a_telegram')])
            : [self::OK, 'Telegram', t('service.doctor.long_polling_app_url_pust')];
        $zone = $raw('OWNER_TIMEZONE') ?? '+03:00';
        try {
            new DateTimeZone($zone);
            $out[] = [self::OK, t('service.doctor.chasovoy_poyas'), $zone];
        } catch (Throwable) {
            $out[] = [self::FAIL, t('service.doctor.chasovoy_poyas'), t('service.doctor.owner_timezone_ne_raspoznan') . $zone];
        }
        $quiet = $raw('QUIET_HOURS');
        if ($quiet !== null && preg_match('~^\d{1,2}-\d{1,2}$~', $quiet) !== 1) {
            $out[] = [self::WARN, t('service.doctor.tihie_chasy'), t('service.doctor.quiet_hours_s_do_naprimer')];
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
            return [self::FAIL, t('service.doctor.baza'), t('service.doctor.net_soedineniya')];
        }
        try {
            $row = $this->db->query('SELECT coalesce(max(version), 0) AS v FROM schema_migrations')->fetchRow();
        } catch (Throwable $e) {
            return [self::FAIL, t('service.doctor.baza'), t('service.doctor.zapros_ne_proshel') . $e::class];
        }
        $applied = isset($row['v']) && is_int($row['v']) ? $row['v'] : 0;
        return $applied === $latest
            ? [self::OK, t('service.doctor.baza'), sprintf(t('service.doctor.migratsii_primeneny'), $applied)]
            : [self::FAIL, t('service.doctor.baza'), sprintf(t('service.doctor.primeneno_iz_migratsiy_perezapustite_pri'), $applied, $latest)];
    }

    /**
     * Stored settings a release renamed or removed: they are not applied, the owner should know and may remove them.
     *
     * spec: brief/operations.md#настройки-агента
     * @return list<array{string, string, string}>
     */
    private function outdated(): array {
        try {
            $keys = $this->unknownSettings === null ? [] : ($this->unknownSettings)();
        } catch (Throwable) {
            return [];
        }
        return $keys === [] ? [] : [[self::WARN, t('service.doctor.settings_unknown'), sprintf(t('service.doctor.settings_unknown_list'), implode(', ', $keys))]];
    }

    /**
     * The data folder is a mount of the host: the application writes its archives and marks there as its own user.
     *
     * @return array{string, string, string}
     */
    private function writable(): array {
        return is_dir($this->storage) && is_writable($this->storage)
            ? [self::OK, t('service.doctor.storage'), t('service.doctor.storage_ok')]
            : [self::FAIL, t('service.doctor.storage'), t('service.doctor.storage_readonly')];
    }

    /** @return array{string, string, string} */
    private function disk(): array {
        $free = @disk_free_space($this->storage);
        $total = @disk_total_space($this->storage);
        if ($free === false || $total === false || $total <= 0.0) {
            return [self::WARN, t('service.doctor.disk'), t('service.doctor.mesto_ne_uznat')];
        }
        $share = $free / $total;
        $text = sprintf(t('service.doctor.svobodno_gb'), $free / 1e9, $share * 100.0);
        return match (true) {
            $share < 0.05 => [self::FAIL, t('service.doctor.disk'), $text . t('service.doctor.uberite_starye_obrazy_docker_image')],
            $share < 0.15 => [self::WARN, t('service.doctor.disk'), $text],
            default => [self::OK, t('service.doctor.disk'), $text],
        };
    }

    /** What the module of the Claude subscription says of itself: the CLI, the sign-in, the route. */
    private static function claudeState(string $name, string $body): string {
        if ($name !== 'claude-code') {
            return '';
        }
        /** @var mixed $data */
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return '';
        }
        $parts = [];
        foreach (['cli', 'auth', 'route'] as $key) {
            /** @var mixed $value */
            $value = $data[$key] ?? null;
            if (is_string($value) && preg_match('~^[A-Za-z0-9._-]{1,32}$~', $value) === 1) {
                $parts[] = $key . ': ' . $value;
            }
        }
        return $parts === [] ? '' : ' (' . implode(', ', $parts) . ')';
    }

    /** @return list<array{string, string, string}> */
    private function services(): array {
        $out = [];
        foreach ([
            t('service.doctor.sandbox') => ['SANDBOX_TOKEN', Env::raw($this->env, 'SANDBOX_URL') ?? 'http://sandbox:9000'],
            t('service.doctor.browser') => ['BROWSER_TOKEN', Env::raw($this->env, 'BROWSER_URL') ?? 'http://browser:3000'],
            // spec: brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы — modules in their compose profiles
            'media-video' => ['MEDIA_VIDEO_TOKEN', Env::raw($this->env, 'MEDIA_VIDEO_URL') ?? 'http://media-video:8091'],
            'media-docs' => ['MEDIA_DOCS_TOKEN', Env::raw($this->env, 'MEDIA_DOCS_URL') ?? 'http://media-docs:8092'],
            // spec: brief/models.md#claude-по-подписке — healthy is the CLI found and new enough; the sign-in and the route are said
            'claude-code' => ['CLAUDE_CODE_TOKEN', Env::raw($this->env, 'CLAUDE_CODE_URL') ?? 'http://claude-code:8094'],
        ] as $name => [$token, $url]) {
            if (Env::raw($this->env, $token) === null) {
                $out[] = [self::WARN, $name, $token . t('service.doctor.pust_vyklyuchen')];
                continue;
            }
            if ($this->http === null) {
                continue;
            }
            try {
                $response = $this->http->request(new Request($url . '/health'), new TimeoutCancellation(5));
                $body = $response->getBody()->buffer(limit: 10_000);
                $out[] = $response->getStatus() === 200 ? [self::OK, $name, t('service.doctor.otvechaet') . self::claudeState($name, $body)] : [self::FAIL, $name, t('service.doctor.otvet') . $response->getStatus()];
            } catch (Throwable $e) {
                $out[] = [self::FAIL, $name, t('service.doctor.ne_otvechaet') . $e::class . ') — docker compose ps' . ($name === t('service.doctor.sandbox') ? '' : t('service.doctor.profile'))];
            }
        }
        return $out;
    }
}
