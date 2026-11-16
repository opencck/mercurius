---
title: "hermes-agent — память, скиллы, самоулучшение, планировщик"
type: repository
source: https://github.com/NousResearch/hermes-agent
local: references/hermes-agent/
commit: e96221fa
reviewed: 2026-11-16
---

# hermes-agent — память, скиллы, самоулучшение, планировщик

Общий конспект репозитория — [notes/hermes-agent.md](hermes-agent.md). Здесь — только подсистемы памяти, скиллов,
самоулучшения, планировщика и плагинов; ядро/цикл и шлюз/безопасность разобраны в соседних конспектах.

Формат ссылок: `references/hermes-agent/<путь>@e96221fa:L10-L40 (имя)`. Пометка **(вывод)** — мой вывод, а не
утверждение из репозитория.

## 1. Кратко

Память hermes двухслойная: маленькая курируемая память в двух файлах (`MEMORY.md` — заметки агента, `USER.md` —
профиль пользователя; жёсткий лимит в символах, целиком в системном промпте как замороженный снимок) и неограниченный
журнал всех разговоров в SQLite с полнотекстовым поиском (`session_search`, без LLM). Всё более умное — графы, векторы,
вывод фактов, «сновидения» — вынесено за контракт `MemoryProvider`: ровно один внешний провайдер работает рядом со
встроенной памятью и получает хуки на начало хода (prefetch), конец хода (sync), сжатие контекста и границу сессии.
Процедурные знания хранятся отдельно — в скиллах (`SKILL.md` + файлы): в промпте постоянно только индекс «имя —
описание», тело грузится по требованию. Самоулучшение — это фоновый форк агента после хода, который по счётчикам
(каждые N ходов / N итераций с инструментами) перечитывает разговор и через те же инструменты `memory` и
`skill_manage` дописывает память и скиллы; плюс «куратор», который по простою архивирует неиспользуемые скиллы.
Планировщик — задания в `jobs.json`, тик раз в 60 с, каждое задание в свежей сессии с урезанным набором инструментов,
результат доставляется системой, а не агентом. Главный инвариант, определяющий почти все решения, — не ломать кэш
префикса промпта: ничего в системном промпте не меняется посреди разговора.

## 2. Встроенная память

**Что хранится.** Два текстовых файла в `<HERMES_HOME>/memories/`: `MEMORY.md` — заметки агента об окружении
(факты о системе, соглашения, особенности инструментов), `USER.md` — профиль пользователя (кто он, предпочтения,
стиль). Каждый файл — список записей, разделённых строкой `\n§\n`; запись может быть многострочной.
`references/hermes-agent/tools/memory_tool_store.py@e96221fa:L23-L26 (MEMORY_BLOCK_HEADERS, ENTRY_DELIMITER)`.

**Лимиты.** В символах, а не в токенах (не зависят от модели): 2200 для `MEMORY.md`, 1375 для `USER.md`;
настраиваются `memory.memory_char_limit` / `memory.user_char_limit`. Авто-сжатия нет: запись сверх лимита
возвращает ошибку со списком текущих записей и требованием прислать один пакет `operations`, который и освобождает
место, и добавляет новое; лимит проверяется только по итогу пакета.
`references/hermes-agent/tools/memory_tool_store.py@e96221fa:L177-L194 (MemoryStore.__init__)`,
`references/hermes-agent/tools/memory_tool_store.py@e96221fa:L403-L419 (MemoryStore.add)`,
`references/hermes-agent/website/docs/user-guide/features/memory.md@e96221fa:L176-L203`.

**Как попадает в промпт.** При старте сессии файлы читаются, дедуплицируются и рендерятся в «замороженный снимок»:
шапка `MEMORY (your personal notes) [67% — 1,474/2,200 chars]` + записи через `§`. Снимок в течение сессии не
меняется — запись в память сразу попадает на диск, но в системный промпт попадёт только в следующей сессии. Причина —
сохранить префикс-кэш промпта. Блоки памяти стоят в «волатильном» хвосте системного промпта (после индекса скиллов,
перед блоками плагинов и строкой времени).
`references/hermes-agent/tools/memory_tool_store.py@e96221fa:L223-L259 (load_from_disk)`,
`references/hermes-agent/tools/memory_tool_store.py@e96221fa:L675-L682 (_render_block)`,
`references/hermes-agent/agent/system_prompt.py@e96221fa:L535-L560 (_memory_parts)`,
`references/hermes-agent/agent/system_prompt.py@e96221fa:L751-L814 (build_system_prompt_parts)`.

**Кто и когда обновляет.** Только модель через один инструмент `memory` (тулсет `memory`): параметры `target`
(`memory` | `user`) и `operations[]` с действиями `add` / `patch` / `remove`. Действия `read` нет — память уже в
промпте. `patch` и `remove` находят запись по уникальной подстроке `old_text`; `patch` меняет только этот фрагмент.
Действие `replace` убрано: модели затирали им многофактовые записи. Успешный ответ намеренно не содержит списка
записей («Write saved… do not repeat it»), чтобы модель не начинала «чинить дальше».
`references/hermes-agent/tools/memory_tool.py@e96221fa:L373-L439 (MEMORY_SCHEMA)`,
`references/hermes-agent/tools/memory_tool.py@e96221fa:L222-L238 (memory_tool)`,
`references/hermes-agent/tools/memory_tool_store.py@e96221fa:L660-L673 (_success_response)`.

Пишут два источника: основной ход (foreground) и фоновый разбор после хода (раздел 7). Описание инструмента задаёт
границу: в память — только то, что относится к каждой сессии; процедуры и предпочтения по конкретному виду работ — в
скилл; журнал выполненного — не в память, а в поиск по сессиям.

**Защита от порчи.**

- Каждая мутация: файловая блокировка → перечитать файл с диска → применить → атомарная запись (temp + rename).
  `references/hermes-agent/tools/memory_tool_store.py@e96221fa:L353-L401 (_mutate)`.
- Нечитаемый файл (ошибка декодирования) — отказ писать, а не перезапись пустым.
- «Дрейф»: если в файле есть запись длиннее всего лимита (кто-то дописал текст без разделителей) — запись
  отклоняется и делается снимок `.bak.<ts>`; разрешены только чистые удаления.
  `references/hermes-agent/tools/memory_tool_store.py@e96221fa:L51-L63 (_drift_error)`.
- Не более 3 неудачных попыток консолидации за ход, дальше терминальный ответ «оставь память, отвечай пользователю».
  `references/hermes-agent/tools/memory_tool_store.py@e96221fa:L206-L221 (_consolidation_failure)`.
- Точные дубликаты не добавляются.

**Защита от инъекций.** Память входит в системный промпт, поэтому содержимое сканируется набором регулярных
выражений в самом строгом режиме `strict`: классические инъекции («ignore previous instructions»), перехват роли,
C2-лексика, эксфильтрация (`curl … $TOKEN`, `cat .env`), SSH-бэкдоры (`authorized_keys`), правка
`AGENTS.md`/`config.yaml`/`SOUL.md`, зашитые секреты, невидимые юникод-символы. Перед сканированием — NFKC и
схлопывание пробелов.
`references/hermes-agent/tools/threat_patterns.py@e96221fa:L68-L150 (_PATTERNS)`,
`references/hermes-agent/tools/threat_patterns.py@e96221fa:L196-L232 (scan_for_threats)`.

- При записи — блокировка: `references/hermes-agent/tools/memory_tool_store.py@e96221fa:L29-L32 (_scan_memory_content)`.
- При загрузке — запись, не прошедшая скан, заменяется в снимке на `[BLOCKED: …]`, а в «живом» списке остаётся,
  чтобы владелец её увидел и удалил (молча выкинуть — значит скрыть атаку).

**Контроль владельца.** `memory.write_approval: true` — ни одна запись не применяется напрямую: в интерактивном CLI
foreground-запись спрашивает подтверждение, во всех остальных случаях (мессенджеры, фоновый разбор) запись
складывается в `<HERMES_HOME>/pending/memory/<id>.json` и ждёт `/memory approve|reject`. По умолчанию — `false`
(пишет свободно). При постановке `patch`/`remove` в очередь фиксируется полный текст целевой записи; если запись с
тех пор изменилась — применение отклоняется. Независимо от флага: фоновый разбор без участия человека **не может**
удалять и править записи — такие операции всегда уходят в очередь, ему доступен только `add`.
`references/hermes-agent/tools/write_approval.py@e96221fa:L204-L219 (evaluate_gate)`,
`references/hermes-agent/tools/memory_tool.py@e96221fa:L84-L102 (_gate_or_stage)`,
`references/hermes-agent/tools/memory_tool.py@e96221fa:L179-L219 (_background_delete_gate)`,
`references/hermes-agent/tools/memory_tool.py@e96221fa:L353-L370 (apply_memory_pending)`.

Важная оговорка из документации: шлюз одобрения — не блокировка файлов. Общие файловые инструменты и терминал в
основном ходе могут править `MEMORY.md` напрямую, минуя шлюз
(`references/hermes-agent/tools/write_approval.py@e96221fa:L1-L13`).

**Про границы сессий.** Вся схема работает на границе сессии: в мессенджере чат — одна бесконечная сессия, и если
не делать `/new`, новые записи памяти так и не попадут в промпт.
`references/hermes-agent/website/docs/user-guide/features/memory.md@e96221fa:L59-L65`.

## 3. Интерфейс провайдера памяти

Абстрактный класс `MemoryProvider`:
`references/hermes-agent/agent/memory_provider.py@e96221fa:L84-L267 (MemoryProvider)`. Оркестратор —
`MemoryManager`: `references/hermes-agent/agent/memory_manager.py@e96221fa:L200-L243`.

**Сколько провайдеров.** Одновременно активен ровно **один внешний** провайдер, выбранный `memory.provider` в
конфиге; второй отклоняется с предупреждением. Он работает **рядом** со встроенной памятью, не заменяя её.
`references/hermes-agent/agent/memory_manager.py@e96221fa:L284-L294 (add_provider)`.
Встроенная память в этом коммите — **не** провайдер: это `MemoryStore` + инструмент `memory`, а `MemoryManager`
создаётся только когда `memory.provider` называет внешний плагин. Упоминания провайдера `builtin` в коде менеджера —
след прежнего устройства **(вывод по `_init_memory`)**:
`references/hermes-agent/agent/agent_init.py@e96221fa:L1154-L1230 (_init_memory)`.

