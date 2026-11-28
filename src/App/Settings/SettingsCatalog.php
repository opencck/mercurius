<?php

declare(strict_types=1);

namespace OpenCCK\App\Settings;

use function Mercurius\I18n\t;

/**
 * The settings the owner changes in the dialog. Everything else stays in .env: the link to Telegram (the first
 * setup goes through it), the database, Compose, the vault master key, the tokens the sandbox and browser read.
 *
 * spec: brief/operations.md#настройки-агента
 */
final class SettingsCatalog {
    public const SECRET = 'secret';
    public const TEXT = 'text';
    public const INT = 'int';
    public const FLOAT = 'float';

    /**
     * What a setting is when neither .env nor the dialog gives it: shown by /settings and the settings tool, so the agent
     * knows the number it works with. Checked against the parsers of the settings by a test.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'CLAUDE_CODE_EXTRA_USAGE' => 'stop',
        'LLM_REASONING_EFFORT' => 'low',
        'LLM_MAX_TOKENS' => '4096',
        'LLM_HISTORY_LIMIT' => '40',
        'LLM_CONTEXT_WINDOW' => '131072',
        'AGENT_MAX_TURNS' => '750',
        'AGENT_TOKEN_BUDGET' => '1000000',
        'AGENT_RUN_TIMEOUT' => '28800',
        'AGENT_TOOL_TIMEOUT' => '120',
        'AGENT_APPROVAL_TIMEOUT' => '1800',
        'AGENT_WORKER_MAX_TURNS' => '375',
        'AGENT_WORKER_TOKEN_BUDGET' => '500000',
        'AGENT_WORKER_TOOLS' => 'read_file,list_files,search_files,write_file,run_command,git,checkpoint,http_request,web_search,web_read,browser,documents,media,skill_view,self_info,servers,ssh_run,sql_query,file_write,tcp_probe',
        'AGENT_MAX_WORKERS_PER_RUN' => '10',
        'AGENT_DAILY_SPEND_CAP' => '0',
        'BROWSER_CONFIRM' => '0',
        'IMPROVE_GATE' => '1',
        'SKILL_MAX_FILES' => '0',
        'AGENT_TOOL_USE_GUIDANCE' => 'auto',
        'AGENT_MONTHLY_SPEND_CAP' => '0',
        'JOB_TOKEN_BUDGET' => '300000',
        'GROUP_FREE_DAILY_TOKENS' => '200000',
        'MEDIA_AUDIO_AUTO_MINUTES' => '10',
        'MEDIA_TEXT_LIMIT' => '20000',
        'MEDIA_INBOX_DAYS' => '30',
        'MEMORY_NUDGE_TURNS' => '10',
        'MEMORY_FILE_LIMIT' => '4000',
        'MEMORY_RULES_NOTICE' => '0',
        'MEMORY_SLEEP_TRANSLATE' => '0',
        'MEMORY_SLEEP_AUTO' => '',
        'MEMORY_SLEEP_USD' => '0.10',
        'CONTEXT_MAX_TOKENS' => '0',
        'CONTEXT_COMPACT_PERCENT' => '0',
    ];

    /**
     * Settings read at each use: a change works at once, no restart.
     *
     * spec: brief/operations.md#настройки-агента
     */
    public const LIVE = ['MEMORY_RULES_NOTICE', 'MEMORY_SLEEP_MODELS', 'MEMORY_SLEEP_TRANSLATE', 'MEMORY_SLEEP_AUTO', 'MEMORY_SLEEP_USD', 'CONTEXT_MAX_TOKENS', 'CONTEXT_COMPACT_PERCENT', 'LLM_CHEAP_MODELS', 'LLM_MAIN_MODELS', 'LLM_STRONG_MODELS', 'LLM_FALLBACK_MODELS', 'WEB_SEARCH_MODELS', 'MEDIA_VISION_MODELS', 'MEDIA_IMAGE_MODELS', 'MEDIA_SPEECH_MODELS', 'MEDIA_VIDEO_MODELS', 'MEDIA_AUDIO_GEN_MODELS', 'MEDIA_VIDEO_GEN_MODELS', 'CLAUDE_CODE_EXTRA_USAGE', 'CLAUDE_CODE_EXTRA_USAGE_RESERVE_PERCENT'];

