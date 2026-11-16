# Роли моделей, классификация, каталог — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** модель диалога выбирается при его открытии по классу первого запроса и таблице ролей; модели — от любых
провайдеров (сейчас OpenRouter); каталог в БД даёт окно модели для сжатия; роли меняют агент, владелец и те, кому
владелец дал инструмент.

**Architecture:** модель — `ModelRef` (провайдер + идентификатор, строка `provider:model`, без префикса — `openrouter`).
`ProviderRouter` реализует `LlmClient` и передаёт запрос клиенту провайдера, сняв префикс. Таблицы `model_catalog`
(по провайдерам), `model_roles`, `routing_decisions`. `ModelCatalogModule` грузит каталог каждого провайдера при
старте (если старше суток) и раз в сутки. `ModelRoles` — роли из БД с начальными значениями. `RequestClassifier` —
эвристики, неясное — дешёвой моделью. `ModelRouter` выбирает модель при открытии диалога и пишет решение.

**Spec:** [BRIEF.md → Модели](../BRIEF.md#модели) (в т.ч. решения владельца 2026-11-16 о провайдерах и правах),
[начальная таблица ролей](../BRIEF.md#начальная-таблица-ролей-моделей). Опора — [hermes: вспомогательные модели](../notes/hermes-agent.md#вспомогательные-модели).

## Global Constraints

- `ModelRef::parse('x/y')` → `openrouter:x/y`; `ModelRef::parse('anthropic:claude-x')` → провайдер `anthropic`.
  Провайдер — `[a-z0-9_-]+`; неизвестный провайдер в `ProviderRouter` → `LlmException(BadRequest)`.
- Роли: `cheap`, `main`, `strong`, `media`; начальные — `openrouter:openai/gpt-5.6-luna`, `openrouter:z-ai/glm-5.3-flash`,
  `openrouter:anthropic/claude-opus-5`, `openrouter:google/gemini-3.8-flash`.
- `set_by`: `seed`, `agent`, `owner`; плюс `set_by_principal`. `owner` (зафиксировано) пишется только из запуска
  владельца с `fix: true`; такую строку меняет только владелец. Остальные записи — `agent`.
- `set_model_role(role, model, reason, fix?)` — класс «запись», причина ≥ 10 символов; модель должна быть в каталоге
  своего провайдера и (кроме `cheap`) поддерживать инструменты; доступ — по политике инструментов участника (у владельца есть).
- Классы: `short`, `normal`, `complex`; `short`/`normal` → `main`, `complex` → `strong`; изображения/звук → `media`
  (когда канал начнёт принимать вложения). Эвристики: команда `/…` или ≤ 200 символов без признаков сложности →
  `short`; ≥ 2000 символов или признаки (спроектируй, архитектур, план, проанализируй, сравни варианты, рефактор,
  исследуй, по шагам) → `complex`; иначе — дешёвая модель одним словом; мусор или ошибка → `normal` (`fallback`).
- Модель диалога выбирается при открытии и не меняется до конца диалога.
- Роль указывает на модель, которая есть в каталоге без `tools`, — откат на `main` с причиной.
- Каталог: при старте, если старше 24 ч, и раз в 24 ч; сбой — предупреждение, старый в силе.
- Окно для сжатия — `context_length` модели диалога из каталога, иначе `LLM_CONTEXT_WINDOW`; дешёвая модель сжатия —
  роль `cheap` (вместо `LLM_CHEAP_MODEL`, настройка остаётся запасной).
- Каждое решение — строка `routing_decisions` (диалог, класс, способ, роль, модель, причина).

## Review Focus

1. Каталог не загрузился — диалоги открываются, сжатие берёт окно из настройки. (Task 3, `testWindowFallsBackWithoutCatalog`.)
2. Агент или участник пытается перезаписать зафиксированную владельцем роль — отказ с объяснением. (Task 2, `testOwnerFixedRoleIsNotChanged`.)
3. Дешёвая модель классификатора ответила мусором — `normal`, `fallback`. (Task 3, `testGarbageAnswerFallsBackToNormal`.)
4. Второе сообщение того же диалога не перевыбирает модель. (Task 4, `testModelIsChosenOnceForAConversation`.)
5. Старые диалоги со строкой модели без провайдера продолжают работать. (Task 1, `testPlainIdMeansOpenRouter`; Task 4.)

---

### Task 1: `ModelRef`, `ProviderRouter`, схема и хранилища, модуль каталога

- `ModelRef(string $provider, string $model)`, `parse(string): self`, `__toString(): string`.
- `ProviderRouter(array<string, LlmClient> $clients)` implements `LlmClient`: `complete`/`stream` с моделью без префикса.
- `ModelCatalogRepository::replace(string $provider, list<ModelInfo>)`, `find(ModelRef)`, `fetchedAt(string $provider)`.
- `ModelRoleRepository::all()`, `set(string $role, ModelRef, string $reason, string $setBy, ?int $principalId): bool`, `seed(array<string, ModelRef>, string $reason)`.
- `ModelCatalogModule(array<string, Closure(): list<ModelInfo>> $sources, ModelCatalogRepository, LoggerInterface, float $interval = 86400)`.
- [ ] Контракт хранилищ, тесты `ModelRef`, `ProviderRouter`, модуля (RED) → реализация → коммит `feat(db): store models of any provider, roles and routing decisions`.

### Task 2: Роли и `set_model_role`

- `ModelRoles::model(string $role): ModelRef`, `ModelRoles::set(...)`: string (итог для модели).
- [ ] Тесты: `testSeedValuesComeFirst`, `testAgentChangesARoleWithAReason`, `testOwnerFixedRoleIsNotChanged`, `testOnlyTheOwnerFixes`, `testUnknownOrToollessModelIsRefused`, `testShortReasonIsRefused` → коммит `feat(app): let the agent and allowed participants change model roles`.

### Task 3: Классификация и выбор

- `RequestClassifier::classify(string): array{RequestClass, string}`, `ModelRouter::choose(int $conversationId, string $text): ModelRef`, `ModelRouter::window(string $model): int`.
- [ ] Тесты эвристик, `testUnclearTextAsksTheCheapModel`, `testGarbageAnswerFallsBackToNormal`, `testComplexGoesToTheStrongModel`, `testModelWithoutToolsFallsBackToMain`, `testWindowFallsBackWithoutCatalog`, `testDecisionIsRecorded` → коммит `feat(app): choose the conversation model by a rough class of the request`.

### Task 4: Сборка

- `RunScheduler`: модель диалога — `ModelRouter::choose`; `ContextCompactor`: окно через `Closure(string $model): int`, дешёвая модель через `Closure(): string`.
- `index.php`: `ProviderRouter(['openrouter' => …])`, каталог, роли (seed), модуль, `set_model_role`.
- [ ] Тесты `testModelIsChosenOnceForAConversation`, `testComplexFirstMessageOpensWithTheStrongModel` → коммит `feat(app): open conversations with the model their first request needs`.

### Task 5: Ревью, сервер, спецификация, закрытие фазы

- [ ] Ревью свежим агентом (Opus), исправления.
- [ ] Сервер: каталог загружен; `/new` + простой вопрос → `main`; `/new` + «спроектируй …» → `strong` (`routing_decisions`); `set_model_role` через бота.
- [ ] `BRIEF.md` «Решено в фазе 3 (часть 5)», фаза 3 — готова в `ROADMAP.md`, удалить план; `/graphify . --update`.