### Методы контракта

| Метод | Обязателен | Когда вызывается | Что делает |
|-------|-----------|------------------|------------|
| `name` (property) | да | всегда | короткий идентификатор |
| `is_available() -> bool` | да | при сборке агента, до активации | проверка конфига/зависимостей, **без сети** |
| `initialize(session_id, **kwargs)` | да | один раз при сборке агента | соединения, ресурсы, фоновые потоки. kwargs: `hermes_home`, `platform`, `agent_context` (`primary`/`subagent`/`cron`/`flush`), `agent_identity`, `parent_session_id`, `user_id`, `user_name`, `chat_id`, `gateway_session_key`, `resumed`, `message_count`, `cwd`, `session_title` |
| `system_prompt_block() -> str` | нет | при сборке системного промпта (раз на сессию) | **статичный** текст о провайдере; вспомненное сюда не кладут |
| `on_turn_start(turn_number, message, **kwargs)` | нет | начало каждого хода, до prefetch | тик; kwargs: `model`, `author_id`, `author_name`, `author_is_bot`, `previous_message`, `remaining_tokens`… — передаются только те, что есть в сигнатуре |
| `prefetch(query, *, session_id) -> str` | нет | один раз перед циклом инструментов хода | вернуть вспомненный контекст; должен быть быстрым (отдать кэш фонового recall) |
| `recall_status() -> RecallStatus\|None` | нет | сразу после prefetch | что было вставлено — для строки-индикатора пользователю |
| `sync_turn(user, assistant, *, session_id, messages=None, turn_author=None)` | нет | после завершённого хода, в фоне | сохранить ход; не блокировать |
| `queue_prefetch(query, *, session_id)` | нет | после хода, в фоне | подготовить recall к следующему ходу |
| `get_tool_schemas() -> list` | да | при регистрации и при сборке tools[] | схемы инструментов провайдера (OpenAI function calling) |
| `handle_tool_call(tool_name, args, **kwargs) -> str` | если есть инструменты | когда модель вызвала инструмент провайдера | вернуть JSON-строку |
| `on_pre_compress(messages) -> str` | нет | перед сжатием контекста | извлечь важное до потери; возврат подмешивается в промпт суммаризации |
| `on_memory_write(action, target, content, metadata=None)` | нет | после **успешной** записи встроенным инструментом `memory` | зеркалировать в свой бэкенд; `patch` приходит как `replace` с полным новым текстом, `metadata.previous_content` — прежняя запись |
| `on_delegation(task, result, *, child_session_id, **kwargs)` | нет | у родителя, когда субагент завершил задачу | наблюдение за делегированием |
| `on_session_end(messages)` | нет | только на настоящей границе сессии (`/new`, выход, ротация) | финальное извлечение |
| `on_session_switch(new_session_id, *, parent_session_id, reset, rewound, **kwargs)` | нет | `session_id` сменился без пересоздания (`/resume`, `/branch`, `/new`, сжатие, `/undo`) | перепривязать состояние |
| `shutdown()` | нет | остановка процесса / вытеснение агента из кэша | сбросить очереди, закрыть соединения |
| `get_config_schema()`, `save_config()`, `read_config_fields()`, `write_config_fields()` | для мастера настройки | `hermes memory setup`, панель | описание и сохранение настроек; секреты уходят в `.env` |
| `backup_paths()`, `identity_signature()`, `unavailable_reason()`, `on_profile_created()`, `on_profile_renamed()` | нет | бэкап, шлюз, профили | служебные |

### Жизненный цикл по шагам

1. **Сборка агента.** `_init_memory`: читает `memory.provider`; если это не встроенное значение — создаёт
   `MemoryManager`, находит плагин (`resolve_memory_provider`), `add_provider()` → `initialize_for_agent()` →
   `initialize()` провайдера. Затем `inject_memory_provider_tools(agent)` добавляет схемы провайдера в `tools[]`.
   Шлюз, пересобирающий агента на каждый ход, передаёт уже инициализированный менеджер, чтобы провайдер не терял
   состояние. `references/hermes-agent/agent/agent_init.py@e96221fa:L1154-L1230`,
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L950-L963 (initialize_all)`.
2. **Регистрация инструментов.** `add_provider` сначала читает схемы, потом меняет состояние. Имена, совпадающие с
   инструментами ядра, отбрасываются («ядро всегда побеждает»); конфликт имён между провайдерами — тоже. Вызовы
   идут через таблицу `tool_name → provider`.
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L296-L342`,
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L610-L632 (handle_tool_call)`.
3. **Системный промпт.** `system_prompt_block()` — один раз, вместе с блоками встроенной памяти.
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L351-L355 (build_system_prompt)`.
4. **Начало хода.** `on_turn_start` → если сообщение не тривиальное («ok», «спасибо», слэш-команда) —
   `prefetch_all(query)`; затем `describe_recall()` выводит пользователю строку вида «🧠 Provider — recalled 3
   memories». `references/hermes-agent/agent/turn_context.py@e96221fa:L1060-L1090 (_memory_turn_start_and_prefetch)`,
   `references/hermes-agent/agent/memory_provider.py@e96221fa:L66-L81 (TRIVIAL_PROMPT_RE, is_trivial_prompt)`.
5. **Вставка вспомненного.** Результат prefetch **не идёт в системный промпт**: он оборачивается в
   `<memory-context>` с пометкой «это вспомненный контекст, НЕ новый ввод пользователя» и приклеивается к сообщению
   пользователя текущего хода (кэш префикса цел). Теги и пометки вычищаются из ответа провайдера (чтобы он не мог
   подделать обёртку) и из ответа модели; повторяющиеся пункты внутри блока удаляются.
   `references/hermes-agent/agent/memory_context.py@e96221fa:L183-L199 (build_memory_context_block)`,
   `references/hermes-agent/agent/memory_context.py@e96221fa:L23-L27 (sanitize_context)`,
   `references/hermes-agent/agent/turn_context.py@e96221fa:L114-L122 (compose_multimodal_context_part)`.
