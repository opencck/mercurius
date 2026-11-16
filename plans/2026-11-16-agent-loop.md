# Агентский цикл (часть 1 фазы 3) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** владелец пишет боту задачу — агент решает её за несколько вызовов модели и инструментов в границах лимитов
участника, сохраняя каждый шаг в БД и продолжая работу после перезапуска контейнера.

**Architecture:** `RunScheduler` держит не больше одного активного запуска на диалог и исполняет его в своём волокне;
`AgentLoop` прогоняет итерации из фаз над `RunState`; инструменты — записи `ToolRegistry`, их вызовы проверяет
`ToolCallValidator`, исполняет `ToolExecutor` отрезками из `BatchPlanner`. Состояние — PostgreSQL (`runs`,
`tool_calls`, `run_inbox`, расширенные `messages`, `principal_policies`). `ChatService` перестаёт звать модель сам и
передаёт сообщения планировщику.

**Tech Stack:** PHP 8.2, AMPHP v3 / Revolt, amphp/postgres, opencck/server, пакеты `mercurius-llm` и
`mercurius-messenger`, PHPUnit 9 + amphp/phpunit-util, PHPStan max, Psalm level 1.

**Spec:** [BRIEF.md → Агентский цикл и харнесс](../BRIEF.md#агентский-цикл-и-харнесс) — разделы «Запуск и цикл»,
«Права участника», «Лимиты», «Устойчивость», «Инструменты» (без песочницы), «Контекст» (только системный промпт).
Песочница, подтверждения, сжатие и роли моделей — следующие части, у каждой свой план.

## Global Constraints

- Скиллы перед кодом: `skill-opencck` и `skill-amphp`; процесс — `superpowers:test-driven-development`.
- Async-first: никаких блокирующих вызовов, только `amphp/*` и Revolt.
- Перед каждой правкой — найти соответствующее место в `references/hermes-agent` (коммит сабмодуля `e96221f`),
  в коде ставить `ref: references/hermes-agent/<путь>@e96221f:L<a>-L<b> (<функция>) — что взято / от чего отступили`;
  номера строк сверять по файлу. Требования — `spec: BRIEF.md#<якорь> — …`. На `ROADMAP.md` и `plans/` код не ссылается.
- Сообщения и тексты для пользователя — по-русски; идентификаторы, комментарии в коде, сообщения коммитов — по-английски.
- Лимиты по умолчанию: `AGENT_MAX_TURNS=30`, `AGENT_TOKEN_BUDGET=400000`, `AGENT_RUN_TIMEOUT=900` (с),
  `AGENT_TOOL_TIMEOUT=120` (с, потолок 600).
- Пороги ограничителя зацикливания — как в hermes (`agent/tool_guardrails.py@e96221f:L37-L46`, `L125-L130`):
  предупреждение на 3-м одинаковом вызове, остановка на 5-м; циклы с периодом до 4 — те же пороги по кругам.
- Параллельно — не больше 8 вызовов чтения; результат одного вызова в этой части обрезается до 50 000 символов
  с припиской (выгрузка в файл — в части 2).
- Маркер недоверенных данных: `<untrusted-data source="…">` … `</untrusted-data>`, внутри содержимого вхождения
  `<untrusted-data` и `</untrusted-data>` обезвреживаются.
- Ни одно значение политики участника не больше значения владельца; по умолчанию у `admin` и `user` инструментов нет.
- Секреты не попадают в логи, промпт, результаты инструментов и тексты пользователю.
- Перед каждым коммитом — `composer check` зелёный; сообщение коммита — по `CLAUDE.md` (семантический формат,
  английский, через `watermarks-remover:clean-user-facing-text`, `git commit -F`, без `Co-Authored-By`).

## Review Focus

1. **Сообщение владельца во время запуска** — дописка попадает модели после ближайшего результата инструмента и не
   разрывает пару «вызов — результат»; два сообщения подряд в пределах 0,35 с склеиваются. Тест — задача 9.
2. **Перезапуск посреди пишущего инструмента** — вызов не повторяется, модель получает «прервано перезапуском…»;
   вызов чтения повторяется. Тест — задача 9.
3. **Модель вернула обрезанный JSON аргументов (`FinishReason::Length`)** — инструмент не исполняется, запрос
   повторяется один раз, затем остановка `truncated`. Тест — задача 8.
4. **Бюджет кончается посреди запуска** — резерв не проходит до вызова модели, модель получает один итоговый вызов
   без инструментов, владелец получает отчёт с израсходованным. Тест — задача 8.
5. **`/stop` во время долгого инструмента** — инструмент отменяется, вызов `interrupted`, запуск `stopped`, новые
   сообщения начинают новый запуск. Тест — задача 9.

---

### Task 1: Настройки агента

**Files:**
- Create: `src/Infrastructure/Config/AgentSettings.php`
- Modify: `index.php`, `.env.example`
- Test: `test/Unit/Infrastructure/Config/ServiceSettingsTest.php`

**Interfaces:**
- Produces: `AgentSettings::fromEnv(array $env): self` с полями `positive-int $maxTurns`, `$tokenBudget`,
  `$runTimeout`, `$toolTimeout`; метод `ownerLimits(): RunLimits` (тип — задача 3; до неё — вернуть поля, а метод
  добавить в задаче 3).

- [ ] **Step 1: Failing tests** — `testAgentDefaults` (30 / 400000 / 900 / 120 при пустом env),
  `testAgentToolTimeoutCappedAt600` (`AGENT_TOOL_TIMEOUT=601` → `ConfigException`),
  `testAgentRejectsNonPositive` (`AGENT_MAX_TURNS=0` → `ConfigException`). Образец — существующие тесты `LlmSettings`.
- [ ] **Step 2:** `composer test -- --filter ServiceSettingsTest` → FAIL (класса нет).
- [ ] **Step 3:** реализовать через `Env::positiveInt($env, 'AGENT_MAX_TURNS', 30, 10_000)` и т. п.; потолок
  `AGENT_TOOL_TIMEOUT` — 600, `AGENT_RUN_TIMEOUT` — 86 400, `AGENT_TOKEN_BUDGET` — 50 000 000. Дописать переменные с
  комментариями в `.env.example`, загрузить в `index.php` рядом с `LlmSettings`.
- [ ] **Step 4:** тест → PASS.
- [ ] **Step 5:** `composer check`, коммит `feat(app): read agent limits from the environment`.

### Task 2: Миграция схемы запусков

**Files:**
- Create: `migrations/0003_agent_runs.sql`
- Test: `test/Integration/AgentSchemaTest.php` (группа `integration`)

Схема (точные имена — их используют задачи 7–9):

```sql
ALTER TABLE conversations ADD COLUMN system_prompt text, ADD COLUMN model text;

CREATE TABLE runs (
    id bigserial PRIMARY KEY,
    conversation_id bigint NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    principal_id bigint NOT NULL REFERENCES principals (id) ON DELETE CASCADE,
    parent_run_id bigint REFERENCES runs (id) ON DELETE CASCADE,
    model text NOT NULL,
    status text NOT NULL CHECK (status IN ('running', 'waiting_approval', 'done', 'failed', 'stopped')),
    stop_reason text,
    max_turns integer NOT NULL, token_budget integer NOT NULL, run_timeout integer NOT NULL, tool_timeout integer NOT NULL,
    turns integer NOT NULL DEFAULT 0,
    tokens_used integer NOT NULL DEFAULT 0,
    tokens_reserved integer NOT NULL DEFAULT 0,
    active_ms bigint NOT NULL DEFAULT 0,
    prompt_anchor integer NOT NULL DEFAULT 0,   -- usage.promptTokens() of the last call
    warned boolean NOT NULL DEFAULT false,      -- 80% warning already given
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (tokens_used >= 0 AND tokens_reserved >= 0)
);
-- One active run per conversation.
CREATE UNIQUE INDEX runs_one_active_per_conversation ON runs (conversation_id) WHERE status IN ('running', 'waiting_approval');

ALTER TABLE messages
    DROP CONSTRAINT messages_role_check,
    ADD CONSTRAINT messages_role_check CHECK (role IN ('user', 'assistant', 'tool')),
    ADD COLUMN run_id bigint REFERENCES runs (id) ON DELETE SET NULL,
    ADD COLUMN tool_calls jsonb,
    ADD COLUMN tool_call_id text;

CREATE TABLE tool_calls (
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    call_id text NOT NULL,
    position integer NOT NULL,
    name text NOT NULL,
    arguments jsonb,                -- null when the JSON did not parse
    danger text NOT NULL CHECK (danger IN ('read', 'write', 'irreversible')),
    status text NOT NULL CHECK (status IN ('pending', 'running', 'done', 'failed', 'awaiting_approval', 'denied', 'interrupted')),
    result_preview text,
    result_path text,
    started_at timestamptz, finished_at timestamptz,
    PRIMARY KEY (run_id, call_id)
);

CREATE TABLE run_inbox (
    id bigserial PRIMARY KEY,
    run_id bigint NOT NULL REFERENCES runs (id) ON DELETE CASCADE,
    content text NOT NULL,
    external_message_id text,
    created_at timestamptz NOT NULL DEFAULT now(),
    consumed_at timestamptz
);

CREATE TABLE principal_policies (
    principal_id bigint PRIMARY KEY REFERENCES principals (id) ON DELETE CASCADE,
    policy jsonb NOT NULL,          -- overrides only; see ParticipantPolicy::fromArray
    updated_at timestamptz NOT NULL DEFAULT now()
);
```

- [ ] **Step 1: Failing test** `testSecondActiveRunInConversationIsRejected` (две вставки `running` в один диалог →
  `SqlQueryError`) и `testToolRoleIsAccepted`. Подготовка БД — как в `MigratorTest` (база `*_test`, пересоздание схемы),
  затем `Migrator::migrate(Migrator::loadDirectory(...))`.
- [ ] **Step 2:** проверить имя существующего ограничения роли в `messages` (`\d messages` на сервере или
  `pg_constraint`) и поправить `DROP CONSTRAINT`, если имя другое.
- [ ] **Step 3:** написать миграцию; интеграционные тесты пройдут на сервере в задаче 11 (локально PostgreSQL нет).
- [ ] **Step 4:** `composer check` (интеграционные исключены), коммит `feat(db): add agent runs, tool calls and policies`.

### Task 3: Домен — запуск, лимиты, политика участника

**Files:**
- Create: `src/Domain/Agent/RunStatus.php`, `StopReason.php`, `DangerClass.php`, `ToolCallStatus.php`, `RunLimits.php`,
  `SandboxLimits.php`, `ParticipantPolicy.php`, `Run.php`
- Create: `src/App/Service/PolicyService.php`, `src/Domain/Repository/PolicyRepository.php`
- Test: `test/Unit/Domain/Agent/ParticipantPolicyTest.php`, `test/Unit/App/Service/PolicyServiceTest.php`,
  `test/Support/InMemoryPolicyRepository.php`

**Interfaces:**
- Produces:
  - `enum RunStatus: string { Running='running'; WaitingApproval='waiting_approval'; Done='done'; Failed='failed'; Stopped='stopped' }`
  - `enum StopReason: string { Answered='answered'; TurnLimit='turn_limit'; TokenBudget='token_budget'; Deadline='deadline'; LoopGuard='loop_guard'; InvalidCalls='invalid_calls'; Truncated='truncated'; PersistFailed='persist_failed'; ModelFailed='model_failed'; Stopped='stopped' }` + `isLimit(): bool` (первые три после Answered и Deadline) и `label(): string` (русский текст для отчёта).
  - `enum DangerClass: string { Read='read'; Write='write'; Irreversible='irreversible' }`
  - `enum ToolCallStatus: string` — значения как в CHECK миграции.
  - `final readonly class RunLimits(int $maxTurns, int $tokenBudget, int $runTimeout, int $toolTimeout)`; `min(self $cap): self` — поэлементный минимум.
  - `final readonly class SandboxLimits(int $diskBytes, int $processes, int $cpuSeconds, int $memoryBytes, int $concurrentCommands)`; `min(self $cap): self`. Значения задаёт часть 2; здесь — только хранение.
  - `final readonly class ParticipantPolicy(?list<string> $tools /* null = все */, RunLimits $run, ?SandboxLimits $sandbox, bool $mayRequestApproval)`; `static fromArray(array $overrides, self $base): self`, `toArray(): array` (только переопределения), `capTo(self $owner): self` (инструменты — пересечение, лимиты — `min`, `mayRequestApproval` — `&&`), `allowsTool(string $name): bool`.
  - `final readonly class Run(int $id, int $conversationId, int $principalId, ?int $parentRunId, string $model, RunStatus $status, ?StopReason $stopReason, RunLimits $limits, int $turns, int $tokensUsed, int $tokensReserved, int $activeMs, int $promptAnchor, bool $warned)`.
  - `interface PolicyRepository { find(int $principalId): ?array; save(int $principalId, array $overrides): void; }`
  - `PolicyService(PolicyRepository, PrincipalRepository-или-Role, RunLimits $ownerLimits)`: `forPrincipal(Principal $p): ParticipantPolicy` — владелец: все инструменты, `$ownerLimits`, `mayRequestApproval=true`; `admin`/`user`: `tools=[]`, `mayRequestApproval=false`, лимиты владельца; поверх — переопределения из репозитория; итог — `capTo(owner)`. `update(Principal $target, array $overrides): ParticipantPolicy` — владельцу менять себя нельзя (`DomainException`).
- Добавить в `AgentSettings`: `ownerLimits(): RunLimits`.

- [ ] **Step 1: Failing tests:** `testOverridesCannotExceedOwner` (поправка `max_turns=999` у user при владельце 30 →
  30); `testToolsAreIntersectedWithOwner`; `testUserHasNoToolsByDefault`; `testOwnerPolicyCannotBeChanged`;
  `testFromArrayRoundTrip`.
- [ ] **Step 2:** запустить → FAIL.
- [ ] **Step 3:** реализовать. `spec: BRIEF.md#права-участника`.
- [ ] **Step 4:** PASS; `composer check`; коммит `feat(app): add participant policies capped by the owner`.

### Task 4: Инструменты — контракт, реестр, проверка вызовов

**Files:**
- Create: `src/App/Agent/Tool/Tool.php`, `ToolResult.php`, `ToolContext.php`, `ToolRegistry.php`, `JsonSchema.php`,
  `ToolCallValidator.php`, `ValidatedCall.php`
- Test: `test/Unit/App/Agent/Tool/ToolRegistryTest.php`, `JsonSchemaTest.php`, `ToolCallValidatorTest.php`,
  `test/Support/FakeTool.php`

**Interfaces:**
- Produces:
  - `interface Tool { definition(): ToolDefinition; danger(array $args): DangerClass; untrusted(): bool; timeout(): positive-int /* seconds */; execute(array $args, ToolContext $ctx): ToolResult; }`
  - `final readonly class ToolResult(string $content, bool $isError = false)`; `static ok(string)`, `static error(string)`.
  - `final readonly class ToolContext(int $runId, int $conversationId, Principal $principal, Cancellation $cancellation)`.
  - `ToolRegistry`: `register(Tool $tool): void` (дубликат имени → `LogicException`), `get(string $name): ?Tool`,
    `definitions(ParticipantPolicy $policy): list<ToolDefinition>` (в порядке регистрации, только разрешённые),
    `names(ParticipantPolicy $policy): list<string>`.
  - `JsonSchema::validate(array $schema, mixed $value): list<string>` — ошибки по-русски с путём; поддержать `type`
    (`object`, `string`, `integer`, `number`, `boolean`, `array`), `properties`, `required`, `enum`, `items`,
    `additionalProperties: false`, `minimum`/`maximum`, `maxLength`.
  - `ToolCallValidator(ToolRegistry)`: `validate(list<ToolCall> $calls, ParticipantPolicy $policy, FinishReason $finish): ValidationOutcome`
    где `final readonly class ValidationOutcome(list<ValidatedCall> $calls, bool $truncated)`, а
    `final readonly class ValidatedCall(ToolCall $call, ?Tool $tool, ?array $args, ?string $error, DangerClass $danger)`
    (`error !== null` → не исполняется, `error` уходит модели результатом).
- Правила проверки — по `references/hermes-agent/agent/turn_tool_validation.py@e96221f:L97-L269`:
  одинаковые id делаются уникальными (суффикс `-2`); пустые аргументы → `{}`; дубли «имя + канонизированные
  аргументы» схлопываются в одну запись с результатом-ссылкой «повтор вызова <id>»; неизвестное или запрещённое
  политикой имя → ошибка «Неизвестный инструмент X. Доступны: a, b»; JSON не разбирается → ошибка; ответ с
  `FinishReason::Length` и аргументом, не заканчивающимся на `}`/`]`, → `truncated=true` (исполнять нельзя ничего);
  схема не проходит → ошибка со списком из `JsonSchema`. Класс опасности для невалидных — `Read` (не исполняются).

- [ ] **Step 1: Failing tests:** `testUnknownToolReturnsAllowedNames`, `testToolForbiddenByPolicyIsUnknown`,
  `testInvalidJsonIsNeverExecuted`, `testTruncatedArgumentsFlagTheBatch`, `testDuplicateCallsCollapse`,
  `testDuplicateIdsBecomeUnique`, `testSchemaErrorsAreListed`, `testEmptyArgumentsBecomeEmptyObject`;
  `JsonSchemaTest` — по одному тесту на ключевое слово.
- [ ] **Step 2:** FAIL. **Step 3:** реализовать с `ref:` на строки hermes. **Step 4:** PASS.
- [ ] **Step 5:** `composer check`; коммит `feat(app): add tool registry and call validation`.

### Task 5: Исполнение вызовов

**Files:**
- Create: `src/App/Agent/Tool/BatchPlanner.php`, `ToolExecutor.php`, `UntrustedWrapper.php`
- Test: `test/Unit/App/Agent/Tool/BatchPlannerTest.php`, `ToolExecutorTest.php`, `UntrustedWrapperTest.php`

**Interfaces:**
- Consumes: `ValidatedCall`, `Tool`, `ToolResult`, `ToolContext` (задача 4).
- Produces:
  - `BatchPlanner::segments(list<ValidatedCall> $calls): list<list<ValidatedCall>>` — подряд идущие исполняемые вызовы
    класса `Read` — один отрезок (не больше 8), остальные — отрезки из одного; невалидные — свои отрезки (не исполняются,
    но держат порядок). По `references/hermes-agent/agent/tool_dispatch_helpers.py@e96221f:L213-L259`; отступление —
    без анализа пересечения путей (в этой части нет файловых инструментов).
  - `ToolExecutor(int $maxResultChars = 50_000, ?LoggerInterface)`: `run(ValidatedCall $call, ToolContext $ctx, int $timeoutSeconds): ToolResult`
    — `TimeoutCancellation` поверх `$ctx->cancellation` (`CompositeCancellation`); таймаут → `ToolResult::error("Инструмент X не уложился в N с")`;
    любое `Throwable` кроме отмены запуска → `ToolResult::error("Инструмент X завершился с ошибкой: <класс>")` (текст
    исключения не показывается — в нём могут быть секреты), в лог — класс и имя; отмена запуска (`CancelledException`
    от `$ctx->cancellation`) пробрасывается. Результат длиннее лимита — обрезка с припиской
    «… [обрезано: показано N из M символов]». Недоверенный результат — через `UntrustedWrapper`.
  - `UntrustedWrapper::wrap(string $source, string $content): string` — по
    `references/hermes-agent/agent/tool_dispatch_helpers.py@e96221f:L579-L612`; вхождения маркеров внутри — с
    вставкой `\u{200B}` после `<`.

- [ ] **Step 1: Failing tests:** `testReadsFormOneSegmentWritesAreBarriers` (R,R,W,R → [[R,R],[W],[R]]),
  `testParallelSegmentIsCappedAt8`, `testTimeoutBecomesErrorResult` (FakeTool с `delay(2)`, таймаут 1 с),
  `testExceptionBecomesErrorResultWithoutMessage`, `testRunCancellationPropagates`, `testLongResultIsTruncated`,
  `testUntrustedMarkerInsideContentIsNeutralised`.
- [ ] **Step 2–4:** FAIL → реализовать → PASS (тесты на таймауты — `AsyncTestCase`).
- [ ] **Step 5:** `composer check`; коммит `feat(app): execute tool calls with timeouts and isolation of failures`.

### Task 6: Ограничитель зацикливания

**Files:**
- Create: `src/App/Agent/LoopGuard.php`, `src/App/Agent/GuardVerdict.php`
- Test: `test/Unit/App/Agent/LoopGuardTest.php`

**Interfaces:**
- Produces: `enum GuardVerdict { Ok; Warn; Halt }`; `LoopGuard::observe(list<string> $batchSignatures): GuardVerdict`
  — одна подпись на вызов: `sha1(name . "\0" . canonicalJson(args) . "\0" . resultContent)`; серия одинаковых
  одиночных подписей и повторяющиеся пачки с периодом 1–4: на 3-м круге `Warn` (один раз на серию), на 5-м `Halt`;
  любая другая подпись сбрасывает серию. Состояние — в объекте (живёт столько же, сколько `RunState`); после перезапуска
  серия начинается заново — допустимо. По `references/hermes-agent/agent/tool_guardrails.py@e96221f:L37-L46, L125-L130`;
  отступление — без отдельных счётчиков ошибок разных команд.
- Текст предупреждения (дописывается к результату последнего вызова): «Внимание: ты повторяешь тот же вызов с тем же
  результатом. Измени подход или подведи итог.»

- [ ] **Step 1: Failing tests:** `testThirdIdenticalCallWarnsOnce`, `testFifthIdenticalCallHalts`,
  `testDifferentCallResetsStreak`, `testAlternatingPairIsDetected` (A,B ×5 → Halt), `testPeriodFiveIsNotDetected`.
- [ ] **Step 2–4:** FAIL → реализовать → PASS.
- [ ] **Step 5:** `composer check`; коммит `feat(app): stop runs that repeat the same tool calls`.

### Task 7: Хранилища запусков и переписки

**Files:**
- Create: `src/Domain/Repository/RunRepository.php`, `TranscriptRepository.php`
- Create: `src/Infrastructure/Repository/PostgresRunRepository.php`, `PostgresTranscriptRepository.php`,
  `PostgresPolicyRepository.php`
- Modify: `src/Domain/Repository/ConversationRepository.php`, `src/Infrastructure/Repository/PostgresConversationRepository.php`
  (+ `conversationMeta(int $id): array{system_prompt: ?string, model: ?string, channel: string, chat_id: string, principal_id: int}`,
  `setMeta(int $id, string $systemPrompt, string $model): void`)
- Test: `test/Integration/RunRepositoryTest.php`, `TranscriptRepositoryTest.php`; фейки
  `test/Support/InMemoryRunRepository.php`, `InMemoryTranscriptRepository.php` (с тем же поведением резерва)

**Interfaces:**
- Produces:
  - `RunRepository`:
    - `start(int $conversationId, int $principalId, string $model, RunLimits $limits, ?int $parentRunId = null): ?Run` — `null`, если в диалоге уже есть активный (уникальный индекс);
    - `find(int $id): ?Run`; `active(int $conversationId): ?Run`; `unfinished(): list<Run>` (статус `running`);
    - `reserve(int $runId, int $tokens): bool` — одним `UPDATE runs SET tokens_reserved = tokens_reserved + $1 WHERE id = $2 AND tokens_used + tokens_reserved + $1 <= token_budget RETURNING id`;
    - `settle(int $runId, int $reserved, int $actual, int $promptAnchor): void` — `tokens_reserved -= reserved, tokens_used += actual, turns += 1, prompt_anchor = …`;
    - `addActiveTime(int $runId, int $ms): void`; `markWarned(int $runId): void`;
    - `finish(int $runId, RunStatus $status, ?StopReason $reason): void`.
  - `TranscriptRepository`:
    - `appendUser(int $conversationId, ?int $runId, string $content, ?string $externalId): void`;
    - `appendAssistant(int $conversationId, int $runId, string $text, list<ValidatedCall> $calls): void` — **одна транзакция**: строка `messages` с `tool_calls` (JSON `[{id,name,arguments}]` из исходных `ToolCall`) и строки `tool_calls` со статусом `pending` (или `failed` с готовым результатом для невалидных);
    - `startCall(int $runId, string $callId): void` (`running`, `started_at`);
    - `appendToolResult(int $conversationId, int $runId, string $callId, ToolCallStatus $status, string $content): void` — одна транзакция: строка `messages` роли `tool` и обновление `tool_calls` (`result_preview` — первые 1500 символов, `finished_at`);
    - `runMessages(int $runId): list<Message>` — вся переписка запуска в порядке `id`;
    - `history(int $conversationId, int $beforeRunId, int $limit): list<Message>` — из прошлых запусков только `user` и `assistant` без `tool_calls` с непустым текстом, последние `$limit` по порядку;
    - `pendingCalls(int $runId): list<array{call_id: string, name: string, arguments: ?array, danger: DangerClass, status: ToolCallStatus}>` — вызовы последнего ответа без результата;
    - `inbox(int $runId, string $content, ?string $externalId): void`; `consumeInbox(int $runId): list<string>` — атомарно `UPDATE … SET consumed_at = now() WHERE run_id = ? AND consumed_at IS NULL RETURNING content ORDER BY id`.
  - `PostgresPolicyRepository implements PolicyRepository`.

- [ ] **Step 1: Failing integration tests:** `testReserveRefusesBeyondBudget` (бюджет 100: `reserve(60)` true,
  `reserve(50)` false), `testConcurrentReservesNeverOverspend` (10 параллельных `reserve(20)` через `Amp\async` при
  бюджете 100 → ровно 5 успешных), `testSettleReplacesReservation`, `testAssistantAndCallsAreSavedTogether`,
  `testHistorySkipsToolRounds`, `testConsumeInboxReturnsEachMessageOnce`, `testStartRefusesSecondActiveRun`.
  Те же сценарии — юнит-тесты фейков в `test/Unit/Support/InMemoryRepositoriesTest.php`, чтобы фейки не разошлись с БД.
- [ ] **Step 2:** локально — только юнит-тесты фейков → FAIL → реализовать → PASS. Интеграционные — на сервере в задаче 11.
- [ ] **Step 3:** `composer check`; коммит `feat(db): store runs, transcripts and token reservations`.

### Task 8: Агентский цикл

**Files:**
- Create: `src/App/Agent/AgentLoop.php`, `RunState.php`, `RunObserver.php`, `PromptBuilder.php`
- Modify: `src/App/Service/SystemPrompt.php` (ярусы; правило про отсутствие инструментов заменяется правилами работы
  с инструментами и недоверенными данными)
- Test: `test/Unit/App/Agent/AgentLoopTest.php`, `test/Support/ScriptedLlm.php` (последовательность заранее заданных
  `ChatResponse`, по одной на вызов `stream`; текст отдаётся `TextDelta`, затем `StreamEnd`), `test/Support/RecordingObserver.php`

**Interfaces:**
- Consumes: всё из задач 3–7; `LlmClient`, `LlmCallRepository` (назначение `agent`).
- Produces:
  - `interface RunObserver { toolStarted(Run $run, string $name, string $argsPreview): void; textDelta(Run $run, string $text): void; finished(Run $run, string $finalText, ?StopReason $reason): void; }`
  - `PromptBuilder::system(Principal $principal, DateTimeImmutable $openedAt): string` — ярусы: постоянное
    (`SystemPrompt::text()`), контекст (кто участник, роль, дата открытия диалога), изменчивое — пусто в этой части.
    Строится один раз при открытии диалога и сохраняется `ConversationRepository::setMeta`.
  - `AgentLoop(LlmClient, RunRepository, TranscriptRepository, LlmCallRepository, ToolRegistry, ToolCallValidator, ToolExecutor, ?LoggerInterface, ?Closure $clock)`:
    `run(Run $run, ParticipantPolicy $policy, Principal $principal, RunObserver $observer, Cancellation $cancellation): Run` — возвращает запуск в конечном статусе.
- Итерация (по `references/hermes-agent/agent/conversation_loop.py@e96221f:L1648-L1807` и `turn_tool_round.py@e96221f:L49-L238`),
  каждая фаза — приватный метод, возвращающий `?StopReason` (`null` — дальше):
  1. `checkLimits`: ходы `turns >= maxTurns` → `TurnLimit`; время `activeMs + сейчас − начало >= runTimeout*1000` → `Deadline`;
     дописки `consumeInbox` → `appendUser` (после последнего результата — пары не рвутся, т. к. фаза идёт после записи результатов).
  2. `reserve`: оценка = `promptAnchor` + (символы сообщений после последнего вызова / 4) + `maxTokens`; `reserve()` false → `TokenBudget`.
  3. `callModel`: `ChatRequest(model, [system, ...history, ...runMessages], tools: registry->definitions(policy), maxTokens)`;
     поток `TextDelta` → `observer->textDelta`; `LlmException` → запись в `llm_calls`, `ModelFailed`. `settle()` фактическим `usage->totalTokens()`.
  4. Нет `toolCalls` → `appendAssistant(text, [])`, `Answered`.
  5. `validate`; `truncated` → без записи вызовов повторить запрос один раз в этом ходе, второй раз → `Truncated`.
  6. `persist`: `appendAssistant` в транзакции; исключение → `PersistFailed`, ни один инструмент не запускается.
  7. `execute`: отрезки `BatchPlanner`; каждый вызов — `startCall` → `observer->toolStarted` → `ToolExecutor::run`
     (таймаут = min(`tool->timeout()`, `limits->toolTimeout`, остаток времени запуска)) → `appendToolResult`
     в исходном порядке. Невалидные получают свой `error`.
  8. `guard`: `Warn` → приписка к последнему результату (до записи), `Halt` → `LoopGuard`. Три хода подряд без единого
     валидного вызова → `InvalidCalls`.
  9. Предупреждение 80% (ходы, токены или время), если `!warned`: приписка к последнему результату
     «Израсходовано больше 80% лимита (<что>). Подводи итог.», `markWarned` — по `turn_iteration_prep.py@e96221f:L41-L99, L183-L186`.
- Остановка по лимиту (`StopReason::isLimit()` или `Deadline`): один «итоговый» вызов без инструментов (`tools: []`) с
  сообщением пользователя «Лимит исчерпан (<label>). Кратко подведи итог: что сделано, что осталось.» — вне бюджета,
  но не больше `min(1000, maxTokens)` токенов вывода (по `turn_iteration_prep.py@e96221f:L406-L416`). Отчёт владельцу —
  его текст + строка «Остановлено: <label>. Ходов: N, токенов: M, время: T с.»
- Статус: `Answered` → `done`; `Stopped` → `stopped`; остальные → `failed`. Отмена `$cancellation` с причиной
  «выключение» (`ShutdownException`) не меняет статус — запуск останется `running` и продолжится после старта.

- [ ] **Step 1: Failing tests** (ScriptedLlm + FakeTool + фейки хранилищ):
  `testTwoStepTaskUsesToolsThenAnswers`; `testToolErrorGoesBackToModel`; `testUnknownToolGetsErrorResultAndRunContinues`;
  `testNothingExecutesWhenPersistFails` (фейк транскрипта бросает в `appendAssistant`, FakeTool не вызван);
  `testTruncatedArgumentsRetryOnceThenStop`; `testTurnLimitGivesFinalSummaryAndReport`;
  `testTokenBudgetStopsBeforeModelCall` (бюджет меньше `maxTokens` → ни одного `stream`, кроме итогового);
  `testEightyPercentWarningIsAddedOnce`; `testLoopGuardHaltsOnFifthRepeat`;
  `testInboxMessageIsDeliveredAfterToolResults` (дописка в инбоксе во время исполнения → в следующем запросе стоит
  после сообщения `tool`); `testShutdownLeavesRunRunning`; `testReadCallsRunInParallel` (два FakeTool по 1 с → меньше 1,5 с).
- [ ] **Step 2–4:** FAIL → реализовать с `ref:` → PASS.
- [ ] **Step 5:** `composer check`; коммит `feat(app): run the agent loop with tools and limits`.

### Task 9: Планировщик запусков, канал, команды

**Files:**
- Create: `src/App/Agent/RunScheduler.php`, `src/App/Agent/ChannelRunObserver.php`, `src/App/Agent/ShutdownException.php`
- Modify: `src/App/Service/ChatService.php` (вместо вызова модели — `RunScheduler::submit`; `/stop`; участник без
  инструментов идёт тем же циклом с пустым набором — отдельной ветки «просто чат» нет)
- Test: `test/Unit/App/Agent/RunSchedulerTest.php`, `test/Unit/App/Service/ChatServiceTest.php` (переписать)

**Interfaces:**
- Consumes: `AgentLoop::run`, `RunRepository`, `TranscriptRepository`, `ConversationRepository`, `PolicyService`, `Channel`.
- Produces:
  - `RunScheduler(…, float $debounce = 0.35, float $debounceCap = 1.0)`:
    - `submit(Principal $p, IncomingMessage $m, int $conversationId): void` — есть активный запуск → `inbox()` и
      возврат; нет → ждать `debounce` (новые сообщения того же чата за это время склеиваются через `\n\n`, не дольше
      `debounceCap`), `appendUser`, `RunRepository::start`, запуск `AgentLoop::run` в `async()`; возврат сразу.
      По `references/hermes-agent/gateway/run_busy.py` (режим `steer`) и настройкам `busy_text_debounce_seconds` —
      номера строк найти при реализации.
    - `stop(int $conversationId): bool` — отменяет волокно (`DeferredCancellation` с `CancelledException`, не
      `ShutdownException`), выполняющиеся вызовы → `interrupted`, запуск → `stopped`.
    - `resumeAll(): void` — для каждого `unfinished()`: незавершённые вызовы `pendingCalls` — `Read` в статусе
      `running` → снова `pending`; `Write`/`Irreversible` в `running` → `appendToolResult(…, Interrupted,
      "Прервано перезапуском: неизвестно, выполнено ли действие. Проверь состояние, прежде чем повторять.")`;
      `pending` исполняются обычным порядком (цикл начинает с фазы 7 для них); в чат — «Продолжаю задачу после
      перезапуска.»; затем `AgentLoop::run`. Отступление от hermes (`session-storage.md`: прерванный ход не
      продолжается) — одной фразой в коде.
    - `shutdown(): void` — отменяет все волокна `ShutdownException` и ждёт их.
  - `ChannelRunObserver`: заглушка «…» при старте; `toolStarted` → правка статуса «Выполняю `<имя>`: <аргументы до 200
    символов>…» (не чаще раза в 1,5 с, как поток в фазе 2); `textDelta` — поток как в фазе 2; `finished` — итог правкой
    и продолжениями (`TextSplitter`), `ParseMode::Markdown`.
  - `ChatService`: `/stop` → `RunScheduler::stop` и ответ «Остановлено.» или «Сейчас ничего не выполняется.»;
    `/new` при активном запуске — сначала `stop`. Модель диалога — `LlmSettings::model` (роли — часть 5), записывается
    `setMeta` при открытии вместе с системным промптом.
- **Взаимная блокировка:** `/stop` приходит через `ChatDispatcher` в очередь чата — она не занята, потому что
  `submit` возвращается сразу (запуск — в своём волокне). Проверить тестом.

- [ ] **Step 1: Failing tests:** `testMessageDuringRunGoesToInbox`; `testMessagesWithinDebounceAreMerged`;
  `testStopCancelsRunningToolAndMarksInterrupted`; `testNewMessageAfterStopStartsNewRun`;
  `testResumeRerunsInterruptedRead`; `testResumeDoesNotRepeatInterruptedWrite`; `testShutdownKeepsRunResumable`;
  `testStopIsHandledWhileRunIsBusy` (через настоящий `ChatDispatcher`); в `ChatServiceTest` — сохранить проверки
  отказа постороннему, `/start`, `/new`, не-текста.
- [ ] **Step 2–4:** FAIL → реализовать → PASS.
- [ ] **Step 5:** `composer check`; коммит `feat(app): schedule one run per conversation with steering and resume`.

### Task 10: Первые инструменты ядра

**Files:**
- Create: `src/App/Agent/Tool/HttpRequestTool.php`, `src/App/Agent/Tool/AddressGuard.php`, `src/App/Agent/Tool/SetParticipantPolicyTool.php`
- Test: `test/Unit/App/Agent/Tool/HttpRequestToolTest.php`, `AddressGuardTest.php`, `SetParticipantPolicyToolTest.php`

**Interfaces:**
- Consumes: `Tool`, `ToolResult`, `ToolContext`, `PolicyService`; `Amp\Http\Client\HttpClient` из `HttpClients::create()`.
- Produces:
  - `AddressGuard::check(string $host): ?string` — разрешает имя через `Amp\Dns\resolve` и возвращает текст отказа,
    если любой адрес частный, петлевой, link-local, multicast, `0.0.0.0/8`, CGNAT `100.64.0.0/10`, IPv6 ULA/link-local
    или имя без точки (сервисы compose). Подключение — только к проверенному адресу (подменять адрес в запросе или
    проверять после соединения), чтобы DNS-ребиндинг не обошёл проверку. Это непреодолимый запрет.
  - `HttpRequestTool`: имя `http_request`; аргументы `{url: string(maxLength 2048), method: enum[GET, HEAD], headers?: object}`;
    в этой части только `GET`/`HEAD` (остальные методы — часть 3 с подтверждением); `danger` → `Read`;
    `untrusted()` → true; таймаут 30 с; только `http`/`https`; тело ответа — до 1 МБ, текстом, с кодом и
    `content-type` в первой строке; редиректы — не больше 5, каждый адрес снова через `AddressGuard`.
  - `SetParticipantPolicyTool`: имя `set_participant_policy`; аргументы `{principal_id: integer, tools?: array<string>,
    max_turns?: integer, token_budget?: integer, run_timeout?: integer, tool_timeout?: integer, may_request_approval?: boolean}`;
    `danger` → `Write`; исполняется только когда `ctx->principal` — владелец, иначе `ToolResult::error`; результат —
    итоговая политика после `capTo`.
  - Ни один из них не попадает в реестр участника без разрешения политики (задача 3).

- [ ] **Step 1: Failing tests:** `testPrivateAndLoopbackAddressesAreRefused` (10.0.0.1, 127.0.0.1, 169.254.169.254,
  ::1, fd00::1, `postgres`), `testRedirectToInternalAddressIsRefused`, `testOnlyGetAndHeadAreAccepted`,
  `testResultIsMarkedUntrusted` (через `ToolExecutor`), `testNonOwnerCannotChangePolicies`,
  `testPolicyChangeIsCappedByOwner`.
- [ ] **Step 2–4:** FAIL → реализовать (HTTP — фейковый `HttpClient` через `DelegateHttpClient`) → PASS.
- [ ] **Step 5:** `composer check`; `security-review` по изменениям задачи; коммит
  `feat(app): add http_request and set_participant_policy tools`.

### Task 11: Сборка, проверка на сервере, спецификация

**Files:**
- Create: `src/Infrastructure/Module/AgentModule.php` (`start()` → `RunScheduler::resumeAll()`, `stop()` → `shutdown()`)
- Modify: `index.php` (сборка: репозитории, `PolicyService(AgentSettings::ownerLimits())`, реестр с двумя
  инструментами, `AgentLoop`, `RunScheduler`, `ChatService`; `AgentModule` добавляется **после** `TelegramModule`,
  чтобы при остановке сначала встал приём сообщений), `ROADMAP.md`, `BRIEF.md`
- Test: интеграционные тесты задач 2 и 7 на сервере

- [ ] **Step 1:** `composer check` локально — зелёный.
- [ ] **Step 2:** `code-review`, затем `simplify` по всей части; исправить найденное, снова `composer check`.
- [ ] **Step 3:** коммит сборки `feat(app): wire the agent loop into the application`; push в `master`; дождаться CI.
- [ ] **Step 4:** на сервере по `CLAUDE.md → Развёртывание`: `git pull --ff-only`, сабмодули, новые переменные
  `AGENT_*` в `.env` (если хотим не значения по умолчанию), `docker compose up -d --build`, `docker compose ps` —
  оба `healthy`, `docker compose logs --tail=50 app` — миграция `0003` применена.
- [ ] **Step 5:** `docker compose --profile test run --rm --build test` — интеграционные тесты зелёные (вывод в отчёт).
- [ ] **Step 6:** проверка через Telegram Web (`claude-in-chrome`, только чат бота):
  1. «Скачай https://example.com и скажи заголовок страницы, затем узнай код ответа https://httpbin.org/status/404» —
     два вызова `http_request`, статус-сообщение показывает шаги, итоговый ответ верный;
  2. «Открой http://postgres:5432» — отказ инструмента виден модели, запуск не падает;
  3. долгая задача → посреди неё дописка «и ещё проверь https://example.org» — учтена в том же запуске;
  4. долгая задача → `docker compose restart app` посреди → после старта «Продолжаю задачу после перезапуска.» и
     итог; в `tool_calls` нет повторного исполнения пишущих вызовов;
  5. `/stop` посреди задачи → «Остановлено.»;
  6. временно `AGENT_MAX_TURNS=2` → отчёт «Остановлено: исчерпаны ходы…»; вернуть значение.
- [ ] **Step 7:** `docker image prune -f --filter label=com.docker.compose.project=mercurius`.
- [ ] **Step 8:** спецификация: в `BRIEF.md` — «Решено в фазе 3 (часть 1)» с тем, что отличилось от проекта;
  в `ROADMAP.md` — отметить сделанные пункты фазы 3 (фаза остаётся «в работе»); коммит `docs(spec): record agent loop
  results`; push.
