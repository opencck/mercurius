<?php

declare(strict_types=1);

namespace OpenCCK\App\Settings;

/**
 * The settings the owner changes in the dialog. Everything else stays in .env: the link to Telegram (the first
 * setup goes through it), the database, Compose, the vault master key, the tokens the sandbox and browser read.
 *
 * spec: BRIEF.md#настройки-агента
 */
final class SettingsCatalog {
    public const SECRET = 'secret';
    public const TEXT = 'text';
    public const INT = 'int';
    public const FLOAT = 'float';

    /** @var array<string, array{string, string, string}> key => [group, kind, what it is] */
    public const ALL = [
        'OPENROUTER_API_KEY' => ['модели', self::SECRET, 'ключ OpenRouter'],
        'OPENAI_API_KEY' => ['модели', self::SECRET, 'ключ OpenAI'],
        'VLESS_URI' => ['модели', self::SECRET, 'VLESS для запросов к провайдерам моделей (пусто — напрямую)'],
        'LLM_MODEL' => ['модели', self::TEXT, 'основная модель (author/slug или provider:id)'],
        'LLM_CHEAP_MODEL' => ['модели', self::TEXT, 'дешёвая модель для простых задач и классификации'],
        'LLM_REASONING_EFFORT' => ['модели', self::TEXT, 'усилие рассуждения: none, low, medium, high'],
        'LLM_MAX_TOKENS' => ['модели', self::INT, 'потолок ответа модели, токенов'],
        'LLM_HISTORY_LIMIT' => ['модели', self::INT, 'сообщений истории в контексте'],
        'LLM_CONTEXT_WINDOW' => ['модели', self::INT, 'окно контекста, токенов'],
        'AGENT_MAX_TURNS' => ['лимиты', self::INT, 'вызовов модели на запуск'],
        'AGENT_TOKEN_BUDGET' => ['лимиты', self::INT, 'токенов на запуск'],
        'AGENT_RUN_TIMEOUT' => ['лимиты', self::INT, 'секунд на запуск'],
        'AGENT_TOOL_TIMEOUT' => ['лимиты', self::INT, 'секунд на вызов инструмента'],
        'AGENT_APPROVAL_TIMEOUT' => ['лимиты', self::INT, 'секунд ожидания подтверждения'],
        'AGENT_WORKER_MAX_TURNS' => ['лимиты', self::INT, 'вызовов модели у воркера'],
        'AGENT_WORKER_TOKEN_BUDGET' => ['лимиты', self::INT, 'токенов у воркера'],
        'AGENT_MAX_WORKERS_PER_RUN' => ['лимиты', self::INT, 'воркеров на запуск'],
        'AGENT_DAILY_SPEND_CAP' => ['лимиты', self::FLOAT, 'потолок расхода в день, $ (пусто — нет)'],
        'AGENT_MONTHLY_SPEND_CAP' => ['лимиты', self::FLOAT, 'потолок расхода в месяц, $ (пусто — нет)'],
        'JOB_TOKEN_BUDGET' => ['лимиты', self::INT, 'токенов на запуск задания'],
        'SEARCH_PROVIDERS' => ['поиск', self::TEXT, 'поставщики поиска по порядку: searxng, brave, tavily, openrouter'],
        'SEARXNG_URL' => ['поиск', self::TEXT, 'адрес своего SearXNG'],
        'BRAVE_SEARCH_API_KEY' => ['поиск', self::SECRET, 'ключ Brave Search'],
        'TAVILY_API_KEY' => ['поиск', self::SECRET, 'ключ Tavily'],
        'WEB_SEARCH_MODEL' => ['поиск', self::TEXT, 'модель OpenRouter для поиска'],
        'AGENT_LANGUAGE' => ['агент', self::TEXT, 'язык агента: ru или en'],
        'OWNER_TIMEZONE' => ['время', self::TEXT, 'часовой пояс владельца (Europe/Moscow или +03:00)'],
        'QUIET_HOURS' => ['время', self::TEXT, 'тихие часы «с-до», например 23-8'],
        'DIGEST_TIME' => ['время', self::TEXT, 'время ежедневной сводки ЧЧ:ММ (пусто — выключена)'],
        'MEMORY_CHECK_HOUR' => ['память', self::INT, 'час ночной проверки памяти'],
        'MEMORY_HRR_WEIGHT' => ['память', self::FLOAT, 'доля HRR в поиске по памяти, 0–1'],
        'TRACE_RETENTION_DAYS' => ['трассы', self::INT, 'дней хранения полных промптов'],
        'SSH_CONFIRM_GROUPS' => ['серверы', self::TEXT, 'группы серверов, где каждая команда — с подтверждением'],
        'METRICS_TOKEN' => ['трассы', self::SECRET, 'токен /metrics для Prometheus (пусто — выключен)'],
        'MAIL_HOST' => ['почта', self::TEXT, 'сервер почты (IMAP и SMTP), например mail.example.ru'],
        'MAIL_IMAP_PORT' => ['почта', self::INT, 'порт IMAP с TLS, обычно 993'],
        'MAIL_SMTP_PORT' => ['почта', self::INT, 'порт SMTP: 465 — TLS, 587 — STARTTLS'],
        'MAIL_USER' => ['почта', self::TEXT, 'логин ящика'],
        'MAIL_PASSWORD' => ['почта', self::SECRET, 'пароль ящика'],
        'MAIL_FROM' => ['почта', self::TEXT, 'адрес отправителя (пусто — логин)'],
        'MAIL_AUTHSERV_ID' => ['почта', self::TEXT, 'authserv-id своего сервера в Authentication-Results (пусто — MAIL_HOST)'],
        'A2A_NAME' => ['сеть', self::TEXT, 'имя агента в карточке A2A для соседей'],
        'A2A_DESCRIPTION' => ['сеть', self::TEXT, 'чем агент может помочь соседям (карточка A2A)'],
    ];

    public static function has(string $key): bool {
        return isset(self::ALL[$key]);
    }

    public static function secret(string $key): bool {
        return (self::ALL[$key][1] ?? null) === self::SECRET;
    }

    /** The shape of a value, before the full check of the settings; null — fits. */
    public static function problem(string $key, string $value): ?string {
        $kind = self::ALL[$key][1] ?? null;
        return match (true) {
            $kind === null => sprintf('Настройки %s нет. Есть: %s.', $key, implode(', ', array_keys(self::ALL))),
            strlen($value) > 4096 => 'Слишком длинное значение.',
            $kind === self::INT && !ctype_digit($value) => $key . ' — целое число.',
            $kind === self::FLOAT && !is_numeric($value) => $key . ' — число.',
            $kind !== self::SECRET && preg_match('~[\x00-\x1F]~', $value) === 1 => 'Значение в одну строку.',
            $key === 'AGENT_LANGUAGE' && !in_array($value, \Mercurius\I18n\Catalogs::LANGUAGES, true)
                => 'AGENT_LANGUAGE — один из: ' . implode(', ', \Mercurius\I18n\Catalogs::LANGUAGES) . '.',
            default => null,
        };
    }
}