6. **Таймаут prefetch.** Внешний prefetch идёт в отдельном потоке с ожиданием 8 с; не успел — ход продолжается без
   памяти, а «застрявший» провайдер на следующих ходах пропускается.
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L374-L424 (_prefetch_provider)`,
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L41-L57 (таймауты)`.
7. **Конец хода.** `sync_all()` и `queue_prefetch_all()` ставятся в **один** фоновый поток-исполнитель менеджера:
   это не задерживает ответ и сериализует записи (ход N раньше N+1). Прерванный ход не синхронизируется — частичный
   вывод не считается правдой. `references/hermes-agent/run_agent.py@e96221fa:L902-L933 (_sync_external_memory_for_turn)`,
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L460-L493 (sync_all)`,
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L495-L532 (_submit_background)`.
8. **Запись встроенной памяти.** После успешного (не отложенного в очередь) вызова `memory` —
   `notify_memory_tool_write` → `on_memory_write` для каждой операции пакета.
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L809-L858`.
9. **Сжатие контекста.** `on_pre_compress(messages)`; возврат идёт в промпт суммаризации. Есть версия контракта v2
   (`pre_compress_checkpoint_api_version = 2`): провайдер-архиватор гарантирует, что успешный возврат = данные
   сохранены; при `compression.checkpoint_required: true` сжатие без такого чекпойнта отменяется (fail-closed), а
   провайдеру дают очищенный список (только текст user/assistant). После сжатия, если сменился `session_id`, —
   `on_session_switch`. `references/hermes-agent/agent/memory_manager.py@e96221fa:L720-L764 (on_pre_compress)`.
10. **Граница сессии.** `/new`: `on_session_end(старые сообщения)` и `on_session_switch(reset=True)` ставятся
    **одной** задачей в тот же фоновый поток — извлечение строго раньше перепривязки и не блокирует команду.
    `references/hermes-agent/agent/memory_manager.py@e96221fa:L660-L691 (commit_session_boundary_async)`.
11. **Завершение.** `shutdown_memory_provider`: `on_session_end` → `shutdown_all()` (ограниченное 5 с ожидание очереди,
    затем `shutdown()` провайдеров в обратном порядке). Защита от двойного вызова — `claim_teardown`.
    `references/hermes-agent/run_agent.py@e96221fa:L881-L893`,
    `references/hermes-agent/agent/memory_manager.py@e96221fa:L869-L935`.

### Гарантии менеджера, на которые опирается плагин

- Исключение в любом хуке логируется и проглатывается — провайдер не может уронить ход.
  `references/hermes-agent/agent/memory_manager.py@e96221fa:L245-L255 (_each_provider)`.
- Всё, что уходит провайдеру (запрос, тексты хода, транскрипт, аргументы инструментов), проходит редактирование
  секретов. `references/hermes-agent/agent/memory_manager.py@e96221fa:L100-L143 (_redact_for_provider)`.
- Из запроса вырезается тело скилла: при `/skill …` провайдер получает только инструкцию пользователя.
- Для форков (`agent_context="subagent"`) хуки хода выключены на стороне хоста: нет prefetch, sync, on_turn_start,
  on_session_end, on_delegation; остаются `initialize`, `on_session_switch`, `on_pre_compress`.
  `references/hermes-agent/agent/memory_manager.py@e96221fa:L262-L267`.
- Обратная совместимость по сигнатурам: менеджер смотрит сигнатуру метода и передаёт только принимаемые аргументы.
- Один экземпляр провайдера — один профиль; фоновые потоки провайдера обязаны наследовать контекст вызывающего
  (`spawn_context_thread`). `references/hermes-agent/agent/memory_provider.py@e96221fa:L21-L33`.

### Упаковка провайдера

Каталог с `__init__.py` (класс + `register(ctx)`, вызывающий `ctx.register_memory_provider(...)`), `plugin.yaml`
(name, version, description, hooks), опционально `cli.py` (`register_cli(subparser)`) и `config_schema.py`.
Источники в порядке приоритета: встроенные `plugins/memory/<name>/` → пользовательские
`$HERMES_HOME/plugins/<name>/` → проектные `./.hermes/plugins/` → entry point `hermes_agent.memory_providers`.
Для провайдеров памяти побеждает **более ранний** источник (обратно общей системе плагинов), чтобы подложенный
каталог не подменил память. Обнаружение только перечисляет — ничего не импортируется, пока имя не выбрано в конфиге.
Провайдер может через `ctx.register_skill` поставить свои скиллы только для чтения.
`references/hermes-agent/website/docs/developer-guide/memory-provider-plugin.md@e96221fa:L15-L36`,
`references/hermes-agent/website/docs/developer-guide/memory-provider-plugin.md@e96221fa:L444-L481`.
В самом репозитории встроенных провайдеров два — `byterover` и `retaindb` (`plugins/memory/`); Honcho, Hindsight,
Mem0, Supermemory, Holographic ставятся из каталога плагинов.

## 4. Поиск по прошлым сессиям

Инструмент `session_search` — поиск по полному журналу всех разговоров в SQLite (`state.db`) с полнотекстовым
индексом FTS5 (обычный + триграммный). **Без вызовов LLM**: возвращаются настоящие сообщения из БД. Один инструмент,
режим выбирается по аргументам:

- `query` — поиск: топ-N сессий, дедупликация по «родословной» сессии (сжатие и ветвление порождают цепочки
  сессий), полностью раскрывается только лучший результат;
- `session_id` + `around_message_id` — прокрутка: окно ±N сообщений вокруг найденного;
- только `session_id` — чтение сессии (начало/конец, с ограничением 2000 символов на сообщение);
- без аргументов — список недавних сессий.

`references/hermes-agent/tools/session_search_tool.py@e96221fa:L1-L9 (docstring модуля)`,
`references/hermes-agent/tools/session_search_tool.py@e96221fa:L816-L832 (SESSION_SEARCH_SCHEMA)`,
`references/hermes-agent/hermes_state_fts.py@e96221fa:L16-L60 (таблицы messages_fts, messages_fts_trigram)`.

Детали, полезные при проектировании:

- Сессии субагентов, kanban и интеграций скрыты из поиска; сессии cron — **понижены** в ранжировании, а не
  исключены: их повторяющаяся лексика при голом BM25 вытесняла разговоры самого пользователя.
  `references/hermes-agent/tools/session_search_tool.py@e96221fa:L24-L33`.
- Выдача редактируется от секретов; base64-данные заменяются заглушкой.
- В системный промпт добавляется одна фраза-подсказка, только если инструмент доступен: «если пользователь ссылается
  на прошлый разговор — сначала поищи, не проси повторить».
  `references/hermes-agent/agent/prompt_builder.py@e96221fa:L240-L243 (SESSION_SEARCH_GUIDANCE)`.

**Отличие от памяти** (таблица из документации,
`references/hermes-agent/website/docs/user-guide/features/memory.md@e96221fa:L250-L261`):

| | Память (MEMORY/USER) | Поиск по сессиям |
|---|---|---|
| Объём | ~1300 токенов | все сессии |
| Стоимость | в каждом промпте | по требованию, без LLM |
| Наполнение | курирует агент | автоматически, всё подряд |
| Для чего | факты, нужные всегда | «что мы решили про X на прошлой неделе» |

README упоминает «FTS5 session search with LLM summarization» (`references/hermes-agent/README.md@e96221fa:L26`), но
код инструмента в этом коммите суммаризацию не делает — расхождение README и кода **(вывод)**.

## 5. Моделирование пользователя

В ядре отдельной подсистемы нет. Есть три слоя:

1. **`USER.md`** — профиль, который агент ведёт сам через `memory(target="user")` (раздел 2). Правило из промпта
   разбора: записывать только факты, **подтверждённые** сообщением пользователя или результатом инструмента; не
   выводить черты, роль, настроение из темы и тона; разовую просьбу не превращать в постоянное предпочтение, если
   пользователь не сказал «всегда»/«запомни».
   `references/hermes-agent/agent/background_review.py@e96221fa:L446-L465 (_MEMORY_ROUTING_BLOCK)`.
2. **Предпочтения по виду работ** — не в профиле, а в скилле, который этой работой управляет (раздел 6–7).
3. **Внешний провайдер.** «Диалектическое моделирование пользователя» из README — это плагин Honcho из каталога, не
   код ядра: после хода сервер Honcho выводит заключения о пользователе, а базовый контекст (представление о
   пользователе, сводка сессии) подмешивается через `prefetch`.
   `references/hermes-agent/website/docs/user-guide/features/honcho.md@e96221fa:L9-L31`.

Личность самого агента — файл `SOUL.md` в `HERMES_HOME`, первый блок системного промпта; его правит владелец
(`references/hermes-agent/website/docs/user-guide/features/personality.md@e96221fa:L9-L14`). Сканер угроз считает
попытку правки `SOUL.md`/`config.yaml` из памяти или скилла признаком атаки (`hermes_config_mod`,
`references/hermes-agent/tools/threat_patterns.py@e96221fa:L136-L137`).

## 6. Скиллы

### Формат

Скилл — каталог с обязательным `SKILL.md` (YAML-frontmatter + Markdown) и необязательными подкаталогами
`references/` (глубина по теме), `templates/` (заготовки), `scripts/` (запускаемые помощники), `assets/`.

Frontmatter: `name`, `description`, `version`, `author`, `license`, `platforms` (фильтр по ОС),
`metadata.hermes.tags`, `.category`, `.related_skills`, `.config` (настройки, которые скилл просит в `config.yaml`),
условия показа `requires_toolsets` / `requires_tools` / `fallback_for_toolsets` / `fallback_for_tools`, а также
`required_environment_variables` (секреты запрашиваются только при загрузке скилла в локальном CLI; в мессенджере
секрет в чате не спрашивают никогда).
`references/hermes-agent/skills/AGENTS.md@e96221fa:L16-L22`,
`references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L194-L229`,
`references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L277-L315`.

Стандарт оформления: описание ≤ 60 символов, одно предложение (ровно столько показывает индекс; `skill_manage`
отклоняет длиннее); порядок разделов `When to Use → Prerequisites → How to Run → Quick Reference → Procedure →
Pitfalls → Verification`; ~100–200 строк; логику не писать в тексте, а выносить в `scripts/`.
`references/hermes-agent/skills/AGENTS.md@e96221fa:L24-L56`.

Лимиты записи: имя ≤ 64 символа, `^[a-z0-9][a-z0-9._-]*$`; `SKILL.md` ≤ 100 000 символов; вспомогательный файл ≤ 1 МиБ;
писать можно только в `references|templates|scripts|assets`.
`references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L122-L127`.

### Где лежат

Единый источник истины — `<HERMES_HOME>/skills/<категория>/<скилл>/`. Встроенные скиллы из `skills/` репозитория
**копируются** туда при установке (манифест `.bundled_manifest`); `optional-skills/` поставляются, но не активны, пока
их не установят (`hermes skills install official/...`). Порядок поиска при совпадении имён: проектные
(`<repo>/.hermes/skills/`, только после `hermes skills trust`) → локальные → `skills.create_dir` → `external_dirs`.
Служебные файлы в корне: `.usage.json` (счётчики, pinned/locked), `.archive/`, `.hub/lock.json` (происхождение и
хеши установленных), `.curator_ledger.jsonl`.
`references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L335-L365`,
`references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L467-L519`.

### Как попадают в контекст (прогрессивное раскрытие)

- **Постоянно** — только индекс в системном промпте: блок `## Skills` с жёсткой инструкцией «перед ответом просмотри
  список; если скилл хоть частично подходит — ОБЯЗАН загрузить через `skill_view(name)`», затем
  `<available_skills>` — строки `- имя: описание`, сгруппированные по категориям. Скиллы, не подходящие по ОС,
  отключённые и не прошедшие условия по инструментам, скрыты. Категории вне текущего контекста сворачиваются в
  строку «только имена», но **никогда не выкидываются**.
  `references/hermes-agent/agent/prompt_builder.py@e96221fa:L1303-L1368 (_render_skills_index)`,
  `references/hermes-agent/agent/prompt_builder.py@e96221fa:L1240-L1273 (build_skills_system_prompt)`.
- **По требованию** — `skill_view(name)` возвращает весь `SKILL.md` (без пагинации: скилл читается целиком),
  `skill_view(name, file_path)` — отдельный файл. Повторный просмотр без изменений в той же сессии возвращает
  короткую заглушку. Просмотр считается использованием (счётчики `view_count`/`use_count`).
  `references/hermes-agent/tools/skills_tool.py@e96221fa:L634-L650 (skill_view)`,
  `references/hermes-agent/tools/skills_tool.py@e96221fa:L779-L800 (_skill_view_with_bump)`.
- **Явно** — слэш-команда `/имя-скилла` вставляет тело скилла в сообщение пользователя; скиллы можно закрепить на
  агенте (auto-load, стабильная часть промпта).
- **Изменения посреди разговора.** Индекс в системном промпте не перестраивается (кэш). Вместо этого к следующему
  сообщению пользователя один раз приклеивается заметка «появились/исчезли такие-то скиллы»; сам промпт обновится
  при следующем сжатии.
  `references/hermes-agent/agent/skills_index_delta.py@e96221fa:L102-L137 (stage_skills_index_note)`.

### Инструменты

| Инструмент | Что делает | Где |
|------------|-----------|-----|
| `skills_list(category?)` | имена, описания, категории | `references/hermes-agent/tools/skills_tool.py@e96221fa:L305` |
| `skill_view(name, file_path?)` | тело скилла или файл | `references/hermes-agent/tools/skills_tool.py@e96221fa:L634` |
| `skill_manage(operations[])` | create / patch / write_file / remove_file / delete; пакет атомарен — при любой ошибке откат всех затронутых скиллов | `references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L805-L854` |

### Создание и правка скиллов самим агентом