    /** @var array<string, array{string, string, string}> key => [group id, kind, catalog key of what it is] (texts — settings.group.*, settings.about.*) */
    public const ALL = [
        'OPENROUTER_API_KEY' => ['models', self::SECRET, 'settings.about.openrouter_api_key'],
        'OPENAI_API_KEY' => ['models', self::SECRET, 'settings.about.openai_api_key'],
        'ANTHROPIC_API_KEY' => ['models', self::SECRET, 'settings.about.anthropic_api_key'],
        'AWS_BEARER_TOKEN_BEDROCK' => ['models', self::SECRET, 'settings.about.aws_bearer_token_bedrock'],
        'BEDROCK_REGION' => ['models', self::TEXT, 'settings.about.bedrock_region'],
        // spec: brief/models.md#провайдеры-gemini-и-vertex
        'GEMINI_API_KEY' => ['models', self::SECRET, 'settings.about.gemini_api_key'],
        'VERTEX_CREDENTIALS' => ['models', self::SECRET, 'settings.about.vertex_credentials'],
        'VERTEX_PROJECT_ID' => ['models', self::TEXT, 'settings.about.vertex_project_id'],
        'VERTEX_REGION' => ['models', self::TEXT, 'settings.about.vertex_region'],
        // spec: brief/models.md#kimi-ключ-api-и-подписка
        'MOONSHOT_API_KEY' => ['models', self::SECRET, 'settings.about.moonshot_api_key'],
        'MOONSHOT_BASE_URL' => ['models', self::TEXT, 'settings.about.moonshot_base_url'],
        'KIMI_CODE_API_KEY' => ['models', self::SECRET, 'settings.about.kimi_code_api_key'],
        'KIMI_CODE_BASE_URL' => ['models', self::TEXT, 'settings.about.kimi_code_base_url'],
        'VLESS_URI' => ['models', self::SECRET, 'settings.about.vless_uri'],
        // spec: brief/models.md#вход-в-подписку-claude — the token wins over the sign-in; it reaches the module with each request
        'CLAUDE_CODE_OAUTH_TOKEN' => ['models', self::SECRET, 'settings.about.claude_code_oauth_token'],
        // spec: brief/models.md#лимиты-подписки-и-переход-на-модель-категории — the owner's word on paid use, read at each call
        'CLAUDE_CODE_EXTRA_USAGE' => ['models', self::TEXT, 'settings.about.claude_code_extra_usage'],
        'CLAUDE_CODE_EXTRA_USAGE_RESERVE_PERCENT' => ['models', self::TEXT, 'settings.about.claude_code_extra_usage_reserve_percent'],
        'LLM_MODEL' => ['models', self::TEXT, 'settings.about.llm_model'],
        'LLM_CHEAP_MODEL' => ['models', self::TEXT, 'settings.about.llm_cheap_model'],
        // spec: brief/models.md#списки-моделей-по-категориям — the lists the agent chooses a model from
        'LLM_CHEAP_MODELS' => ['models', self::TEXT, 'settings.about.llm_cheap_models'],
        'LLM_MAIN_MODELS' => ['models', self::TEXT, 'settings.about.llm_main_models'],
        'LLM_STRONG_MODELS' => ['models', self::TEXT, 'settings.about.llm_strong_models'],
        // spec: brief/models.md#запасные-модели — the models of an empty text category and of a failed run
        'LLM_FALLBACK_MODELS' => ['models', self::TEXT, 'settings.about.llm_fallback_models'],
        'LLM_REASONING_EFFORT' => ['models', self::TEXT, 'settings.about.llm_reasoning_effort'],
        'LLM_MAX_TOKENS' => ['models', self::INT, 'settings.about.llm_max_tokens'],
        'LLM_HISTORY_LIMIT' => ['models', self::INT, 'settings.about.llm_history_limit'],
        'LLM_CONTEXT_WINDOW' => ['models', self::INT, 'settings.about.llm_context_window'],
        'AGENT_MAX_TURNS' => ['limits', self::INT, 'settings.about.agent_max_turns'],
        'AGENT_TOKEN_BUDGET' => ['limits', self::INT, 'settings.about.agent_token_budget'],
        'AGENT_RUN_TIMEOUT' => ['limits', self::INT, 'settings.about.agent_run_timeout'],
        'AGENT_TOOL_TIMEOUT' => ['limits', self::INT, 'settings.about.agent_tool_timeout'],
        'AGENT_APPROVAL_TIMEOUT' => ['limits', self::INT, 'settings.about.agent_approval_timeout'],
        'AGENT_WORKER_MAX_TURNS' => ['limits', self::INT, 'settings.about.agent_worker_max_turns'],
        'AGENT_WORKER_TOKEN_BUDGET' => ['limits', self::INT, 'settings.about.agent_worker_token_budget'],
        'AGENT_WORKER_TOOLS' => ['limits', self::TEXT, 'settings.about.agent_worker_tools'],
        'AGENT_MAX_WORKERS_PER_RUN' => ['limits', self::INT, 'settings.about.agent_max_workers_per_run'],
        'AGENT_DAILY_SPEND_CAP' => ['limits', self::FLOAT, 'settings.about.agent_daily_spend_cap'],
        'AGENT_MONTHLY_SPEND_CAP' => ['limits', self::FLOAT, 'settings.about.agent_monthly_spend_cap'],
        'JOB_TOKEN_BUDGET' => ['limits', self::INT, 'settings.about.job_token_budget'],
        'GROUP_FREE_DAILY_TOKENS' => ['limits', self::INT, 'settings.about.group_free_daily_tokens'],
        'SEARCH_PROVIDERS' => ['search', self::TEXT, 'settings.about.search_providers'],
        'SEARXNG_URL' => ['search', self::TEXT, 'settings.about.searxng_url'],
        'BRAVE_SEARCH_API_KEY' => ['search', self::SECRET, 'settings.about.brave_search_api_key'],
        'TAVILY_API_KEY' => ['search', self::SECRET, 'settings.about.tavily_api_key'],
        'WEB_SEARCH_MODELS' => ['search', self::TEXT, 'settings.about.web_search_models'],
        // spec: brief/internet.md#модели-вложений — media models are unset by default
        'MEDIA_VISION_MODELS' => ['media', self::TEXT, 'settings.about.media_vision_models'],
        'MEDIA_AUDIO_GEN_MODELS' => ['media', self::TEXT, 'settings.about.media_audio_gen_models'],
        'MEDIA_VIDEO_GEN_MODELS' => ['media', self::TEXT, 'settings.about.media_video_gen_models'],
        'MEDIA_IMAGE_MODELS' => ['media', self::TEXT, 'settings.about.media_image_models'],
        'MEDIA_VIDEO_MODELS' => ['media', self::TEXT, 'settings.about.media_video_models'],
        'MEDIA_SPEECH_MODELS' => ['media', self::TEXT, 'settings.about.media_speech_models'],
        'MEDIA_VISION_RULES' => ['media', self::TEXT, 'settings.about.media_vision_rules'],
        'MEDIA_IMAGE_RULES' => ['media', self::TEXT, 'settings.about.media_image_rules'],
        'MEDIA_VIDEO_RULES' => ['media', self::TEXT, 'settings.about.media_video_rules'],
        'MEDIA_SPEECH_RULES' => ['media', self::TEXT, 'settings.about.media_speech_rules'],
        'MEDIA_ENDPOINTS' => ['media', self::TEXT, 'settings.about.media_endpoints'],
        'MEDIA_AUTO' => ['media', self::TEXT, 'settings.about.media_auto'],
        'MEDIA_AUDIO_AUTO_MINUTES' => ['media', self::INT, 'settings.about.media_audio_auto_minutes'],
        'MEDIA_TEXT_LIMIT' => ['media', self::INT, 'settings.about.media_text_limit'],
        'MEDIA_INBOX_DAYS' => ['media', self::INT, 'settings.about.media_inbox_days'],
        'AGENT_LANGUAGE' => ['agent', self::TEXT, 'settings.about.agent_language'],
        'OWNER_TIMEZONE' => ['time', self::TEXT, 'settings.about.owner_timezone'],
        'QUIET_HOURS' => ['time', self::TEXT, 'settings.about.quiet_hours'],
        'INITIATIVE_SCHEDULE' => ['time', self::TEXT, 'settings.about.initiative_schedule'],
        'INITIATIVE_PER_DAY' => ['time', self::INT, 'settings.about.initiative_per_day'],
        'SELF_REVIEW_SCHEDULE' => ['time', self::TEXT, 'settings.about.self_review_schedule'],
        'EVAL_JUDGE_MODEL' => ['models', self::TEXT, 'settings.about.eval_judge_model'],
        'EVAL_RUNS' => ['models', self::INT, 'settings.about.eval_runs'],
        'MEMORY_CHECK_HOUR' => ['memory', self::INT, 'settings.about.memory_check_hour'],
        'MEMORY_HRR_WEIGHT' => ['memory', self::FLOAT, 'settings.about.memory_hrr_weight'],
        'MEMORY_NUDGE_TURNS' => ['memory', self::INT, 'settings.about.memory_nudge_turns'],
        'MEMORY_FILE_LIMIT' => ['memory', self::INT, 'settings.about.memory_file_limit'],
        'MEMORY_RULES_NOTICE' => ['memory', self::INT, 'settings.about.memory_rules_notice'],
        'MEMORY_SLEEP_MODELS' => ['models', self::TEXT, 'settings.about.memory_sleep_models'],
        'MEMORY_SLEEP_TRANSLATE' => ['memory', self::INT, 'settings.about.memory_sleep_translate'],
        'MEMORY_SLEEP_AUTO' => ['memory', self::TEXT, 'settings.about.memory_sleep_auto'],
        'MEMORY_SLEEP_USD' => ['memory', self::FLOAT, 'settings.about.memory_sleep_usd'],
        'CONTEXT_MAX_TOKENS' => ['agent', self::INT, 'settings.about.context_max_tokens'],
        'CONTEXT_COMPACT_PERCENT' => ['agent', self::INT, 'settings.about.context_compact_percent'],
        'TRACE_RETENTION_DAYS' => ['traces', self::INT, 'settings.about.trace_retention_days'],
        'SSH_CONFIRM_GROUPS' => ['servers', self::TEXT, 'settings.about.ssh_confirm_groups'],
        'BROWSER_CONFIRM' => ['agent', self::INT, 'settings.about.browser_confirm'],
        'IMPROVE_GATE' => ['agent', self::INT, 'settings.about.improve_gate'],
        'SKILL_MAX_FILES' => ['agent', self::INT, 'settings.about.skill_max_files'],
        'AGENT_TOOL_USE_GUIDANCE' => ['agent', self::TEXT, 'settings.about.agent_tool_use_guidance'],
        'SSH_NEVER_OFF' => ['servers', self::TEXT, 'settings.about.ssh_never_off'],
        'METRICS_TOKEN' => ['traces', self::SECRET, 'settings.about.metrics_token'],
        'A2A_NAME' => ['network', self::TEXT, 'settings.about.a2a_name'],
        'A2A_DESCRIPTION' => ['network', self::TEXT, 'settings.about.a2a_description'],
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
            $kind === null => sprintf(t('settings.settings_catalog.nastroyki_net_est'), $key, implode(', ', array_keys(self::ALL))),
            strlen($value) > 4096 => t('settings.settings_catalog.slishkom_dlinnoe_znachenie'),
            $kind === self::INT && !ctype_digit($value) => $key . t('settings.settings_catalog.tseloe_chislo'),
            $kind === self::FLOAT && !is_numeric($value) => $key . t('settings.settings_catalog.chislo'),
            $kind !== self::SECRET && preg_match('~[\x00-\x1F]~', $value) === 1 => t('settings.settings_catalog.znachenie_v_odnu_stroku'),
            $key === 'CLAUDE_CODE_EXTRA_USAGE' && !in_array($value, ['stop', 'notify', 'allow'], true) => t('settings.settings_catalog.claude_extra_usage'),
            $key === 'CLAUDE_CODE_EXTRA_USAGE_RESERVE_PERCENT' && $value !== '' && (!is_numeric($value) || (float) $value <= 0 || (float) $value > 100) => t('settings.settings_catalog.claude_reserve'),
            $key === 'AGENT_LANGUAGE' && !in_array($value, \Mercurius\I18n\Catalogs::LANGUAGES, true)
                => t('settings.settings_catalog.agent_language_odin_iz') . implode(', ', \Mercurius\I18n\Catalogs::LANGUAGES) . '.',
            default => null,
        };
    }
}
