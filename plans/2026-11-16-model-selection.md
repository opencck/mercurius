# Профиль задачи, выбор модели, каскад (фаза 5, часть 2) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** оркестратор описывает часть задачи профилем из оценок 1–10, код подбирает самую дешёвую подходящую модель по
каталогу, а неудачу воркера повторяет один раз на более сильной модели.

**Architecture:** индексы Artificial Analysis хранятся в `model_catalog`; `ModelSelector` (App/Agent/Model) переводит
профиль в требования по процентилям каталога и выбирает модель; `WorkerRunner` берёт модель из профиля (или роли) и
при неудаче по вине модели делает каскад.

**Spec:** [BRIEF.md → Часть 2](../BRIEF.md#часть-2--профиль-задачи-выбор-модели-каскад), [профиль задачи](../BRIEF.md#профиль-задачи),
[выбор](../BRIEF.md#выбор).

## Global Constraints

- Оси профиля: `text`, `reasoning`, `math`, `academic`, `code`, `agency`, `images`, `audio`, `context`, `error_cost`,
  `urgency`; целые 1–10, нет — 1.
- Индекс по оси: text/reasoning/math/academic → intelligence, code → coding, agency → agentic.
- Требование: оценка `s'` = `min(10, s + max(0, error_cost − 5) ÷ 2)`; индекс ≥ процентиль `s' × 10` среди моделей с
  этим индексом (оценка ≤ 1 — без требования).
- Жёсткие: tools — если у части есть инструменты; images ≥ 5 → `image` во входных модальностях; audio ≥ 5 → `audio`;
  context `s` > 1 → окно ≥ `2^(s+12)`.
- Цена — `(3 × вход + выход) ÷ 4`; модель без цены не выбирается; без индексов — только если все оценки осей с индексами ≤ 3.
- Нет подходящих — роль `strong`, способ `fallback`. Решение — `routing_decisions` (класс `profile`, способ `profile`).
- Каскад — при `model_failed`, `invalid_calls`, `truncated`, `loop_guard`, `internal_error`: требования +2 или `strong`;
  только если остаток доли ≥ 8000 токенов; один раз.

## Review Focus

1. Все модели каталога без индексов (каталог пуст) — выбор не падает, берётся роль. (`testEmptyCatalogFallsBackToTheStrongRole`.)
2. Профиль требует картинки, а дешёвые модели их не видят — выбрана модель с `image`. (`testImagesNeedAVisionModel`.)
3. Высокая цена ошибки поднимает требования. (`testErrorCostRaisesTheBar`.)
4. Каскад не превышает долю воркера и не повторяется дважды. (`testCascadeOnceWithinTheShare`.)
5. Остановка владельцем не вызывает каскад. (`testStoppedWorkerIsNotRetried`.)

---

### Task 1: Индексы в каталоге
- Миграция `0008_model_scores.sql` (`intelligence_index`, `coding_index`, `agentic_index`); хранилища пишут и читают; контракт.
- `ModelCatalogRepository::all(string $provider): list<ModelInfo>` для процентилей.

### Task 2: `TaskProfile` и `ModelSelector`
- `TaskProfile::fromArray(array): self`; `ModelSelector::choose(TaskProfile, bool $needsTools, ?int $conversationId): array{ModelRef, string $reason}`; `escalate(...)`.

### Task 3: Делегирование с профилем и каскадом
- `delegate_task`: поле `profile` (объект осей); `WorkerRunner` выбирает модель, делает каскад, пишет решение; отчёт «каскад: A → B».

### Task 4: Ревью, сервер, спецификация
- Ревью (Opus), исправления, развёртывание, проверка задачи с профилем, итог в BRIEF, ROADMAP, план удалить.