Скиллы названы «процедурной памятью». В системном промпте — одна фраза («вывел нетривиальный рабочий процесс —
запиши через skill_manage») плюс инструкции в блоке `## Skills` («если скилл с ошибкой — исправь patch'ем»).
`references/hermes-agent/agent/prompt_builder.py@e96221fa:L261 (SKILLS_GUIDANCE)`,
`references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L859-L873 (_skill_manage_description)`.

Что происходит при записи (`skill_manage`): проверка блокировки/закрепления → шлюз одобрения → проверка формы и
имени → файловый замок на скилл → снимок «до» в журнал → действие → сканирование → запись в журнал и счётчики.
К результату прикладываются замечания линтера (не блокируют): «тело похоже на журнал инцидентов», больше 60 файлов
в `references/`, тело больше ~24 000 символов.
`references/hermes-agent/tools/skill_linter.py@e96221fa:L46-L56`.

Происхождение записи хранится в `ContextVar`: `foreground` или `background_review`; отдельно — «с участием человека»
(`/refine`) или нет. От этого зависят шлюзы и то, считает ли куратор скилл «своим».
`references/hermes-agent/tools/skill_provenance.py@e96221fa:L1-L44`.

### Защита

1. **Сканер** (`skills_guard`, версия `skills-guard-v16`): ~390 строк регулярных выражений — эксфильтрация, инъекции,
   разрушительные команды, признаки атаки на цепочку поставок, невидимые символы, обход путей, подозрительные
   бинарные файлы; итог — вердикт `safe` / `caution` / `dangerous`.
   `references/hermes-agent/tools/skills_guard.py@e96221fa:L172-L557 (THREAT_PATTERNS)`.
2. **Матрица «доверие × вердикт»:**

   | Уровень доверия | safe | caution | dangerous |
   |-----------------|------|---------|-----------|
   | `builtin` (поставка) | да | да | да |
   | `trusted` (список репозиториев: `openai/skills`, `anthropics/skills`…) | да | да | блок |
   | `community` (всё остальное) | да | блок | блок |
   | `agent-created` | да | да | «ask» = ошибка агенту |

   `--force` обходит блок `caution`, но не `dangerous`.
   `references/hermes-agent/tools/skills_guard.py@e96221fa:L29-L42 (TRUSTED_REPOS, INSTALL_POLICY)`,
   `references/hermes-agent/tools/skills_guard.py@e96221fa:L988-L1002 (should_allow_install)`.
3. **Скиллы, созданные агентом.** В основном ходе сканирование выключено по умолчанию
   (`skills.guard_agent_created: false`; обоснование в коде — терминал всё равно исполняет то же без проверки), но
   **всегда включено** для фонового разбора. Учитываются только находки, которых не было до правки.
   `references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L44-L79`.
4. **Проектные скиллы** не грузятся из произвольного клона: нужен явный `hermes skills trust`; даже после этого каждый
   скилл сканируется и при вердикте `dangerous` попадает в карантин (кэш по хешу содержимого). Cron и API никогда не
   спрашивают и не доверяют сами.
5. **Шлюз одобрения** `skills.write_approval: true` — любая запись `skill_manage` (из любого источника) откладывается
   в `<HERMES_HOME>/pending/skills/<id>.json`; владелец смотрит краткую суть и diff: `/skills pending|diff|approve|
   reject`. По умолчанию выключен.
   `references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L668-L703`.
6. **Pin** — нельзя удалить/архивировать (править можно). **Lock** — агент не может менять скилл **никаким** способом:
   `skill_manage`, файловые инструменты и терминал (разбор команды: перенаправления, `cp/mv/rm`, `sed -i`, `git`,
   `python -c`…) отказывают; агент не может и снять замок. Документация честно говорит: это проверка политики, а не
   песочница — скрипт или `execute_code` могут обойти.
   `references/hermes-agent/tools/skill_manager_guards.py@e96221fa:L226-L253 (_locked_guard)`,
   `references/hermes-agent/tools/skill_lock.py@e96221fa:L153-L168 (path_refusal)`,
   `references/hermes-agent/tools/skill_lock.py@e96221fa:L464 (command_refusal)`,
   `references/hermes-agent/website/docs/user-guide/features/curator.md@e96221fa:L344-L372`.
7. **Журнал и откат.** Каждая мутация скилла (кем бы ни сделана) — запись в `.curator_ledger.jsonl`: actor
   (`curator`/`agent`/`user`), действие, манифесты «до/после» по sha256; содержимое — в блоб-хранилище по хешу.
   `hermes curator rollback <entry-id>` откатывает одну мутацию.
   `references/hermes-agent/website/docs/user-guide/features/curator.md@e96221fa:L156-L185`.

### Каталог (хаб)

`hermes skills search|install|inspect|check|update|uninstall`. Источники-адаптеры: `official` (каталог
`optional-skills/` репозитория), `skills-sh`, `well-known` (`/.well-known/skills/index.json` на сайте), `github`
(репозиторий/путь и пользовательские «tap»), `clawhub`, `lobehub`, `browse-sh`, `url` (прямая ссылка на `SKILL.md`).
Установка: скачать в карантин (`.hub/quarantine/`) → просканировать весь пакет → применить матрицу → записать в
`.hub/lock.json` источник, хеш содержимого, версию сканера, находки. Копируются только файлы, на которые `SKILL.md`
явно ссылается. `update` пропускает скиллы, изменённые локально.
`references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L746-L756`,
`references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L904-L953`.
Код адаптеров (`tools/skills_hub*.py`) не читался — описание по документации.

Ещё есть `/learn <источник>` — агент собирает материал своими инструментами и пишет скилл по стандарту
(`references/hermes-agent/website/docs/user-guide/features/skills.md@e96221fa:L117-L142`).

## 7. Цикл самоулучшения

В hermes это три механизма: подсказки в промпте основного хода, **фоновый разбор после хода** и **куратор**.
Правки кода, системного промпта или конфигурации агент сам себе не делает — улучшается только память и скиллы.

### 7.1. Фоновый разбор (background review)

**Когда запускается.** После того как ответ доставлен пользователю, в отдельном потоке — не конкурирует с задачей.
Два независимых счётчика:

- память: каждые `memory.nudge_interval` (по умолчанию 10) **ходов пользователя**;
- скиллы: когда с прошлого разбора набралось `skills.creation_nudge_interval` (10) **итераций с вызовами
  инструментов**.

Счётчик обнуляется, если модель сама вызвала `memory` / `skill_manage` в основном ходе. Не запускается: если ответ
пуст или ход прерван, для cron (`skip_background_review`), для одноразовых запусков, при
`auxiliary.background_review.enabled: false`. Вручную — `/refine [на чём сосредоточиться]`.
`references/hermes-agent/agent/turn_context.py@e96221fa:L820-L829 (_tick_memory_nudge)`,
`references/hermes-agent/agent/review_signals.py@e96221fa:L33-L41 (skill_review_due)`,
`references/hermes-agent/agent/tool_executor.py@e96221fa:L745-L748`,
`references/hermes-agent/agent/turn_finalizer.py@e96221fa:L617-L642 (_mirror_turn_to_memory)`,
`references/hermes-agent/agent/background_review.py@e96221fa:L304-L326 (load_background_review_settings)`.

**Что запускается.** Форк того же агента (`AIAgent`) со снимком разговора и одним дополнительным сообщением
пользователя — промптом разбора. Форк отвязан от хранения: не пишет в БД сессий, не трогает внешнего провайдера
памяти. Максимум 16 итераций.
`references/hermes-agent/agent/background_review.py@e96221fa:L1055-L1065 (build_cache_parity_fork)`,
`references/hermes-agent/agent/background_review.py@e96221fa:L1265-L1326 (_run_review_fork)`.

**На какой модели.** По умолчанию — на **основной** модели, с побайтно тем же системным промптом, `tools[]` и
настройками рассуждений: полный разговор читается из тёплого кэша промпта, поэтому дёшево. Если
`auxiliary.background_review.{provider,model}` указывает другую модель — кэш всё равно холодный, и форк получает
**дайджест**: последние 24 сообщения дословно, более ранние — одна строка на реплику (первые 300/200 символов и имена
вызванных инструментов).
`references/hermes-agent/agent/background_review.py@e96221fa:L408-L438 (_digest_history)`.

**Что решает (промпты).** Три промпта — память, скиллы, оба сразу:
`references/hermes-agent/agent/background_review.py@e96221fa:L446-L656`. Суть:

- Память: два хранилища, один факт — в одно; только подтверждённые факты; «нечего сохранять» — допустимый ответ.
- Скиллы: «будь активен — большинство сессий дают хотя бы одну правку». Сигналы: пользователь поправил стиль/формат/
  подход (раздражение — сигнал первого класса); найден нетривиальный приём; загруженный скилл оказался неверным.
- Порядок предпочтения: (1) поправить скилл, который был загружен в этом разговоре → (2) поправить существующий
  «зонтичный» скилл → (3) добавить файл в `references/`/`templates/`/`scripts/` → (4) создать новый скилл **уровня
  класса задач** (имя не должно быть номером PR, текстом ошибки, «fix-X»).
- Что **не** записывать: сбои окружения (нет бинарника, не настроены ключи); негативные утверждения об инструментах
  («браузер не работает» — такие записи потом месяцами цитируются агентом против себя); разовые задачи;
  неудавшиеся попытки, оформленные как «рекомендуемый процесс».
- Форма: «уроки, а не журналы» — правило в повелительном наклонении + одно предложение «почему»; без дат, номеров
  задач, цитат; исправлять на месте, а не дописывать «UPDATE:».

**Что и куда пишет.** Только через `memory` и `skill_manage` — в те же файлы, что основной ход. Итог разбора
(«💾 Memory updated», «Skill 'foo' patched») показывается пользователю в чате; подробность —
`display.memory_notifications: off|on|verbose`. Расход токенов приписывается родительской сессии
(`task='background_review'`).
`references/hermes-agent/agent/background_review.py@e96221fa:L1337-L1420 (_run_review_in_thread)`.

**Как ограничено.**

| Ограничение | Где |
|-------------|-----|
| Белый список инструментов на стороне **исполнения** (схема `tools[]` та же ради кэша): `memory` (только если сработал счётчик памяти), скилл-инструменты, `read_file`, `search_files`; терминал и запись файлов запрещены; доп. инструменты — только из `auxiliary.background_review.extra_tools` | `references/hermes-agent/agent/background_review.py@e96221fa:L1192-L1233 (_review_tool_whitelist)` |
| Память: без участия человека только `add`; `patch`/`remove` всегда уходят в очередь на одобрение | `references/hermes-agent/tools/memory_tool.py@e96221fa:L179-L219` |
| Скиллы: «прочитай перед записью» — править можно только то, что загружено `skill_view` в этом же разборе | `references/hermes-agent/tools/skill_manager_guards.py@e96221fa:L213-L223` |
| Скиллы: удалять нельзя (кроме «осадка» самого куратора); закреплённые и запертые — неприкосновенны | `references/hermes-agent/tools/skill_manager_guards.py@e96221fa:L166-L180` |
| Сканер угроз на записи скиллов всегда включён | `references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L53-L56 (_scan_required)` |
| Опасные команды автоматически отклоняются; запрос секретов пропускается | `references/hermes-agent/agent/background_review.py@e96221fa:L1143-L1158` |
| Бюджет входных токенов на один разбор: 75% окна модели, не более 600 000; запасное значение 120 000 | `references/hermes-agent/agent/background_review.py@e96221fa:L249-L258` |
| Новый ход пользователя отменяет идущий разбор (на локальной модели разбор откладывается до простоя) | `references/hermes-agent/agent/review_idle_queue.py@e96221fa:L26-L28` |

**Как владелец контролирует.** `memory.write_approval` / `skills.write_approval` (очередь + `/memory pending`,
`/skills pending|diff|approve|reject`), уведомления в чате, `enabled: false`, интервалы счётчиков, `curator lock`
на конкретный скилл, журнал мутаций с откатом, `/journey` — просмотр, правка и удаление выученного.

### 7.2. Куратор

Фоновое обслуживание скиллов, **созданных агентом** (метка `created_by: "agent"` ставится только при записи из
фонового разбора; встроенные, установленные из хаба и написанные по просьбе пользователя — вне его ведения).
`references/hermes-agent/website/docs/user-guide/features/curator.md@e96221fa:L202-L221`.

- **Запуск** — не по cron, а по проверке простоя: с прошлого запуска прошло `interval_hours` (168 ч) и агент
  простаивает `min_idle_hours` (2 ч). Первый запуск на новой установке откладывается на целый интервал.
  `references/hermes-agent/agent/curator.py@e96221fa:L158-L170 (should_run_now)`,
  `references/hermes-agent/agent/curator.py@e96221fa:L1194 (maybe_run_curator)`.
- **Фаза 1, без LLM:** не использовался 14 дней → `stale`; 30 дней → перенос в `.archive/` (восстановимо). Закреплённые
  и упомянутые в любом cron-задании — пропускаются.
  `references/hermes-agent/agent/curator.py@e96221fa:L198-L255 (apply_automatic_transitions)`.
- **Фаза 2, LLM-консолидация — выключена по умолчанию** (`curator.consolidate`): форк агента просматривает скиллы и
  сливает пересекающиеся в «зонтичные»; перед ней — tar.gz-снимок всего дерева скиллов, `hermes curator rollback`.
  `references/hermes-agent/agent/curator.py@e96221fa:L281 (CURATOR_REVIEW_PROMPT)`,
  `references/hermes-agent/agent/curator.py@e96221fa:L920 (run_curator_review)`.
- Никогда не удаляет — максимум архив. Есть `--dry-run` и отчёт по каждому запуску.

Промпт куратора и `curator_backup.py` целиком не читались.

## 8. Планировщик (cron)

Код: `cron/jobs.py` (хранилище заданий), `cron/scheduler.py` + `cron/scheduler_*.py` (тик и запуск),
`tools/cronjob_tools.py` (инструмент агента). Прочитаны `cron/AGENTS.md`, документация и точечно — сборка промпта,
сканер, создание агента; сами `jobs.py` (3423 строки) и `scheduler.py` (4420 строк) целиком не читались.

### Формат задания

Задания лежат в `<HERMES_HOME>/cron/jobs.json` (атомарная запись), результаты запусков —
`cron/output/<job_id>/<timestamp>.md`.
`references/hermes-agent/website/docs/user-guide/features/cron.md@e96221fa:L1505-L1507`.

Расписание — четыре формы: длительность (`"30m"`, `"2h"` — одноразово), фраза (`"every 2h"`, `"every monday 9am"`),
cron из 5 полей (`"0 9 * * *"`), ISO-момент (одноразово).

Поля определения задания (`references/hermes-agent/cron/job_definition.py@e96221fa:L14-L20 (JOB_DEFINITION_FIELDS)`,
описание — `references/hermes-agent/cron/AGENTS.md@e96221fa:L8-L19`):

| Поле | Смысл |
|------|-------|
| `name`, `prompt`, `schedule` | имя, самодостаточный промпт, расписание |
| `skills` | скиллы, тела которых подставляются в промпт |
| `model`, `provider`, `base_url`, `reasoning_effort` | переопределение модели на задание |
| `enabled_toolsets` | белый список наборов инструментов задания |
| `script` | файл в `HERMES_HOME/scripts/` (не командная строка), выполняется до агента; stdout вставляется в промпт |
| `no_agent` | задание целиком — скрипт, без LLM |
| `context_from` | подставить последний вывод другого задания (цепочки) |
| `workdir` | рабочий каталог; тогда грузятся его `AGENTS.md`/`CLAUDE.md` |
| `deliver`, `failure_deliver`, `origin` | куда доставлять результат и сбой; откуда создано |
| `attach_to_session` | сделать доставку «продолжаемой» — ответ на неё попадёт в разговор с контекстом |
| `timezone`, `grace_minutes`, `catch_up` | часовой пояс задания; окно догоняния пропущенных запусков |

Управление: инструмент `cronjob(action=create|list|update|remove|run|…)` у агента, `/cron` в чате, `hermes cron …` в
CLI. `references/hermes-agent/tools/cronjob_tools.py@e96221fa:L1063 (cronjob)`,
`references/hermes-agent/tools/cronjob_tools.py@e96221fa:L1137 (CRONJOB_SCHEMA)`.

### Запуск

- Планировщик живёт внутри демона шлюза: тик раз в 60 секунд, файловая блокировка `<home>/cron/.tick.lock` против
  двойного тика из разных процессов.
  `references/hermes-agent/website/docs/user-guide/features/cron.md@e96221fa:L439-L441`,
  `references/hermes-agent/cron/scheduler_tick.py@e96221fa:L33 (tick)`.
- **Не более одного раза:** `next_run_at` сдвигается **до** запуска и в той же записи ставится метка `pending_slot`;
  если процесс умер посреди запуска, слот восстанавливается один раз, а журнал исполнений не даёт выстрелить
  повторно. `references/hermes-agent/cron/AGENTS.md@e96221fa:L38-L42`.
- **Догоняние:** окно = половина периода, в пределах 120 с – 2 ч (или `grace_minutes`); пропущенное за пределами окна
  пропускается с записью причины, а не молча. `references/hermes-agent/cron/AGENTS.md@e96221fa:L26-L28`.
- **Сторож простоя:** сессия задания, не проявляющая активности 600 с, прерывается (именно простой, а не общее
  время); скрипты ограничены отдельно (3600 с). `references/hermes-agent/cron/AGENTS.md@e96221fa:L21-L25`.
- **`wakeAgent`:** скрипт-предпроверка может последней строкой вывести `{"wakeAgent": false}` — агент на этом тике
  не вызывается вовсе. Дешёвый способ частого опроса.
  `references/hermes-agent/website/docs/user-guide/features/cron.md@e96221fa:L1388-L1396`.
- Задание, которое невозможно выполнить (заблокировано сканером, небезопасная конфигурация), **автоматически ставится
  на паузу** с записью причины и одним оповещением — иначе оно стреляло бы каждый тик.
  `references/hermes-agent/cron/scheduler_prompt.py@e96221fa:L450-L470 (_block_and_pause_job)`.

### Изоляция сессии

Каждый запуск — новый `AIAgent` со своей сессией, `platform="cron"`:
`references/hermes-agent/cron/scheduler.py@e96221fa:L2482-L2512`.

- Инструменты: набор, настроенный для «платформы cron», либо `enabled_toolsets` задания. Всегда отключены
  `messaging` и `clarify` (спросить некого); `cronjob` отключён по умолчанию — задание не может плодить задания
  (включается `cron.allow_agent_scheduling: true`). Конфиг-запрет `agent.disabled_toolsets` задание расширить не
  может. `references/hermes-agent/cron/scheduler.py@e96221fa:L419-L438 (_resolve_cron_disabled_toolsets)`.
- Фоновый разбор выключен (`skip_background_review=True`): ~30 тыс. токенов на событие без человека в контуре.
- Память: в коде `skip_memory=False` — задание видит встроенную память, провайдер получает
  `agent_context="cron"`. При этом `cron/AGENTS.md` (L107) утверждает обратное («cron передаёт `skip_memory=True`»)
  — документ отстал от кода **(вывод; верить коду)**.
- Файлы контекста проекта — только при заданном `workdir`; `SOUL.md` грузится всегда.
- Сессии cron в поиске по сессиям понижены в ранжировании (раздел 4).

### Доставка результата

Агент **не отправляет сообщения сам**: финальный ответ забирает система и доставляет по `deliver`. Об этом ему
говорит служебная преамбула промпта; она же вводит два управляющих токена и запрет рекурсии:

- `[SILENT]` — «нового нет», доставка подавляется;
- `[CRON_FAILURE]` первой строкой — запуск считается неудачным;
- «это запуск существующего задания — не создавай и не меняй задания из-за слов "каждый понедельник" в тексте».

`references/hermes-agent/cron/scheduler_prompt.py@e96221fa:L270-L290 (_CRON_HINT)`,
`references/hermes-agent/cron/scheduler.py@e96221fa:L543 (SILENT_MARKER)`,
`references/hermes-agent/cron/scheduler.py@e96221fa:L563 (_is_cron_silence_response)`.

Цели доставки: `origin` (туда, где задание создано; по умолчанию в мессенджере), `local` (только файл),
`telegram` (домашний канал), `telegram:<chat_id>[:<thread_id>]`, список через запятую, `all`. Задание, созданное
изнутри cron-запуска, получает конкретный адрес сразу при создании (чтобы не указывать на исчезнувшую сессию).
Исходящий текст проходит редактирование секретов на всех путях, даже при выключенной настройке редактирования логов;
сбой редактора заменяет сообщение, а не отправляет его непроверенным.
`references/hermes-agent/website/docs/user-guide/features/cron.md@e96221fa:L663-L706`,
`references/hermes-agent/website/docs/user-guide/features/cron.md@e96221fa:L402-L436`.

По умолчанию доставка — «выстрелил и забыл»: в истории чата её нет, и ответ на неё агент не поймёт.
`attach_to_session` / `cron.mirror_delivery` делает доставку продолжаемой: сводка добавляется в сессию чата как
помеченная реплика на границе хода.
`references/hermes-agent/website/docs/user-guide/features/cron.md@e96221fa:L831-L845`.

### Защита от инъекций

Обоснование из кода: cron работает без человека и **сам одобряет вызовы инструментов**, поэтому промпт — поверхность
атаки. Два сканера на двух поверхностях:

1. **Строгий — по промпту, написанному пользователем/агентом**, при создании, изменении и перед запуском:
   инъекции («ignore previous instructions», «do not tell the user»), чтение секретов (`cat .env`, ключи SSH),
   `authorized_keys`, `sudoers`, `rm -rf /`, эксфильтрация (`curl`/`wget` с секретом в URL, теле или заголовке
   `Authorization` — для любого хоста), невидимые юникод-символы.
   `references/hermes-agent/tools/cronjob_prompt_scan.py@e96221fa:L30-L57`,
   `references/hermes-agent/tools/cronjob_prompt_scan.py@e96221fa:L123-L131 (_scan_cron_prompt)`.
2. **Мягкий — по собранному промпту** (с телами скиллов и выводом скриптов): только однозначные директивы-инъекции;
   командные шаблоны убраны, потому что скиллы законно *описывают* такие команды (ложное срабатывание однажды молча
   убило все задания); невидимые символы вычищаются, а не блокируют.
   `references/hermes-agent/cron/scheduler_prompt.py@e96221fa:L394-L421 (_scan_assembled_cron_prompt)`.

Ещё: `script` — только файл из каталога скриптов, не команда; переопределение `base_url` должно совпадать по
происхождению с настроенным адресом провайдера, иначе сохранённый ключ мог бы уйти на чужой хост
(`references/hermes-agent/cron/scheduler_prompt.py@e96221fa:L425-L447 (_guard_job_credential_exfil)`); скиллы,
на которые ссылаются задания, куратор не архивирует.

## 9. Плагины и хуки

Принцип: «ядро — узкая талия, возможности — по краям»; плагин **никогда не правит файлы ядра** и работает только
через опубликованные ABC, хуки и объект `ctx`. Хук без конкретного потребителя в ядро не принимают.
`references/hermes-agent/AGENTS.md@e96221fa:L16-L24`,
`references/hermes-agent/plugins/AGENTS.md@e96221fa:L7-L15`.

### Виды плагинов

`references/hermes-agent/plugins/AGENTS.md@e96221fa:L84-L92`:

| Вид | Где | Особенность |
|-----|-----|-------------|
| Общий | `plugins/<name>/`, `~/.hermes/plugins/`, `./.hermes/plugins/`, pip entry point | `register(ctx)` регистрирует хуки, инструменты, команды; при совпадении имён побеждает более поздний источник |
| Провайдер памяти | `plugins/memory/<name>/` и те же внешние места | выбирается по имени в конфиге; побеждает **встроенный**; один активный |
| Провайдер модели | `plugins/model-providers/<name>/` | ленивая загрузка; пользовательский перекрывает встроенный |
| Движок контекста, генерация изображений, делегирование, поиск, TTS и др. | `plugins/context_engine/` … | ABC + оркестратор; движок контекста, как и память, одиночного выбора |
| Адаптер платформы | `plugins/platforms/<name>/adapter.py` | грузит шлюз |

Отдельно от плагинов: **хуки событий шлюза** (каталоги в `~/.hermes/hooks/<name>/`, доверие «по размещению») и
**shell-хуки** (команда из `config.yaml` на событие; согласие спрашивается один раз на пару «событие + команда» и
хранится в `shell-hooks-allowlist.json`; спрашивает только интерактивный CLI — шлюз и cron никогда).
`references/hermes-agent/website/docs/user-guide/features/hooks.md@e96221fa:L20-L26`,
`references/hermes-agent/website/docs/user-guide/features/hooks.md@e96221fa:L2202-L2214`.

### Включение

Сторонние общие плагины **выключены по умолчанию**: обнаружение их видит, но ничего не загружается, пока имя не
добавлено в `plugins.enabled`. Встроенная инфраструктура грузится сама.
`references/hermes-agent/website/docs/user-guide/features/plugins.md@e96221fa:L173-L175`.

### Точки расширения

Хуки (`references/hermes-agent/hermes_cli/plugins.py@e96221fa:L113-L213 (VALID_HOOKS)`), по группам:

- инструменты: `pre_tool_call` (может запретить вызов), `post_tool_call`, `transform_tool_result`,
  `transform_terminal_output`;
- модель: `pre_llm_call` (может добавить контекст к сообщению пользователя или наложить вето на ход),
  `post_llm_call`, `transform_llm_output`, `pre_api_request` / `post_api_request` / `api_request_error`,
  `pre_auxiliary_call` / `post_auxiliary_call` (вспомогательные вызовы — отдельные события);
- сессия: `on_session_open`, `on_session_start`, `on_session_end`, `on_session_finalize`, `on_session_reset`,
  `on_session_delete`;
- прочее: `on_skill_lifecycle`, `subagent_start` / `subagent_stop`, `pre_gateway_dispatch` (может отбросить или
  переписать входящее сообщение), `pre_approval_request` / `post_approval_response`, потоковые наблюдатели.

Методы `ctx` (`references/hermes-agent/hermes_cli/plugins_context.py@e96221fa:L272-L886`): `register_tool`,
`register_hook`, `register_middleware`, `register_command` / `register_cli_command`,
`register_system_prompt_section` (секция в хвосте системного промпта, с лимитом размера),
`register_memory_provider`, `register_context_engine`, `register_platform`, `register_auxiliary_task`,
`register_redaction_patterns`, а также регистрация провайдеров поиска, браузера, TTS, секретов и т. п.

`post_llm_call` получает сигналы самоулучшения (сработал ли счётчик памяти/скиллов), чтобы плагин мог делать свой
разбор после хода вместо встроенного: `references/hermes-agent/agent/review_signals.py@e96221fa:L53-L64`.

### Манифест

`plugin.yaml` → `PluginManifest`
(`references/hermes-agent/hermes_cli/plugins_manifest.py@e96221fa:L361-L419`): `name`, `version`, `description`,
`author`, `kind`, `requires_env`, `provides_tools`, `provides_hooks`, `capabilities`, `requires_hermes`,
`requires_plugins`, `python_dependencies`, `config_schema`, `manifest_version`, `api_version`, `emits` / `listens`.
Точка входа — `register(ctx)` в `__init__.py`.

### Каталог плагинов (`plugin-catalog/`)

Единственный механизм обнаружения внешних плагинов: один YAML на плагин (~2380 файлов), плюс `removed.yaml` —
«список запрещённых», который проверяют все пути установки. Запись: `name`, `title`, `repo`, **`sha` (полный
40-символьный коммит, обязателен)**, `version`, `description`, `maintainer`, `tier`, `category`, `requires_hermes`,
`docs_url`, `known_issues`, `capabilities` (`provides_tools`, `provides_hooks`, `provides_middleware`,
`requires_env`). Пример — `references/hermes-agent/plugin-catalog/holographic.yaml@e96221fa`.

Правила допуска (`references/hermes-agent/plugin-catalog/README.md@e96221fa:L8-L45`): запись добавляет только
человек через PR; закрепление по точному SHA; плагин не имеет права обновлять сам себя; смена SHA — новый PR с
просмотром изменений; заявленные возможности должны совпадать с реальными (расхождение считается проблемой
безопасности). Сведения о происхождении хранятся в записи установщика, а не в дереве плагина — копию в дереве можно
подделать (`references/hermes-agent/plugins/AGENTS.md@e96221fa:L65-L82`).

В `plugins/AGENTS.md` есть раздел про изоляцию плагинов в отдельном процессе (`plugins.isolation: host`, L118) — не
читался.

## 10. Что перенять в Меркурий

Весь раздел — мои выводы для Меркурия; каждое решение привязано к месту в репозитории. Сквозное ограничение
владельца: **глобальное поведение агента меняет только владелец** (BRIEF, «Ограничения и безопасность»; ROADMAP,
фаза 11).

### Перенять

1. **Контракт провайдера памяти как главный шов фазы 6.** PHP-интерфейс `MemoryProvider` с тем же набором хуков:
   `initialize`, `systemPromptBlock`, `onTurnStart`, `prefetch`, `syncTurn`, `queuePrefetch`, `getToolSchemas`,
   `handleToolCall`, `onPreCompress`, `onMemoryWrite`, `onDelegation`, `onSessionEnd`, `onSessionSwitch`, `shutdown`.
   Holographic и dreaming написаны под этот контракт — их принципы лягут без переделки ядра.
   `references/hermes-agent/agent/memory_provider.py@e96221fa:L84-L267`.
2. **Менеджер памяти берёт на себя надёжность, а не провайдер:** исключения хуков глотаются и логируются; prefetch
   под таймаутом (8 с), «застрявший» провайдер пропускается; `syncTurn` и `queuePrefetch` — в одной очереди с
   сохранением порядка; редактирование секретов перед передачей провайдеру. В AMPHP это одна фоновая очередь на
   сессию (вместо потока) и `TimeoutCancellation` на prefetch.
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L374-L424`,
   `references/hermes-agent/agent/memory_manager.py@e96221fa:L460-L532`.
3. **Вспомненное — в сообщение пользователя, а не в системный промпт,** в обёртке `<memory-context>` с пометкой «не
   ввод пользователя», с вычисткой поддельных тегов. Это и кэш сохраняет, и границу доверия делает явной (фаза 6:
   «извлечение укладывается в бюджет токенов»).
   `references/hermes-agent/agent/memory_context.py@e96221fa:L183-L199`.
4. **Маленькая «всегда в контексте» память с жёстким лимитом и замороженным снимком** — поверх большого хранилища в
   PostgreSQL. Лимит заставляет агента консолидировать, а не копить; ошибка переполнения сразу говорит, как
   исправить одним пакетом. В Меркурии это строки таблицы, а не файлы — файловые блокировки и «дрейф» не нужны.
   `references/hermes-agent/tools/memory_tool_store.py@e96221fa:L177-L259`.
5. **Правила содержания памяти из промптов** взять почти дословно: факт — декларативно, а не приказом себе («User
   prefers concise» — да, «Always respond concisely» — нет: приказ в следующей сессии перекроет текущую просьбу);
   только подтверждённое сообщением или результатом инструмента; разовая просьба ≠ постоянное предпочтение.
   `references/hermes-agent/agent/prompt_builder.py@e96221fa:L195-L233`,
   `references/hermes-agent/agent/background_review.py@e96221fa:L446-L465`.
6. **Очередь одобрения записей (`write_approval`) — но включённая по умолчанию.** Механика готова под требование
   владельца: запись откладывается, владелец видит суть и diff, одобряет или отклоняет в канале; для правок и
   удалений фиксируется целевая запись, изменилась — отказ. Для Меркурия: записи фактов (`add`) фоновым разбором
   можно применять сразу с уведомлением, а всё, что меняет поведение (скиллы, правки/удаления памяти, политика
   подтверждений), — только через очередь и только от владельца по Telegram id.
   `references/hermes-agent/tools/write_approval.py@e96221fa:L204-L219`,
   `references/hermes-agent/tools/memory_tool.py@e96221fa:L353-L370`.
7. **Происхождение записи как обязательный атрибут** (`foreground` / `background_review`, с человеком или без) и
   зависящие от него права: без человека — только `add`, читать перед записью, удалять нельзя.
   `references/hermes-agent/tools/skill_provenance.py@e96221fa:L1-L44`,
   `references/hermes-agent/tools/memory_tool.py@e96221fa:L179-L219`.
8. **Фоновый разбор после хода как механизм фазы 11:** форк с белым списком инструментов на стороне исполнения,
   лимитом итераций и бюджетом входных токенов, запуском по счётчикам, отменой при новом сообщении; результат —
   короткое уведомление владельцу. Список «чего не записывать» (сбои окружения, негативные утверждения об
   инструментах, неудавшиеся попытки) — готовая защита от самоотравления.
   `references/hermes-agent/agent/background_review.py@e96221fa:L1192-L1233`,
   `references/hermes-agent/agent/background_review.py@e96221fa:L507-L530`.
9. **Скиллы: формат `SKILL.md` + прогрессивное раскрытие + заметка об изменении индекса.** Совместимость с форматом
   agentskills.io даёт готовые скиллы; индекс «имя — описание ≤ 60 символов» дешёв; заметка-дельта вместо перестройки
   промпта закрывает критерий фазы 9 «скилл применяется без перезапуска».
   `references/hermes-agent/agent/prompt_builder.py@e96221fa:L1303-L1368`,
   `references/hermes-agent/agent/skills_index_delta.py@e96221fa:L102-L137`.
10. **Матрица «доверие × вердикт сканера» и карантин для внешних скиллов и MCP** — прямо под «внешнее недоверенно до
    одобрения владельцем». Плюс закрепление плагинов по точному SHA и сверка заявленных возможностей с реальными.
    `references/hermes-agent/tools/skills_guard.py@e96221fa:L35-L42`,
    `references/hermes-agent/plugin-catalog/README.md@e96221fa:L8-L45`.
11. **Журнал мутаций с откатом одной записи** (кто, что, манифест до/после, содержимое по хешу) — это и есть
    «изменения версионируются и откатываются» из фазы 11. В PostgreSQL — таблица версий вместо JSONL и блобов.
    `references/hermes-agent/website/docs/user-guide/features/curator.md@e96221fa:L156-L185`.
12. **Cron: агент не доставляет сам; токен `[SILENT]`; свежая сессия; запрет рекурсии; сдвиг `next_run_at` до
    запуска; авто-пауза невыполнимого задания; предпроверка-скрипт с `wakeAgent`.** Всё это переносится один к
    одному; «тихие часы» и частота инициативных сообщений (фаза 10) — надстройка над доставкой.
    `references/hermes-agent/cron/scheduler_prompt.py@e96221fa:L270-L290`,
    `references/hermes-agent/cron/AGENTS.md@e96221fa:L38-L42`.
13. **Поиск по сессиям без LLM как отдельный слой** — в PostgreSQL это FTS по таблице сообщений; понижение
    автоматических сессий (cron) в ранжировании стоит заложить сразу.
    `references/hermes-agent/tools/session_search_tool.py@e96221fa:L24-L33`.

### Упростить

- **Профили и мультиплексирование** (один процесс — много `HERMES_HOME`, `ContextVar`, привязка экземпляра
  провайдера к профилю, `claim_instance`) — источник большой доли сложности менеджера памяти. У Меркурия один
  владелец — не нужно.
- **Файлы → БД.** Блокировки файлов, атомарные переименования, защита от дрейфа, `.bak`, `.usage.json`, JSONL-журнал
  заменяются транзакциями и таблицами.
- **Совместимость сигнатур** (менеджер разбирает сигнатуры методов провайдера, чтобы поддержать старые плагины) —
  в PHP достаточно интерфейса с версией.
- **Паритет кэша форка** (побайтно тот же промпт и `tools[]`) имеет смысл, только если разбор идёт на той же модели и
  провайдер кэширует префикс. Проще начать с дайджеста на дешёвой модели
  (`references/hermes-agent/agent/background_review.py@e96221fa:L408-L438`) и вернуться к паритету после замеров в
  трассах.
- **Чекпойнт v2 перед сжатием** (fail-closed) — не нужен, если полный журнал сообщений и так хранится в БД.
- **Хаб скиллов с восемью источниками, каталог на 2380 плагинов, куратор с LLM-консолидацией** — для одного
  владельца хватит установки по URL репозитория + детерминированного устаревания неиспользуемых скиллов.
- **Сканер на регулярных выражениях** — брать как дешёвый первый рубеж, не как гарантию: сам код называет его
  «advisory» (`references/hermes-agent/tools/threat_patterns.py@e96221fa:L18-L20`), шаблоны англоязычные; для
  русскоязычного владельца нужны русские формулировки.

### Спорное в hermes

1. **Самоизменение включено по умолчанию.** `write_approval` по умолчанию `false` и для памяти, и для скиллов, а
   промпт разбора требует «будь активен, пустой проход — упущенная возможность». Скилл — это инструкции, которым
   агент **обязан** следовать (индекс требует загружать подходящий скилл), то есть фоновый процесс без человека
   меняет поведение агента. Это прямо противоречит требованию владельца Меркурия.
   `references/hermes-agent/agent/background_review.py@e96221fa:L532-L536`.
2. **Предпочтения пользователя встраиваются в скиллы** любого происхождения, включая встроенные и установленные:
   поведение расползается по многим файлам, и восстановить «почему агент так делает» можно только по журналу. Для
   Меркурия лучше явный слой «предпочтения владельца» с одной точкой просмотра и правки.
3. **Шлюзы обходятся общими инструментами.** `write_approval` действует только на `memory` и `skill_manage`;
   файловые инструменты и терминал правят те же файлы напрямую, а `lock` сам назван «проверкой политики, а не
   песочницей». В Меркурии память и скиллы должны лежать там, куда у инструментов агента нет записи (БД + права),
   чтобы шлюз был единственным путём.
   `references/hermes-agent/tools/write_approval.py@e96221fa:L6-L9`.
4. **Сканер скиллов, созданных агентом, в основном ходе выключен** с обоснованием «терминал всё равно может то же».
   Это честно, но означает, что защита держится на подтверждении команд, а не на сканере.
   `references/hermes-agent/tools/skill_manager_tool.py@e96221fa:L44-L56`.
5. **Замороженный снимок в бесконечном чате.** В Telegram сессия одна и не заканчивается, значит записанное в память
   не попадает в промпт, пока пользователь сам не сделает `/new` — документация это признаёт. Меркурию нужна
   автоматическая граница сессии (по простою или по смене дня) либо доставка новых записей заметкой-дельтой, как
   сделано для скиллов.
   `references/hermes-agent/website/docs/user-guide/features/memory.md@e96221fa:L59-L65`.
6. **Лимит 2200 + 1375 символов** очень мал и выставлен ради стоимости каждого запроса. При памяти в PostgreSQL
   с извлечением по запросу «всегда в контексте» можно оставить ещё меньше (профиль владельца), остальное —
   через prefetch.
7. **Документация расходится с кодом** как минимум в двух местах, замеченных при чтении: память в cron
   (`cron/AGENTS.md` L107 против `cron/scheduler.py` L2508) и «LLM summarization» в поиске по сессиям (README L26
   против кода инструмента). Вывод для работы с этим референсом: проверять по коду.
8. **Объём.** Память и скиллы в hermes — десятки тысяч строк, значительная часть которых — следы конкретных
   инцидентов (номера задач в комментариях). Переносить стоит контракты и инварианты, а не реализацию.

## 11. Что читать под вопрос

| Вопрос | Файл |
|--------|------|
| Точный контракт провайдера памяти | [agent/memory_provider.py](../references/hermes-agent/agent/memory_provider.py) |
| Как менеджер вызывает хуки, таймауты, очередь | [agent/memory_manager.py](../references/hermes-agent/agent/memory_manager.py) |
| Как писать провайдер, упаковка, чекпойнт v2 | [website/docs/developer-guide/memory-provider-plugin.md](../references/hermes-agent/website/docs/developer-guide/memory-provider-plugin.md) |
| Где создаётся менеджер и подключаются инструменты провайдера | [agent/agent_init.py](../references/hermes-agent/agent/agent_init.py) (`_init_memory`), [agent/memory_manager_tools.py](../references/hermes-agent/agent/memory_manager_tools.py) |
| Где prefetch вызывается в ходе и как вставляется | [agent/turn_context.py](../references/hermes-agent/agent/turn_context.py), [agent/memory_context.py](../references/hermes-agent/agent/memory_context.py) |
| Хранилище встроенной памяти, лимиты, атомарность | [tools/memory_tool_store.py](../references/hermes-agent/tools/memory_tool_store.py) |
| Схема и шлюзы инструмента `memory` | [tools/memory_tool.py](../references/hermes-agent/tools/memory_tool.py) |
| Очередь одобрения записей | [tools/write_approval.py](../references/hermes-agent/tools/write_approval.py) |
| Шаблоны инъекций и эксфильтрации | [tools/threat_patterns.py](../references/hermes-agent/tools/threat_patterns.py) |
| Сборка системного промпта, место памяти и индекса скиллов | [agent/system_prompt.py](../references/hermes-agent/agent/system_prompt.py), [agent/prompt_builder.py](../references/hermes-agent/agent/prompt_builder.py) |
| Поиск по сессиям | [tools/session_search_tool.py](../references/hermes-agent/tools/session_search_tool.py), [hermes_state_fts.py](../references/hermes-agent/hermes_state_fts.py) |
| Память: поведение, настройки, одобрение — для пользователя | [website/docs/user-guide/features/memory.md](../references/hermes-agent/website/docs/user-guide/features/memory.md) |
| Сравнение внешних провайдеров (Honcho, Holographic…) | [website/docs/user-guide/features/memory-providers.md](../references/hermes-agent/website/docs/user-guide/features/memory-providers.md) |
| Формат и стандарт оформления скилла | [skills/AGENTS.md](../references/hermes-agent/skills/AGENTS.md), [website/docs/developer-guide/creating-skills.md](../references/hermes-agent/website/docs/developer-guide/creating-skills.md) |
| Скиллы для пользователя: раскрытие, доверие, хаб | [website/docs/user-guide/features/skills.md](../references/hermes-agent/website/docs/user-guide/features/skills.md) |
| Чтение скиллов (`skills_list`, `skill_view`) | [tools/skills_tool.py](../references/hermes-agent/tools/skills_tool.py) |
| Запись скиллов агентом, проверки, пакетность | [tools/skill_manager_tool.py](../references/hermes-agent/tools/skill_manager_tool.py), [tools/skill_manager_guards.py](../references/hermes-agent/tools/skill_manager_guards.py) |
| Сканер скиллов и матрица доверия | [tools/skills_guard.py](../references/hermes-agent/tools/skills_guard.py) |
| Замок на скилл, разбор команд терминала | [tools/skill_lock.py](../references/hermes-agent/tools/skill_lock.py) |
| Счётчики использования, архив, pin/lock | [tools/skill_usage.py](../references/hermes-agent/tools/skill_usage.py) |
| Журнал мутаций скиллов | [tools/skill_ledger.py](../references/hermes-agent/tools/skill_ledger.py) |
| Заметка об изменении индекса скиллов | [agent/skills_index_delta.py](../references/hermes-agent/agent/skills_index_delta.py) |
| Фоновый разбор: промпты, форк, белый список, бюджет | [agent/background_review.py](../references/hermes-agent/agent/background_review.py) |
| Когда срабатывает разбор | [agent/review_signals.py](../references/hermes-agent/agent/review_signals.py), [agent/turn_finalizer.py](../references/hermes-agent/agent/turn_finalizer.py) |
| Куратор скиллов | [agent/curator.py](../references/hermes-agent/agent/curator.py), [website/docs/user-guide/features/curator.md](../references/hermes-agent/website/docs/user-guide/features/curator.md) |
| Инварианты планировщика | [cron/AGENTS.md](../references/hermes-agent/cron/AGENTS.md) |
| Промпт задания, сканирование, авто-пауза | [cron/scheduler_prompt.py](../references/hermes-agent/cron/scheduler_prompt.py), [tools/cronjob_prompt_scan.py](../references/hermes-agent/tools/cronjob_prompt_scan.py) |
| Как создаётся агент для задания | [cron/scheduler.py](../references/hermes-agent/cron/scheduler.py) (L2482) |
| Инструмент `cronjob` | [tools/cronjob_tools.py](../references/hermes-agent/tools/cronjob_tools.py) |
| Cron для пользователя: доставка, форматы, цепочки | [website/docs/user-guide/features/cron.md](../references/hermes-agent/website/docs/user-guide/features/cron.md) |
| Виды плагинов, политика, каталог | [plugins/AGENTS.md](../references/hermes-agent/plugins/AGENTS.md), [plugin-catalog/README.md](../references/hermes-agent/plugin-catalog/README.md) |
| Список хуков и методы `ctx` | [hermes_cli/plugins.py](../references/hermes-agent/hermes_cli/plugins.py), [hermes_cli/plugins_context.py](../references/hermes-agent/hermes_cli/plugins_context.py) |
| Манифест плагина | [hermes_cli/plugins_manifest.py](../references/hermes-agent/hermes_cli/plugins_manifest.py) |
| Хуки: шлюзовые, плагинные, shell, согласие | [website/docs/user-guide/features/hooks.md](../references/hermes-agent/website/docs/user-guide/features/hooks.md) |

## 12. Что не прочитано

Репозиторий — около 26 тыс. файлов; читалось прицельно. Ниже — что осталось за рамками и на что это влияет.

**Память**

- `agent/memory_manager.py` L1-L196 (служебные классы, редактирование секретов, `_StuckCallGate`) — только по
  оглавлению; `agent/memory_caller_scope.py`, `agent/memory_manager_tools.py` — только сигнатуры.
- `agent/agent_init_memory_provider.py` (поиск провайдера, набор kwargs для `initialize`) — только оглавление;
  список kwargs взят из документации и docstring.
- `plugins/memory/__init__.py` (683 строки, обнаружение и загрузка), `plugins/plugin_loader.py` — только оглавление.
- Встроенные провайдеры `plugins/memory/byterover`, `plugins/memory/retaindb` — не открывались.
- `tools/memory_tool_store.py`: пакетные операции, `patch`, сопоставление подстрок (L421-L653) — по docstring.
- `tools/write_approval.py`: хранение очереди, diff для скиллов (L60-L140, L222-L416) — не читались.
- Сжатие контекста (`agent/conversation_compression*.py`, `context_compressor*.py`) — только места вызова
  `on_pre_compress`; это область соседнего конспекта.
- `website/docs/user-guide/features/memory-providers.md` — прочитаны «How It Works», Holographic, сравнение;
  разделы Honcho, Mem0, Hindsight, OpenViking, Supermemory — нет.
- Референсы `hermes-plugin-holographic` и `hermes-dreaming` в этом конспекте **не изучались** — здесь только
  контракт, на который они опираются.

**Скиллы**

- Содержимое каталогов `skills/` и `optional-skills/` (сами скиллы) — не открывалось, кроме `skills/AGENTS.md`.
- `agent/skill_utils.py`, `skill_commands.py`, `skill_bundles.py`, `skill_preprocessing.py` (разбор frontmatter,
  слэш-команды, бандлы, шаблоны) — не читались.
- `tools/skills_tool.py` — только начало `skill_view` и обёртка со счётчиками; `tools/skill_manager_tool.py` —
  точка входа, описание, константы; обработчики действий и `skill_manager_batch.py` — нет.
- `tools/skills_guard.py` — политика и вердикт; сами ~390 строк шаблонов и логика разбора — нет.
- `tools/skills_hub*.py` (9 файлов), `skills_sync*.py`, `skill_ledger.py`, `skill_linter.py` (кроме констант),
  `skills_ast_audit.py`, `skillevaluator_scan.py` — нет; хаб и журнал описаны по документации.
- `website/docs/developer-guide/creating-skills.md`, разделы skills.md про бандлы, внешние каталоги, tap'ы — нет.

**Самоулучшение**

- `agent/background_review.py` — прочитаны настройки, дайджест, все три промпта, белый список, запуск форка и
  потока; не читались отмена и ожидание (L27-L242), разрешение модели (L329-L400), сводка действий (L658-L915),
  детали паритета кэша (L916-L1140).
- `agent/review_engine.py`, `review_idle_queue.py` (кроме констант), `learn_prompt.py`, `learning_graph*.py`,
  `learning_mutations.py`, `insights.py` — нет.
- `agent/curator.py` — только оглавление и `should_run_now`; промпт куратора (L281-L460) и `curator_backup.py` —
  нет; куратор описан по документации.
- Каталог `evals/` — нет: есть ли в hermes проверочные задачи для оценки изменений (аналог пункта фазы 11
  «изменение принимается, если не ухудшает результаты») — **не выяснено**. В прочитанном такого механизма не
  встретилось.

**Планировщик**

- `cron/jobs.py`, `cron/scheduler.py` — точечно (создание агента, отключённые наборы инструментов, маркеры);
  `scheduler_delivery*.py` (доставка, 1900+ строк), `scheduler_tick.py`, `executions.py`, `occurrences.py`,
  `missed_runs.py`, `jobs_time.py` (разбор расписаний) — нет. Форматы расписаний, догоняние и «не более одного
  раза» описаны по `cron/AGENTS.md`, а не по коду.
- `tools/cronjob_tools.py` — только оглавление; схема инструмента не читалась.
- `website/docs/developer-guide/cron-internals.md`, `chronos-managed-cron-contract.md`, большая часть `cron.md`
  (1537 строк) — нет. Kanban, heartbeat, goals, loops — нет.

**Плагины и хуки**

- `hermes_cli/plugins*.py` (35 файлов) — только список хуков, поля манифеста и имена методов `ctx`; загрузка,
  изоляция в отдельном процессе, права (`capabilities`), промежуточные обработчики (middleware) — нет.
- `website/docs/user-guide/features/plugins.md`, `hooks.md` (2340 строк), `built-in-plugins.md`,
  `plugin-catalog.md`, `developer-guide/plugins/`, `observer-hooks.md`, `context-engine-plugin.md` — только
  отдельные абзацы. Полезные нагрузки (kwargs) каждого хука не выписаны.
- `agent/shell_hooks.py` — нет.

**Общее**

- `README.md` — только строка про цикл обучения; `agent/AGENTS.md`, `tools/AGENTS.md` — не читались (область
  соседних конспектов).
- Каталоги `tests/`, `apps/`, `contributors/` — не читались по условию задачи; `plugin-catalog/` — только
  `README.md` (начало) и одна запись.
- Номера строк в ссылках сверены по файлам при чтении; диапазоны для больших блоков (списки шаблонов, промпты)
  указаны по границам блока и могут расходиться на 1–3 строки.
