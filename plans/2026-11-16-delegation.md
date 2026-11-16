# Делегирование воркерам (фаза 5, часть 1) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** оркестратор вызывает `delegate_task`, части задачи исполняют воркеры — вложенные запуски в своих диалогах,
на моделях своих ролей, в пределах общего бюджета; оркестратор получает сжатый отчёт.

**Architecture:** `DelegateTaskTool` проверяет задачи и отдаёт их `WorkerRunner`. Тот резервирует бюджет у
родительского запуска, открывает воркеру диалог (канал `worker`), запускает `AgentLoop::run` с урезанной политикой и
тихим наблюдателем, собирает отчёт. Инструмент регистрируется после создания цикла (цикл и раннер ссылаются друг на друга
через общий реестр).

**Spec:** [BRIEF.md → Оркестратор и воркеры, часть 1](../BRIEF.md#часть-1--синхронное-делегирование). Опора —
`references/hermes-agent/tools/delegate_tool.py@e96221fa` (схема L843-L922, ребёнок L204-L395, итог L403-L412),
`delegate_tool_progress.py@e96221fa:L149-L158, L181-L234` (промпт и задание), `delegate_tool_results.py@e96221fa:L254-L309`
(потолок итога), `delegate_tool_toolsets.py@e96221fa:L13-L22` (запрещённые инструменты).

## Global Constraints

- `tasks`: 1–5; `goal` обязателен; `role` ∈ `cheap|main|strong`, по умолчанию `main`; `tools` — имена.
- Воркеру никогда: `delegate_task`, `set_model_role`, `set_participant_policy`; инструменты — пересечение с политикой
  оркестратора; `mayRequestApproval = false`.
- `AGENT_WORKER_MAX_TURNS` = 15 (1..100), `AGENT_WORKER_TOKEN_BUDGET` = 100000 (1000..10 000 000).
- Резерв у родителя: `min(остаток бюджета родителя, задач × AGENT_WORKER_TOKEN_BUDGET)`; не удалось — ошибка
  инструмента «не хватает бюджета». Воркеру — равная доля. После — `settleAuxiliary(родитель, резерв, факт)`.
- Параллельно (до 3) — только если у всех задач инструменты класса «чтение» (`danger([])`); иначе по порядку.
- Итог воркера в отчёте — до `min(4000, max(500, 16000 ÷ число задач))` символов; длиннее — голова и пометка с номером запуска.
- Воркеры при перезапуске не продолжаются: `resumeAll` закрывает их как `failed` / `internal_error` с пометкой.

## Review Focus

1. Модель просит воркеру инструмент, которого нет у оркестратора, — не выдаётся. (Task 2, `testWorkerGetsOnlyAllowedTools`.)
2. Воркер пытается необратимое действие — отказ без карточки владельцу. (Task 2, `testWorkerCannotAskTheOwner`.)
3. Бюджет родителя почти исчерпан — делегирование отказывает, ничего не запускается. (Task 2, `testNotEnoughBudgetRefuses`.)
4. `/stop` оркестратора во время работы воркера — воркер остановлен. (Task 2, `testStopStopsTheWorker`.)
5. Перезапуск с недоделанным воркером — воркер не продолжается сам и не пишет в чат. (Task 3, `testWorkersAreNotResumed`.)

---

### Task 1: Настройки и тихий наблюдатель

- `AgentSettings::$workerMaxTurns`, `$workerTokenBudget`; `.env.example`. `SilentObserver implements RunObserver`.
- [ ] Тесты настроек → реализация → коммит вместе с Task 2.

### Task 2: `WorkerRunner` и `DelegateTaskTool`

- `WorkerRunner::run(ToolContext $parent, list<WorkerTask> $tasks): list<WorkerReport>`; `WorkerTask(goal, context, output, tools, role)`;
  `WorkerReport(index, runId, status, model, turns, tokens, cost, summary)`.
- `DelegateTaskTool` (Write, timeout 900): схема, проверка, текст отчёта.
- [ ] Тесты: `testWorkerAnswersAndTheOrchestratorGetsTheReport` (отчёт со статусом, моделью роли и итогом; история
  родителя без сообщений воркера), `testWorkerGetsOnlyAllowedTools`, `testWorkerCannotAskTheOwner`,
  `testNotEnoughBudgetRefuses`, `testReadOnlyTasksRunInParallel`, `testFailedWorkerIsAValueNotAnError`,
  `testStopStopsTheWorker`, `testLongSummaryIsCut` → коммит `feat(app): delegate parts of a task to worker runs`.

### Task 3: Перезапуск, сборка

- `RunScheduler::resumeAll` закрывает незавершённые запуски с `parentRunId` и не продолжает их; `index.php` регистрирует
  `delegate_task` после создания цикла; системный промпт оркестратора — критерии «когда делить» (кратко).
- [ ] Тест `testWorkersAreNotResumed` → коммит `feat(app): wire delegation and close workers on restart`.

### Task 4: Ревью, сервер, спецификация

- [ ] Ревью (Opus), исправления; сервер: задача «сравни три страницы» → `delegate_task` с тремя задачами чтения, отчёт,
  `/trace` оркестратора и воркеров, расход; `BRIEF.md` «Решено в фазе 5 (часть 1)», отметка в `ROADMAP.md`, план удалить.
