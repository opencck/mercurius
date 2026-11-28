<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Web;

use Mercurius\Llm\ToolDefinition;
use Mercurius\Messenger\Channel;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\GrantKeyed;
use OpenCCK\App\Agent\Tool\Guard\AddressGuard;
use OpenCCK\App\Agent\Tool\Guard\SecretMasker;
use OpenCCK\App\Agent\Tool\NeedsService;
use OpenCCK\App\Agent\Tool\PreparesCall;
use OpenCCK\App\Agent\Tool\ReadsOutside;
use OpenCCK\App\Agent\Tool\Tool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\App\Service\OwnerDirectory;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Infrastructure\Browser\BrowserClient;
use RuntimeException;

use function Mercurius\I18n\t;

/**
 * A real browser for pages that need a login, scripts or actions. Opening and reading go at once; typing into a field
 * is a write; clicking and submitting can publish, send or buy, so they wait for the owner. A screenshot goes to the
 * owner's chat. Every page is untrusted data. An internal address waits for the owner, and the browser lets in that
 * host alone (the key is shared with http_request and web_read).
 *
 * spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — navigation, reading, clicks, input, screenshots; acting for the owner only with a
 *       card; profiles keep logins
 */
final class BrowserTool implements Tool, GrantKeyed, ReadsOutside, PreparesCall, NeedsService, \OpenCCK\App\Agent\Tool\PreparesInConversation, \OpenCCK\App\Agent\Tool\DecidedByParticipant {
    use \OpenCCK\App\Agent\Tool\WiderSecrets;

    /** set by the code when the call works in the profiles of another participant (a project of theirs); a model cannot set it */
    private const HOLDER = 'holder';
    public const NAME = 'browser';
    public const ACTIONS = ['open', 'read', 'click', 'type', 'select', 'scroll', 'back', 'screenshot', 'close', 'script', 'reset'];
    /** what a reset takes: the caches (the sign-ins stay) or the whole profile */
    private const RESET = ['cache', 'profile'];
    public const MODULE = 'browser';
    /** A script of the agent: browser-scripts/read/… reads a frozen copy of the page, browser-scripts/act/… acts on it. */
    public const SCRIPT_PATH = '~^browser-scripts/(read|act)/(?:[A-Za-z0-9_][A-Za-z0-9._-]*/)*[A-Za-z0-9_][A-Za-z0-9._-]*\.js$~';
    public const SCRIPT_MAX = 200_000;
    private const SITE = '~^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?)*$~';

    public function __construct(
        private readonly BrowserClient $browser,
        private readonly Channel $channel,
        private readonly OwnerDirectory|string $ownerChatId,
        /** tells internal addresses apart; null — the browser service alone refuses them */
        private readonly ?AddressGuard $guard = null,
        /** the chat a screenshot goes to is the one it was asked in; null — the owner's chat */
        private readonly ?\OpenCCK\Domain\Repository\ConversationRepository $conversations = null,
        /** a screenshot is kept in the participant's sandbox too, to be sent on; null — not kept */
        private readonly ?\OpenCCK\App\Agent\Sandbox\Sandbox $sandbox = null,
        /** the vision models: the agent looks at a screenshot itself; null — it only sends it */
        private readonly ?\OpenCCK\Infrastructure\Media\MediaModels $models = null,
        /** the participant's choice of a vision model over the owner's list */
        private readonly ?\OpenCCK\App\Agent\Media\MediaPrefs $prefs = null,
        /** @var (\Closure(int): ?int)|null the active project of a conversation; null — profiles are the participant's only */
        private readonly ?\Closure $projectOf = null,
        /** BROWSER_CONFIRM: clicks and sending forms wait for the owner's card; off by default */
        private readonly bool $confirm = false,
        /**
         * @var (\Closure(int, \OpenCCK\Domain\Entity\Member): ?int)|null whose set of profiles a project uses for this
         *      participant: the owner of the project for those who work in it; null — the participant's own
         */
        private readonly ?\Closure $holderOf = null,
        private readonly ?\OpenCCK\Domain\Repository\MemberRepository $principals = null,
        /** the vault a password is typed from by its name; null — only a text is typed */
        private readonly ?\OpenCCK\App\Vault\Vault $vault = null,
    ) {}

