# Подтверждения, разрешения, запреты, посетители (часть 3 фазы 3) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** необратимое действие агента ждёт решения владельца в Telegram (кнопки «Разрешить», «Разрешать всегда»,
«Отказать»), запуск переживает ожидание и перезапуск; сохранённые разрешения снимают вопрос; новый посетитель
вызывает карточку владельцу.

**Architecture:** `CommandClassifier` и `HttpRequestTool` дают вызову класс `irreversible` и ключ разрешения.
`AgentLoop` перед таким вызовом спрашивает `ApprovalGate`: есть разрешение — исполняет; нет — создаёт ожидание
(`approvals`), ставит вызов в `awaiting_approval`, запуск в `waiting_approval` и выходит из цикла.
`ApprovalService` (он же `CallbackHandler`) принимает нажатие владельца атомарно, правит карточку и продолжает
запуск через `RunScheduler::resumeRun()`. Просроченные ожидания закрывает таймер. Текст владельца во время
ожидания — отказ с причиной.

**Tech Stack:** PHP 8.2, AMPHP v3, PostgreSQL, `mercurius-messenger` (кнопки, нажатия — `6744d13`).

**Spec:** [BRIEF.md → Подтверждения и запреты](../BRIEF.md#подтверждения-и-запреты),
[→ Участники: люди и агенты](../BRIEF.md#участники-люди-и-агенты) (посетитель),
[→ Права доступа к сущностям](../BRIEF.md#права-доступа-к-сущностям).

## Global Constraints

- Скиллы `skill-opencck`, `skill-amphp`; TDD; `ref:` hermes `e96221f` (approval.py, approval_detection.py,
  adapter.py `_handle_exec_approval_callback`), строки сверять по файлу.
- `AGENT_APPROVAL_TIMEOUT=1800` (с). Данные кнопки: `ap:<id>:a|g|d` (разрешить, всегда, отказать),
  `pv:<principalId>:t|u|a|r` (посетитель: разговор, пользователь, администратор, отклонить), `pg:<id>:x` (отзыв
  разрешения) — до 64 байт.
- Нажатие принимает только владелец; остальным — `answerCallback(..., 'Это может только владелец.', alert: true)`.
  На каждое нажатие — `answerCallback`.
- Карточки — владельцу в личный чат (`TELEGRAM_ADMIN_ID`), не в место разговора.
- Секреты в показе маскируются (`SecretMasker`).
- Коммиты — по `CLAUDE.md`.

## Review Focus

1. Два быстрых нажатия на одну карточку — решение одно, второе получает «уже решено». Тест — задача 4.
2. Перезапуск, пока ждём, — после старта ожидание живо, нажатие продолжает запуск. Тест — задача 5.
3. В одном ответе модели два необратимых вызова — второй ждёт своей карточки после решения по первому, а не
   исполняется молча. Тест — задача 4.
4. Обфусцированная команда (`git pu""sh`, `$(echo cHVzaA==|base64 -d)`, `bash -c "curl -d …"`) — необратимая.
   Тест — задача 2.
5. Посетитель пишет второй раз до решения владельца — вторая карточка не уходит. Тест — задача 6.

---

### Task 1: Схема и хранилища

- Миграция `0004_approvals.sql`: `principals.role` допускает `visitor`; `principals.status text NOT NULL DEFAULT
  'active' CHECK (status IN ('active','pending','rejected'))`; таблица `approvals` (id bigserial, run_id, call_id,
  principal_id — кто просил, tool, summary — показ целиком с масками, grant_key null, status `pending|approved|denied|expired`,
  reason, decided_by, card_chat_id, card_message_id, created_at, expires_at, decided_at); `approval_grants`
  (id, principal_id, key, created_at, revoked_at; уникальность активного ключа на участника).
- `ApprovalRepository`: `create(...)`, `find(id)`, `pendingFor(runId)`, `decide(id, status, reason, decidedBy): ?Approval`
  — `UPDATE … WHERE status='pending' RETURNING` (атомарно), `expired(now): list<Approval>`, `setCard(id, chat, message)`.
  `GrantRepository`: `has(principalId, key)`, `add(principalId, key)`, `active(principalId)`, `revoke(id)`.
  Postgres + в памяти + общий контракт (как `RunStoreContract`).
- `PrincipalRepository`: `createVisitor(channel, externalId, displayName): Principal` (role visitor, status pending),
  `setRoleAndStatus(id, role, status)`; `Principal` получает `status`.

### Task 2: Классификация действий наружу

- `CommandClassifier::classify(string $command): Classification{danger, key: ?string, label}` — нормализация как в
  hermes (`approval_detection.py` `_command_detection_variants`): снять кавычки и экранирование, склеить `pu""sh`;
  разбить по `;`, `&&`, `||`, `|`, переводам строк; необратимое: `git push [remote]` (ключ `git-push:<remote или url>`),
  `ssh|scp|rsync|sftp <host>` (ключ `<tool>:<host>`), `curl`/`wget` с отправкой (`-d`, `--data*`, `-F`, `-T`,
  `--upload-file`, `-X POST|PUT|PATCH|DELETE`, `--post-data`, `--post-file`, `--method`) (ключ `upload:<host>`),
  «скачал — запустил» (`curl … | sh|bash|python`, ключа нет), неразбираемое (`$(`, обратные кавычки, `eval`,
  `bash|sh -c`, `python -c`, `node -e`, `php -r`, `perl -e`) — без ключа. Остальное — `write`.
- `RunCommandTool::danger()` и новый `RunCommandTool::grantKey()` — из классификатора; `HttpRequestTool` получает
  методы `POST|PUT|PATCH|DELETE` → `irreversible`, ключ `http:<METHOD> <host>`.
- Интерфейс `Tool` получает `grantKey(array $args): ?string` (по умолчанию в базовых — null).
- `SecretMasker::mask(string): string` — токены вида `sk-…`, `ghp_…`, `xox[bp]-…`, `AKIA…`, `Bearer …`,
  `password=…`/`token=…`, длинные (32+) hex/base64 в аргументах → `***`.

### Task 3: Шлюз в цикле

- `AgentLoop`: перед исполнением отрезка, если вызов `Irreversible`:
  - ключ есть и `GrantRepository::has(principal, key)` → исполнить, в результат приписать «по сохранённому разрешению»;
  - `policy.mayRequestApproval` = false или некому ответить (`RunState::attended` = false) → результат-отказ
    «Действие требует подтверждения владельца, а спросить некого.»;
  - иначе: `ApprovalGate::request(run, call, principal)` создаёт ожидание и карточку; вызов → `awaiting_approval`;
    последующие вызовы ответа остаются `pending`; запуск → `waiting_approval`; `RunObserver::waiting()` — статус
    «Жду подтверждения владельца…»; цикл возвращает запуск без `finish`.
- `resumePending`: вызов `awaiting_approval` с решением `approved` → исполнить; `denied`/`expired` → результат
  «Отказано владельцем: <причина>» / «Молчание — не согласие: …».

### Task 4: ApprovalService и карточка

- `ApprovalService implements CallbackHandler`: `request()` (карточка владельцу: «Подтвердите действие», кто
  просил, инструмент, аргументы целиком с масками; длинный показ — файлом через `sendFile` и подпись), `decide()`:
  атомарное решение → `always` добавляет разрешение → правка карточки (без кнопок, «Разрешено/Отказано, <время>») →
  `RunScheduler::resumeRun(runId)`; повторное нажатие — «уже решено»; чужое нажатие — отказ-уведомление.
- `expireDue()` по таймеру (раз в 30 с, `AgentModule`) — просроченные → `expired` + продолжение запуска.
- `RunScheduler::submit()`: запуск диалога в `waiting_approval` и текст владельца → `decide(deny, reason: текст)` и
  продолжение; текст не становится дописью.
- `RunScheduler::resumeRun(int $runId)`: статус → `running`, запуск в своём волокне (как `resume`).

### Task 5: Устойчивость ожидания

- `resumeAll()` не трогает `waiting_approval`; после старта нажатие продолжает такой запуск; просроченные за время
  простоя закрываются первым `expireDue()`.

### Task 6: Посетитель и /permissions

- `AccessService::resolve()`: неизвестный → `createVisitor` (status `pending`) + карточка владельцу
  (`pv:<id>:t|u|a|r`); пока `pending` — ответ «Передал владельцу, он скоро ответит.» без модели; `rejected` —
  отказ; повторное сообщение до решения карточку не шлёт. Решение: `t` → role visitor, active; `u`/`a` → role
  user/admin, active; `r` → rejected.
- Политика посетителя — разговор без инструментов (`PolicyService`: role visitor → tools []).
- `/permissions` (только владелец): список активных разрешений с кнопками «Отозвать» (`pg:<id>:x`).

### Task 7: Сборка и проверка

- `index.php`: `ApprovalService` как `CallbackHandler` диспетчера, таймер в `AgentModule`, webhook с
  `allowed_updates: ['message', 'callback_query']`.
- Итоговое ревью, исправления, развёртывание (`git submodule update` для messenger), проверка в Telegram:
  `git push` в песочнице → карточка → «Отказать» → модель объясняет; повтор → «Разрешать всегда» → следующий push без
  вопроса; `/permissions` → отзыв; перезапуск контейнера во время ожидания → нажатие продолжает; молчание (временно
  `AGENT_APPROVAL_TIMEOUT=60`) → отказ; текст во время ожидания → отказ с причиной.
- Спецификация: «Решено в фазе 3 (часть 3)», отметка в `ROADMAP.md`, план удалить.
