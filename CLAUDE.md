# CLAUDE.md

Проект разработки автономного агента **mercurius (Меркурий)**: персональный помощник, который ставится на сервер
через Docker Compose и общается с владельцем через Telegram. Стек — PHP 8.2+, AMPHP v3 / Revolt, `opencck/server`, PostgreSQL;
модели — через OpenRouter. Основной репозиторий — `github.com/opencck/mercurius`, ветка `master`.

Исследовательский этап завершён: изученные материалы лежат в проекте как база знаний, на которую опирается разработка.

## Три файла спецификации

| Файл | Что в нём | Когда править |
|------|-----------|---------------|
| [BRIEF.md](BRIEF.md) | Что строим и почему: требования, решения, открытые вопросы, база знаний | Изменились требования, принято решение, появился вывод |
| [ROADMAP.md](ROADMAP.md) | В каком порядке строим: фазы, состав, критерии готовности, статусы | Начата или закрыта фаза, изменился состав или порядок |
| `CLAUDE.md` | Как работать в проекте: правила, инструменты, навигация | Изменились правила работы, скиллы, MCP, структура |

Три файла должны быть согласованы: меняешь один — проверь два других. Содержание не дублируй, ставь ссылку.

### Разделы BRIEF.md

- [Цель](BRIEF.md#цель)
- [Меркурий: требования](BRIEF.md#меркурий-требования) — назначение, автономность, ограничения, стек, абстракции, инструменты, память, архитектура
- [Решения](BRIEF.md#решения)
- [Открытые вопросы](BRIEF.md#открытые-вопросы)
- [Структура репозитория](BRIEF.md#структура-репозитория)
- [Соглашения](BRIEF.md#соглашения)
- [База знаний](BRIEF.md#база-знаний): [карта конспектов](BRIEF.md#карта-конспектов) ([по источникам](BRIEF.md#по-источникам), [по вопросам](BRIEF.md#по-вопросам)), [ключевые выводы](BRIEF.md#ключевые-выводы), [расхождения между источниками](BRIEF.md#расхождения-между-источниками)

Если в `BRIEF.md` появился новый раздел — добавь ссылку сюда. Заголовки разделов `BRIEF.md` и `ROADMAP.md` не переименовывай без правки ссылок.

## Как вести разработку

1. **Работа идёт по фазам [ROADMAP.md](ROADMAP.md).** Перед задачей найди её фазу, прочитай «Состав» и «Готово, когда». Задача вне роадмапа — сначала скажи об этом.
2. **Сначала требования и решения.** Сверься с [требованиями](BRIEF.md#меркурий-требования) и [решениями](BRIEF.md#решения). Если задача упирается в [открытый вопрос](BRIEF.md#открытые-вопросы) — спроси владельца, не решай молча.
3. **Опирайся на базу знаний.** Перед проектированием узла открой конспекты, указанные в «Опора» фазы. Порядок: [карта конспектов](BRIEF.md#карта-конспектов) → конспект в `notes/` → по номерам строк в `docs/` или по ссылкам в `references/`. Не читай `docs/` и `references/` целиком, пока конспект не указал место — особенно `references/hermes-agent/` (около 26 тыс. файлов).
4. **Скиллы — до кода.** Перед тем как писать код, вызови нужный скилл из [таблицы ниже](#скиллы-когда-какой).
5. **Сквозные требования роадмапа обязательны** в любой задаче: async-first, лимиты в коде, недоверенные данные, секреты, логи, тесты — см. [ROADMAP.md → Сквозные требования](ROADMAP.md#сквозные-требования).
6. **Проверяй на сервере.** Локального запуска недостаточно — см. [Сервер](#сервер-продакшен-тестирование-разработка).
7. **После работы обнови спецификацию:** статус фазы в `ROADMAP.md`, новые решения и снятые вопросы в `BRIEF.md`, изменившиеся правила — здесь.
8. **Коммиты — только по просьбе владельца.** Во всех репозиториях проекта работаем в ветке `master`.
9. **Пакеты.** Компонент, полезный вне Меркурия, живёт в отдельном репозитории `github.com/opencck/mercurius-<имя>` и подключается сабмодулем в `packages/<имя>/`. Что выносится и когда — [ROADMAP.md → Пакеты и репозитории](ROADMAP.md#пакеты-и-репозитории). Код пакета не зависит от основного репозитория; правка пакета — коммит в его репозитории и обновление сабмодуля с записью в `packages/README.md`.

## Скиллы: когда какой

### В проекте (`.claude/skills/`)

| Скилл | Откуда | Когда использовать |
|-------|--------|--------------------|
| `skill-opencck` | локальный каталог, в git не входит | **Всегда при работе с кодом Меркурия:** структура проекта, модули, контроллеры, сервисы, сущности, репозитории, роутинг (HTTP/WS/SSE), Cycle ORM и миграции, Docker для сервера, тесты `AsyncTestCase`, Prometheus-экспортёр |
| `skill-amphp` | сабмодуль [opencck/skill-amphp](https://github.com/opencck/skill-amphp) | **Всегда при написании асинхронного PHP:** `Amp\async`, `Future`, отмена и таймауты, HTTP-клиент и сервер, сокеты, параллельные воркеры, отладка event loop. Вместе с `skill-opencck` |
| `graphify` | локальный каталог (0.9.43), в git не входит | Вопрос по базе знаний, когда нужна связь между источниками, а не один конспект: `/graphify query "<вопрос>"`. После добавления материалов в `docs/` или `notes/` — `/graphify . --update` |
| `skill-compliance` (`legal-compliance-engine`) | сабмодуль [rekryt/skill-compliance](https://github.com/rekryt/skill-compliance) | Только по запросу: политика конфиденциальности, согласие на обработку персональных данных, оферта — когда Меркурий пойдёт к сторонним пользователям (фаза 12). В разработке ядра не нужен |

В репозитории лежат только скиллы-сабмодули. Локальные каталоги скиллов исключены в `.gitignore`: после клонирования проекта `skill-opencck` и `graphify` нужно поставить отдельно. Новый локальный скилл без своего репозитория — добавь его каталог в `.gitignore`.

### Установлены у пользователя (вне репозитория)

| Скилл | Когда использовать в этом проекте |
|-------|-----------------------------------|
| `claude-api` | **Перед кодом адаптера Claude API** (второй провайдер после OpenRouter) и при выборе моделей Claude: идентификаторы моделей, параметры, tool use, стриминг, кеширование промптов, подсчёт токенов. Не писать по памяти |
| `skill-telegram-widgets` | Интерактивные сообщения бота (Bot Widgets): карточки подтверждений, сводки расхода, живой статус задачи. Для обычных сообщений и кнопок не нужен |
| `skill-3xui` | Нужен тестовый VLESS-сервер или диагностика VLESS/Xray, IPv6, Docker-сети на сервере (фазы 2 и 7) |
| `skill-dockerize` | Не для Меркурия: он про перенос старых сайтов в Docker. Брать только за справкой по работе за Nginx Proxy Manager |
| `skill-creator` | Создание или доработка скилла Claude Code для этого проекта |
| `security-review` | Перед коммитом кода, который исполняет команды, работает с секретами, SSH, прокси или недоверенными данными |
| `code-review`, `simplify` | Перед коммитом заметного объёма кода: сначала поиск ошибок, затем упрощение |
| `run` | Запустить приложение и убедиться, что изменение работает |
| `amphp`, `opencck` | Глобальные копии проектных скиллов. В этом проекте используй проектные (`skill-amphp`, `skill-opencck`): их версия зафиксирована вместе с кодом |

Скиллы для документов, презентаций, таблиц и артефактов к разработке не относятся — только по прямой просьбе.

## MCP-серверы

| Сервер | Где настроен | Что даёт | Когда использовать |
|--------|--------------|----------|--------------------|
| `ssh-mcp` | `.mcp.json` в корне (включён в `.claude/settings.local.json`); образец — [.mcp.json.example](.mcp.json.example) | SSH на сервер проекта, инструменты `mcp__ssh-mcp__*`: `read-command` (чтение по белому списку), `run-command`, `privileged-command`, интерактивные и фоновые сессии, `sftp-upload` / `sftp-download` | Развёртывание, запуск, проверка и диагностика Меркурия на сервере |
| `claude-in-chrome` | расширение браузера у пользователя | Управление вкладками Chrome, чтение страниц, консоль и сеть | Отладка сервиса браузера (фаза 8), проверка веб-интерфейсов. Сначала вызвать скилл `claude-in-chrome` |
| Коннекторы claude.ai (Docs, Data, Meetings и др.) | аккаунт пользователя | Документы, данные, встречи | К разработке не относятся — только по прямой просьбе |

- `.mcp.json` содержит адрес сервера и пароль, поэтому исключён из git. Не коммить его и не печатай его содержимое.
- Исходный код сервера `ssh-mcp` лежит в референсах: [references/ssh-mcp/](references/ssh-mcp/), конспект — [notes/ssh-mcp.md](notes/ssh-mcp.md). Он же — образец политики команд для SSH-инструмента самого Меркурия.

## Сервер: продакшен, тестирование, разработка

- Доступ — через MCP `ssh-mcp`, профиль `default`. Рабочая папка — `/home/rekryt/docker/mercurius/`.
- Там держится продакшен-версия Меркурия. Через неё же ведутся проверка, тестирование и разработка: изменения проверяй на сервере, а не только локально.
- Работай только внутри `/home/rekryt/docker/mercurius/`. В `/home/rekryt/docker/` лежат другие проекты владельца — их файлы, контейнеры, сети и тома не трогай.
- Сервисы запускаются через Docker Compose; имя проекта и файл берутся из `.env` (`COMPOSE_PROJECT_NAME`, `COMPOSE_FILE`).
- Секреты (`.env`) в git не попадают: на сервер их переноси отдельно и в вывод команд не печатай.
- Для чтения используй `read-command`; команды, меняющие систему, — только в рамках задачи.
- Диск сервера заполнен на 90%: следи за размером образов и томов, убирай за собой неиспользуемые образы проекта.

## Секреты и локальные файлы

| Файл | В git | Содержимое |
|------|-------|------------|
| `.env` | нет | `COMPOSE_PROJECT_NAME`, `COMPOSE_FILE`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_ID`, `OPENROUTER_API_KEY` |
| `.env.example` | да | Те же переменные без значений. Добавил переменную в `.env` — добавь и сюда |
| `.mcp.json` | нет | Настройка MCP `ssh-mcp` с адресом и паролем |
| `.mcp.json.example` | да | Образец без адреса и пароля |
| `.claude/settings.local.json` | нет | Локальные настройки Claude Code |
| `graphify-out/` | нет | Граф знаний, пересобирается командой `/graphify` |
| `.claude/skills/skill-opencck/`, `.claude/skills/graphify/` | нет | Локальные скиллы без своего репозитория |

Значения секретов не выводи в ответах, логах, коммитах и сообщениях коммитов.

## База знаний: материалы

Конспект — точка входа в каждый материал. Сводка по всем — в [BRIEF.md → База знаний](BRIEF.md#база-знаний).

### Референсные репозитории (`references/`, git-сабмодули)

Версии и журнал обновлений — в [references/README.md](references/README.md).

| Репозиторий | Зачем проекту | Конспект | Локальная копия |
|-------------|---------------|----------|-----------------|
| [NousResearch/hermes-agent](https://github.com/NousResearch/hermes-agent) | Образец ядра: агентский цикл, инструменты, делегирование, шлюз, подтверждения, скиллы | [ядро](notes/hermes-agent.md), [память и скиллы](notes/hermes-agent-memory-skills.md), [шлюз и безопасность](notes/hermes-agent-gateway-security.md) | [references/hermes-agent/](references/hermes-agent/) |
| [itpartypattaya/hermes-plugin-holographic](https://github.com/itpartypattaya/hermes-plugin-holographic) | Образец памяти: хранилище фактов, поиск, доверие, история версий | [notes/hermes-plugin-holographic.md](notes/hermes-plugin-holographic.md) | [references/hermes-plugin-holographic/](references/hermes-plugin-holographic/) |
| [itpartypattaya/hermes-dreaming](https://github.com/itpartypattaya/hermes-dreaming) | Образец фоновой консолидации памяти | [notes/hermes-dreaming.md](notes/hermes-dreaming.md) | [references/hermes-dreaming/](references/hermes-dreaming/) |
| [rekryt/amphp-ssh](https://github.com/rekryt/amphp-ssh) | Асинхронный SSH-клиент — основа SSH-инструмента агента | [notes/amphp-ssh.md](notes/amphp-ssh.md) | [references/amphp-ssh/](references/amphp-ssh/) |
| [rekryt/amphp-vless](https://github.com/rekryt/amphp-vless) | Асинхронный VLESS-клиент — исходящие соединения через VLESS | [notes/amphp-vless.md](notes/amphp-vless.md) | [references/amphp-vless/](references/amphp-vless/) |
| [tufantunc/ssh-mcp](https://github.com/tufantunc/ssh-mcp) | Образец политики команд, подтверждений и аудита для SSH | [notes/ssh-mcp.md](notes/ssh-mcp.md) | [references/ssh-mcp/](references/ssh-mcp/) |
| [anthropics/claude-cookbooks](https://github.com/anthropics/claude-cookbooks) | Паттерны агентов, работа с контекстом, памятью, субагентами | [notes/claude-cookbooks.md](notes/claude-cookbooks.md) | [references/claude-cookbooks/](references/claude-cookbooks/) |

### Статьи (`docs/`)

| Материал | Конспект | Локальная копия | Оригинал |
|----------|----------|-----------------|----------|
| Anthropic — Building effective AI agents | [notes](notes/anthropic-building-effective-agents.md) | [docs](docs/anthropic-building-effective-agents.md) | [anthropic.com](https://www.anthropic.com/research/building-effective-agents) |
| Claude Cookbook — Orchestrator-Workers | [notes](notes/claude-cookbook-orchestrator-workers.md) | [docs](docs/claude-cookbook-orchestrator-workers.md) | [platform.claude.com](https://platform.claude.com/cookbook/patterns-agents-orchestrator-workers) |
| Swarms — Orchestrator-Worker Pattern for LLM Agents | [notes](notes/swarms-orchestrator-worker-pattern.md) | [docs](docs/swarms-orchestrator-worker-pattern.md) | [swarms.ai](https://www.swarms.ai/blog/orchestrator-worker-pattern-llm-agents) |
| BackendBytes — Multi-agent orchestrator backend | [notes](notes/backendbytes-multi-agent-orchestrator-backend.md) | [docs](docs/backendbytes-multi-agent-orchestrator-backend.md) | [backendbytes.com](https://backendbytes.com/articles/multi-agent-orchestrator-backend/) |
| MetaCTO — AI agent orchestration patterns | [notes](notes/metacto-ai-agent-orchestration-patterns.md) | [docs](docs/metacto-ai-agent-orchestration-patterns.md) | [metacto.com](https://www.metacto.com/blogs/ai-agent-orchestration-patterns) |
| Microsoft Azure — AI agent orchestration patterns | [notes](notes/microsoft-azure-ai-agent-design-patterns.md) | [docs](docs/microsoft-azure-ai-agent-design-patterns.md) | [learn.microsoft.com](https://learn.microsoft.com/en-us/azure/architecture/ai-ml/guide/ai-agent-design-patterns) |
| Vantaige — Orchestrator-workers multi-agent pattern (2026) | [notes](notes/vantaige-orchestrator-workers-2026.md) | [docs](docs/vantaige-orchestrator-workers-2026.md) | [vantaige.io](https://vantaige.io/blog/orchestrator-workers-multi-agent-pattern-2026) |
| Glukhov — Multi-agent orchestration patterns | [notes](notes/glukhov-multi-agent-orchestration-patterns.md) | [docs](docs/glukhov-multi-agent-orchestration-patterns.md) | [glukhov.org](https://www.glukhov.org/ai-systems/architecture/multi-agent-orchestration-patterns/) |
| CompileNRun — Orchestrator-Workers | [notes](notes/compilenrun-agents-orchestrator-workers.md) | [docs](docs/compilenrun-agents-orchestrator-workers.md) | [compilenrun.com](https://www.compilenrun.com/docs/ai/agents/agents-multi-agent-systems/agents-orchestrator-workers/) |
| Built with LangGraph #31 — не скачана (403) | — | [заглушка](docs/plainenglish-langgraph-orchestrator-worker.md) | [ai.plainenglish.io](https://ai.plainenglish.io/built-with-langgraph-31-orchestrator-worker-design-pattern-aa4ed663fc17) |

Новые материалы добавляй по [соглашениям](BRIEF.md#соглашения) и вноси в эти таблицы.

## Референсные репозитории: версии и обновления

Индекс — [references/README.md](references/README.md): таблица зафиксированных версий сабмодулей и журнал изменений. В нём же отслеживаются сабмодули вне `references/` (скиллы).

- Версия репозитория — это коммит сабмодуля. Таблица в индексе должна совпадать с `git submodule status`.
- Конспект репозитория в `notes/` привязан к версии: поле `commit` во frontmatter.
- При любом обновлении сабмодуля в том же коммите обновляй индекс (коммит, даты) и добавляй запись в журнал: что изменилось в upstream, что это значит для проекта, что обновлено у нас.
- После обновления проверь, не нужно ли что-то менять в проекте: конспект, выводы в `BRIEF.md`, код и ссылки на референс в комментариях, `ROADMAP.md`. Новые возможности и приёмы из upstream — повод пересмотреть наши решения; результат проверки запиши в журнал, даже если это «ничего не требуется».
- Если просят проверить обновления референсов — следуй порядку из раздела «Как проверить и обновить» индекса; без просьбы сабмодули не обновляй.
- `amphp-ssh` и `amphp-vless` станут зависимостями Меркурия через Composer. Версия пакета в `composer.lock` и коммит сабмодуля должны совпадать; расхождение — повод обновить сабмодуль и конспект.

## Ссылки на референсы в коде

Когда код, комментарий, конспект или документ опирается на референсный материал, ссылка должна позволять найти точное место и понять, по какой версии оно взято:

- **Репозиторий:** путь до файла от корня проекта, короткий коммит сабмодуля, номера строк и, если есть, имя функции или класса.
  ```
  // ref: references/claude-cookbooks/patterns/agents/util.py@ce8b262:L9-L29 (llm_call) — формат вызова модели
  ```
- **Ноутбук (`.ipynb`):** номера строк JSON ненадёжны — указывай номер ячейки (с нуля, как в JSON) и имя функции или заголовок раздела.
  ```
  # ref: references/claude-cookbooks/patterns/agents/orchestrator_workers.ipynb@ce8b262, cell 2 (FlexibleOrchestrator.process) — разбор плана
  ```
- **Статья из `docs/`:** путь, номера строк и дата скачивания из frontmatter (`fetched`).
  ```
  // ref: docs/backendbytes-multi-agent-orchestrator-backend.md:L324-L342 (fetched 2026-11-16) — атомарный резерв бюджета
  ```
- После ссылки коротко пиши, что именно взято или от чего сознательно отступили.
- Коммит бери из `git submodule status` на момент написания, не из памяти. Номера строк проверяй по файлу.
- Префикс `ref:` обязателен — по нему ищут все места, зависящие от референса, когда сабмодуль обновляется (`ref: references/<репо>/`).
- При обновлении сабмодуля ссылки на изменившиеся файлы перепроверяй и обновляй коммит и строки.