    /**
     * Marks a call that works in the profiles of someone else — the owner of the project of this chat. There a click and
     * a sent form are that participant's to allow, whatever the confirmations of the installation are.
     *
     * spec: brief/participants.md#ресурсы-проекта — what is done under someone's login is confirmed by them
     */
    public function prepareIn(array $args, \OpenCCK\Domain\Entity\Member $principal, int $conversationId, \Amp\Cancellation $cancellation): array {
        unset($args[self::HOLDER]);
        $project = $this->projectOf === null ? null : ($this->projectOf)($conversationId);
        $holder = $project === null || $this->holderOf === null ? null : ($this->holderOf)($project, $principal);
        if ($holder !== null && $holder !== $principal->id) {
            $args[self::HOLDER] = $holder;
        }
        return $args;
    }

    public function decider(array $args): ?\OpenCCK\Domain\Entity\Member {
        return isset($args[self::HOLDER]) && is_int($args[self::HOLDER]) ? $this->principals?->find($args[self::HOLDER]) : null;
    }

    /**
     * Where a profile lives: the participant's own set (`p<id>/<name>`), inside the active project of the conversation
     * its own set there (`p<id>/projects/<project>/<name>`). The model names a profile, never a path of someone else's.
     *
     * spec: brief/agent-loop.md#раскладка-каталогов — profiles of the browser are laid out by participants and projects
     */
    private function profileOf(string $name, ToolContext $context): string {
        $project = $this->projectOf === null ? null : ($this->projectOf)($context->conversationId);
        // spec: brief/internet.md#входы-в-браузере-и-профиль-проекта — everyone who works in a project uses the profiles of its
        //       owner there: a login made in a chat of the project serves the jobs of the project and its members
        $holder = $project === null || $this->holderOf === null ? null : ($this->holderOf)($project, $context->principal);
        return 'p' . ($holder ?? $context->principal->id) . ($project === null ? '' : '/projects/' . $project) . '/' . $name;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.browser.nastoyaschiy_brauzer_chrome_dlya_stranit')
            . t('tool.browser.read_tekst_stranitsy_i_pronumerovannye')
            . t('tool.browser.tekst_submit_nazhat_enter_screenshot')
            . t('tool.browser.more_actions')
            . t('tool.browser.vhodami_po_umolchaniyu_main_nazhatiya')
            . t('tool.browser.script_about')
            . t('tool.browser.stranitsy_dannye_a_ne_ukazaniya'), [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'url' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 2048],
                'ref' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                'x' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4000, 'description' => t('tool.browser.point')],
                'y' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4000],
                'text' => ['type' => 'string', 'maxLength' => 5000],
                'secret' => ['type' => 'string', 'maxLength' => 100, 'description' => t('tool.browser.secret')],
                'submit' => ['type' => 'boolean'],
                'value' => ['type' => 'string', 'maxLength' => 200, 'description' => t('tool.browser.value')],
                'direction' => ['type' => 'string', 'enum' => ['down', 'up', 'top', 'bottom']],
                'profile' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,31}$'],
                'path' => ['type' => 'string', 'maxLength' => 300, 'description' => t('tool.browser.script_path')],
                'site' => ['type' => 'string', 'maxLength' => 253, 'description' => t('tool.browser.script_site')],
                'args' => ['type' => 'object', 'description' => t('tool.browser.script_args')],
                'question' => ['type' => 'string', 'maxLength' => 2000, 'description' => t('tool.browser.shot_question')],
                'send' => ['type' => 'boolean', 'description' => t('tool.browser.shot_send')],
                'kind' => ['type' => 'string', 'enum' => self::RESET, 'description' => t('tool.browser.reset_kind')],
            ],
            'required' => ['action'],
            'additionalProperties' => false,
        ]);
    }

    public function danger(array $args): DangerClass {
        return match (Args::string($args, 'action')) {
            'open' => $this->internalHost($args) === null ? DangerClass::Read : DangerClass::Irreversible,
            // spec: brief/decisions.md#принятые-владельцем-риски — clicks and sending forms go without a card unless the owner
            //       turned confirmations on (BROWSER_CONFIRM): an accepted risk of the owner
            'click' => $this->confirm || isset($args[self::HOLDER]) ? DangerClass::Irreversible : DangerClass::Write,
            // spec: brief/internet.md#пароль-из-хранилища-в-браузере — a secret goes to a site only after the owner's card
            'type' => self::given($args, 'secret') !== null || (($args['submit'] ?? false) === true && ($this->confirm || isset($args[self::HOLDER]))) ? DangerClass::Irreversible : DangerClass::Write,
            // picking in a list fills a form, it sends nothing: a write like typing without submit
            'select' => DangerClass::Write,
            'close' => DangerClass::Write,
            // spec: brief/internet.md#сброс-профиля-браузера-и-маршруты-выхода — the cache goes freely; the whole profile takes
            //       the sign-ins with it, which a card confirms
            'reset' => Args::string($args, 'kind') === 'profile' ? DangerClass::Irreversible : DangerClass::Write,
            // spec: brief/internet.md#скрипты-браузера — reading a copy is a read, acting waits for a card
            'script' => self::scriptMode(Args::string($args, 'path') ?? '') === 'act' ? DangerClass::Irreversible : DangerClass::Read,
            default => DangerClass::Read,
        };
    }

    public function grantKey(array $args): ?string {
        if (Args::string($args, 'action') === 'script') {
            // granted for good: this text of the script on this site; a changed script asks again
            $sha = Args::string($args, 'sha256');
            $site = Args::string($args, 'site');
            return $sha === null || $site === null || self::scriptMode(Args::string($args, 'path') ?? '') !== 'act'
                ? null : 'browser-script:' . $site . ':' . substr($sha, 0, 16);
        }
        if (Args::string($args, 'action') === 'type' && ($secret = self::given($args, 'secret')) !== null) {
            // granted for good: this secret on this site, as http_request binds a secret to a host
            $site = strtolower(Args::string($args, 'site') ?? '');
            return preg_match(self::SITE, $site) === 1 ? sprintf('browser-secret:%s %s', $secret, $site) : null;
        }
        if (Args::string($args, 'action') !== 'open') {
            return null;
        }
        $internal = $this->internalHost($args);
        return $internal === null ? null : 'addr:' . $internal;
    }

    public function describe(array $args): string {
        $action = Args::string($args, 'action') ?? '';
        if ($action === 'script') {
            return sprintf(t('tool.browser.script_describe'), Args::string($args, 'path') ?? '', Args::string($args, 'site') ?? '');
        }
        if ($action === 'type' && ($secret = self::given($args, 'secret')) !== null) {
            return sprintf(t('tool.browser.secret_describe'), $secret, Args::string($args, 'site') ?? '');
        }
        $internal = $action === 'open' ? $this->internalHost($args) : null;
        return 'browser ' . $action . ' ' . mb_substr(Args::string($args, 'url') ?? (string) (Args::int($args, 'ref') ?? ''), 0, 300)
            . ($internal === null ? '' : sprintf(t('tool.address_internal'), $internal));
    }

    public function readsOutside(array $args): bool {
        return in_array(Args::string($args, 'action'), ['open', 'read', 'click', 'type', 'select', 'scroll', 'back', 'script'], true);
    }

    /**
     * A script call gets the text of its file and the hash: the card shows that text, the grant is keyed by it, and
     * exactly that text runs — the file may change after the card, the call does not.
     *
     * spec: brief/internet.md#скрипты-браузера
     */
    public function prepare(array $args, \OpenCCK\Domain\Entity\Member $principal, \OpenCCK\Domain\Agent\ParticipantPolicy $policy, \Amp\Cancellation $cancellation): array|string {
        if (Args::string($args, 'action') !== 'script') {
            return $args;
        }
        $path = Args::string($args, 'path') ?? '';
        if (self::scriptMode($path) === null) {
            return t('tool.browser.script_bad_path');
        }
        $site = strtolower(Args::string($args, 'site') ?? '');
        if (strlen($site) > 253 || preg_match(self::SITE, $site) !== 1) {
            return t('tool.browser.script_bad_site');
        }
        $limits = $policy->sandbox;
        if ($this->sandbox === null || $limits === null) {
            return t('tool.browser.script_no_sandbox');
        }
        try {
            $source = $this->sandbox->fetch($principal->id, $limits, $path, self::SCRIPT_MAX, $cancellation);
        } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure $e) {
            return $e->getMessage();
        }
        if (trim($source) === '' || !mb_check_encoding($source, 'UTF-8')) {
            return t('tool.browser.script_not_text');
        }
        return ['site' => $site, 'source' => $source, 'sha256' => hash('sha256', $source)] + $args;
    }

    /** read, act, or null — not a script path. */
    private static function scriptMode(string $path): ?string {
        return preg_match(self::SCRIPT_PATH, $path, $match) === 1 && !str_contains($path, '..') ? $match[1] : null;
    }

    /** @param array<string, mixed> $args */
    private function internalHost(array $args): ?string {
        $host = parse_url(Args::string($args, 'url') ?? '', PHP_URL_HOST);
        if ($this->guard === null || !is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(trim($host, '[]'));
        return $this->guard->internal($host) ? $host : null;
    }

    public function service(): string {
        return self::MODULE;
    }

    public function untrusted(): bool {
        return true;
    }

    public function timeout(): int {
        return 120;
    }

    /**
     * A string argument as it was written; null — it was not given. An empty text is a text: it clears a field.
     *
     * @param array<string, mixed> $args
     */
    private static function given(array $args, string $name): ?string {
        return isset($args[$name]) && is_string($args[$name]) ? $args[$name] : null;
    }

    /**
     * The value of the secret a call types, or null when it types a text. The secret is typed only on the site the
     * owner's card named: the page open now is asked first, and another site gets nothing — a page that talked the
     * model into typing a password elsewhere is refused here.
     *
     * spec: brief/internet.md#пароль-из-хранилища-в-браузере
     *
     * @param array<string, mixed> $args
     */
    private function secretFor(array $args, string $profile, ToolContext $context): ?string {
        $name = self::given($args, 'secret');
        if ($name === null) {
            return null;
        }
        $site = strtolower(Args::string($args, 'site') ?? '');
        if ($this->vault === null || preg_match(self::SITE, $site) !== 1) {
            throw new RuntimeException(t('tool.browser.secret_no_site'));
        }
        $page = $this->browser->call('read', ['profile' => $profile], $context->cancellation);
        $host = strtolower((string) parse_url(self::str($page, 'url'), PHP_URL_HOST));
        if ($host !== $site && !str_ends_with($host, '.' . $site)) {
            throw new RuntimeException(sprintf(t('tool.browser.secret_other_site'), $host === '' ? '—' : $host, $site));
        }
        return $this->vault->reveal($name, $context->principal) ?? $this->widerSecret($name, $context) ?? throw new RuntimeException(sprintf(t('tool.browser.secret_missing'), $name));
    }

    public function execute(array $args, ToolContext $context): ToolResult {
        $action = Args::string($args, 'action') ?? '';
        $profile = $this->profileOf(Args::string($args, 'profile') ?? 'main', $context);
        try {
            $body = match ($action) {
                'open' => ['profile' => $profile, 'url' => Args::string($args, 'url') ?? '']
                    // the owner allowed this internal host: the browser lets it in for this page
                    + ($context->approved && ($internal = $this->internalHost($args)) !== null ? ['allow' => [$internal]] : []),
                // spec: brief/internet.md#проверка-я-не-робот-и-капча — a point of the screenshot, where a person said to press
                'click' => Args::int($args, 'x') !== null && Args::int($args, 'y') !== null
                    ? ['profile' => $profile, 'x' => Args::int($args, 'x'), 'y' => Args::int($args, 'y')]
                    : ['profile' => $profile, 'ref' => Args::int($args, 'ref') ?? 0],
                // a model that writes the text as `value` (the parameter of select) means the text: typing an empty string
                // instead left the page as it was, and the unchanged page looked like a tool that does not work
                'type' => ['profile' => $profile, 'ref' => Args::int($args, 'ref') ?? 0, 'text' => $this->secretFor($args, $profile, $context) ?? self::given($args, 'text') ?? self::given($args, 'value') ?? throw new RuntimeException(t('tool.browser.type_no_text')), 'submit' => ($args['submit'] ?? false) === true],
                'select' => ['profile' => $profile, 'ref' => Args::int($args, 'ref') ?? 0, 'value' => self::given($args, 'value') ?? throw new RuntimeException(t('tool.browser.select_no_value'))],
                'scroll' => ['profile' => $profile, 'direction' => Args::string($args, 'direction') ?? 'down'],
                'script' => [
                    'profile' => $profile,
                    // only a prepared call has the text: the one the owner saw on the card
                    'source' => Args::string($args, 'source') ?? throw new RuntimeException(t('tool.browser.script_bad_path')),
                    'args' => isset($args['args']) && is_array($args['args']) && $args['args'] !== [] ? $args['args'] : new \stdClass(),
                    'mode' => self::scriptMode(Args::string($args, 'path') ?? '') ?? 'read',
                    'host' => Args::string($args, 'site') ?? '',
                ],
                'reset' => in_array($kind = Args::string($args, 'kind') ?? 'cache', self::RESET, true)
                    ? ['profile' => $profile, 'kind' => $kind]
                    : throw new RuntimeException(t('tool.browser.reset_kind')),
                default => ['profile' => $profile],
            };
            $data = $this->browser->call(match ($action) {
                'open' => 'navigate',
                'script' => 'evaluate',
                default => $action,
            }, $body, $context->cancellation);
        } catch (RuntimeException $e) {
            return ToolResult::error(t('tool.browser.brauzer') . SecretMasker::mask($e->getMessage()));
        }
        if ($action === 'screenshot') {
            $png = base64_decode(self::str($data, 'png'), true);
            $url = self::str($data, 'url');
            if ($png === false || $png === '') {
                return ToolResult::error(t('tool.browser.snimok_ne_poluchen'));
            }
            return $this->screenshot($png, $url, $context, Args::string($args, 'question'), ($args['send'] ?? true) !== false);
        }
        if ($action === 'script') {
            return ToolResult::ok(sprintf(t('tool.browser.script_result'), Args::string($args, 'path') ?? '', self::str($data, 'url'), SecretMasker::mask(self::str($data, 'result')))
                . (($data['truncated'] ?? false) === true ? t('tool.browser.script_truncated') : ''));
        }
        if ($action === 'close') {
            return ToolResult::ok(t('tool.browser.profil_zakryt'));
        }
        if ($action === 'reset') {
            return ToolResult::ok(t(self::str($data, 'kind') === 'profile' ? 'tool.browser.reset_profile' : 'tool.browser.reset_cache'));
        }
        return ToolResult::ok(self::page($data));
    }

    /**
     * The screenshot goes to the chat (and topic) it was asked in and is kept in the participant's sandbox, so the
     * agent can send it on to anyone (send_message with files).
     *
     * spec: brief/channels.md#личные-сообщения-участникам — not only text: files to people and agents
     */
    private function screenshot(string $png, string $url, ToolContext $context, ?string $question, bool $send): ToolResult {
        $seen = $question === null || trim($question) === '' ? null : $this->look($png, trim($question), $context);
        if ($seen !== null && !$send) {
            return $seen->isError ? $seen : ToolResult::ok(sprintf(t('tool.browser.shot_seen'), $url) . "\n" . $seen->content);
        }
        $result = $this->deliver($png, $url, $context);
        return $seen === null || $result->isError ? $result : ToolResult::ok($result->content . "\n" . $seen->content);
    }

    /**
     * The agent looks at the screenshot itself with a vision model: the participant's choice or the owner's list, as for
     * a photo. What the model says about the page is data from outside.
     *
     * spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — the agent sees what is on the screen
     * ref: references/hermes-agent/tools/browser_use_cli.py@e96221fa:L309-L328 (_native_screenshot_result) — hermes
     *      attaches the screenshot to the tool result when the main model sees images; here a vision model of the
     *      owner's media settings looks and answers in text, so any main model gets it and the history stays small
     */
    private function look(string $png, string $question, ToolContext $context): ToolResult {
        if ($this->models === null || $this->prefs === null) {
            return ToolResult::error(t('tool.browser.shot_no_vision'));
        }
        $refs = \OpenCCK\App\Agent\Media\MediaPrefs::candidates($this->prefs->settings, 'vision', $this->prefs->of($context->principal->id, 'image')->model);
        if ($refs === []) {
            return ToolResult::error(t('tool.browser.shot_no_vision'));
        }
        $models = $this->models;
        $call = new \OpenCCK\Infrastructure\Media\MediaCall($context->conversationId, $context->runId, $context->cancellation);
        $answer = $models->first($refs, static fn(string $ref): \OpenCCK\Infrastructure\Media\MediaAnswer => $models->look($ref, [['bytes' => $png, 'mime' => 'image/png']], $question, $call));
        if ($answer->text === null) {
            return ToolResult::error(sprintf(t('media.intake.failed_models'), $answer->error ?? ''));
        }
        return ToolResult::ok(sprintf(t('tool.media.frames'), $answer->model, SecretMasker::mask($answer->text)));
    }

    private function deliver(string $png, string $url, ToolContext $context): ToolResult {
        $meta = $this->conversations?->meta($context->conversationId);
        $chat = $meta === null ? OwnerDirectory::chat($this->ownerChatId) : $meta->chatId;
        $thread = $meta === null || $meta->threadId === '' ? null : $meta->threadId;
        $kept = null;
        $limits = $context->policy?->sandbox;
        if ($this->sandbox !== null && $limits !== null) {
            $path = 'screenshots/shot-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.png';
            try {
                $this->sandbox->put(\OpenCCK\App\Agent\Tool\Sandbox\SandboxTool::holderOf($context), $limits, $path, $png, $context->cancellation);
                $kept = $path;
            } catch (\OpenCCK\App\Agent\Sandbox\SandboxFailure) {
                // the chat still gets it
            }
        }
        $sent = $this->channel->sendFile($chat, 'screenshot.png', $png, mb_substr(t('tool.browser.snimok') . $url, 0, 900), $thread);
        if (!$sent->success) {
            return ToolResult::error(t('tool.browser.snimok_ne_otpravlen') . ($kept === null ? '' : sprintf(t('tool.browser.kept'), $kept)));
        }
        return ToolResult::ok(sprintf(t('tool.browser.shot_sent'), $url) . ($kept === null ? '' : sprintf(t('tool.browser.kept'), $kept)));
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
        // spec: brief/internet.md#как-сделано-поиск-чтение-и-браузер — a dialog of the page was dismissed at once; the agent learns what it said
        $dialogs = [];
        /** @var mixed $line */
        foreach (isset($data['dialogs']) && is_array($data['dialogs']) ? $data['dialogs'] : [] as $line) {
            if (is_string($line)) {
                $dialogs[] = $line;
            }
        }
        return ($dialogs === [] ? '' : t('tool.browser.dialogs') . SecretMasker::mask(implode("\n", $dialogs)) . "\n\n") . sprintf(
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
