# CLAUDE.md

Проект разработки автономного агента **mercurius (Меркурий)**: персональный помощник, который ставится на сервер
через Docker Compose и общается с владельцем через Telegram. Стек — PHP 8.2+, AMPHP v3 / Revolt, `opencck/server`, PostgreSQL;
модели — через OpenRouter. Основной репозиторий — `github.com/opencck/mercurius`, ветка `master`.

Исследовательский этап завершён: изученные материалы лежат в проекте как база знаний, на которую опирается разработка.

## Спецификация

| Файл | Что в нём | Когда править |
|------|-----------|---------------|
| [BRIEF.md](BRIEF.md) | Цель и оглавление спецификации | Появился или переименован файл в `brief/` |
| [brief/](brief/) | Спецификация по подсистемам: требования, устройство, решения, открытые вопросы, база знаний, [история доработок](brief/history.md) | Изменились требования, принято решение, появился вывод |
| `BRIEF.md` сабмодуля | Спецификация своего репозитория: назначение и границы, устройство, решения, проверки | Изменился сабмодуль |
| `CLAUDE.md` | Как работать в проекте: правила, инструменты, навигация | Изменились правила работы, скиллы, MCP, структура |

Файлы должны быть согласованы: меняешь один — проверь остальные. Содержание не дублируй, ставь ссылку.
Каждая ссылка `spec:` ведёт к существующему заголовку — это проверяет `php bin/spec-links.php` (входит в `composer check`).

### Разделы спецификации

- [Цель](brief/requirements.md#цель)
- [Меркурий: требования](brief/requirements.md#меркурий-требования) — назначение, автономность, ограничения, стек, абстракции, инструменты, память, архитектура
- [Устройство приложения на opencck](brief/architecture.md#устройство-приложения-на-opencck) — сущности, сервисы, события, модули и задачи Меркурия в слоях opencck
- [Память: слои](brief/memory.md#память-слои) — устройство памяти: ядро, слой «всегда в контексте», проекты, задачи, отношение участника к проекту, факты, эпизоды, опыт, консолидация, HRR
- [Серверы и сеть](brief/servers.md#серверы-и-сеть) — хранилище секретов, реестр серверов, SSH, классификатор команд, журнал; фаза 7 по частям
- [Интернет и браузер](brief/internet.md#интернет-и-браузер) — поиск, чтение страниц и документов, браузер в своём контейнере, [скрипты браузера](brief/internet.md#скрипты-браузера)
- [Входящие вложения](brief/internet.md#входящие-вложения) — голос, фото, видео, документы во входящих: приём, модели зрения, видео и речи, правила участников, обмен файлами между агентами
- [Скиллы и MCP](brief/skills-mcp.md#скиллы-и-mcp) — каталог скиллов с версиями и одобрением, MCP-серверы по HTTP
- [Планировщик и проекты](brief/scheduler.md#планировщик-и-проекты) — задания по расписанию, доставка итогов, тихие часы
- [Настройки агента](brief/operations.md#настройки-агента) — что в `.env`, что в диалоге: `/settings`, `/set`, `/restart`, инструмент `settings`
- [Несколько пользователей](brief/participants.md#несколько-пользователей) — свои проекты, слой памяти, лимиты и доступы у каждого пользователя; [передача владения](brief/participants.md#передача-владения) (`/owner`, личное остаётся у прежнего владельца) и [свои роли](brief/participants.md#свои-роли) (`/roles`, полномочия ролей)
- [Язык агента](brief/operations.md#язык-агента) — пакет `mercurius-i18n`: тексты агента на русском и английском, настройка `AGENT_LANGUAGE`
- [Модули: сервисы, MCP и скиллы](brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы) — свои репозитории и профили compose, инструмент только при работающем сервисе
- [Экспорт и импорт агента](brief/operations.md#экспорт-и-импорт-агента) — зашифрованный архив агента, полный и частичный импорт
- [Поставка и обслуживание](brief/operations.md#поставка-и-обслуживание) — резервная копия, восстановление, `doctor`, набор проверочных задач
- [Выбор моделей под задачу](brief/models.md#выбор-моделей-под-задачу) — профиль задачи из оценок 1–10, профиль модели, выбор, каскад, экономия
- [Агентский цикл и харнесс](brief/agent-loop.md#агентский-цикл-и-харнесс) — запуск, цикл, лимиты, инструменты, песочница, подтверждения, контекст
- [Знания агента о себе](brief/agent-loop.md#знания-агента-о-себе) — указатель в промпте, подробности — инструмент `self_info` по темам
- [Оркестратор и воркеры](brief/orchestration.md#оркестратор-и-воркеры) — делегирование частями фазы 5: инструмент, воркер как вложенный запуск, общий бюджет, отчёт
- [Трассировка и метрики](brief/operations.md#трассировка-и-метрики) — шаги запуска, полные промпты со сроком хранения, команды расхода, Prometheus
- [Качество кода и CI](brief/requirements.md#качество-кода-и-ci) — PHPUnit, PHPStan, Psalm и экономные GitHub Actions во всех репозиториях
- [Участники: люди и агенты](brief/participants.md#участники-люди-и-агенты) — кто пишет агенту, роли и права для людей и агентов
- [Права доступа к сущностям](brief/participants.md#права-доступа-к-сущностям) — кто × что × действие × где, права по умолчанию, делегирование, секреты
- [Контекст по месту и аудитории](brief/participants.md#контекст-по-месту-и-аудитории) — изоляция контекста, раскрытие по самому слабому слушателю, слои по месту
- [Telegram: группы, каналы и темы](brief/channels.md#telegram-группы-каналы-и-темы) — работа в группах, публикация в канал, форум: темы — проекты, [прокси и локальный сервер Bot API](brief/channels.md#telegram-прокси-и-локальный-сервер-bot-api), [необязательные возможности канала и склейка разрезанных текстов](brief/channels.md#канал-необязательные-возможности-и-разрезанные-тексты)
- [Личные сообщения участникам](brief/channels.md#личные-сообщения-участникам) — `send_message` в личный чат участника, ответ возвращается тому, кто просил
- [Сеть агентов](brief/network.md#сеть-агентов) — связь экземпляров Меркурия по WebSocket API, доверие и безопасность — в том числе [вход по WebSocket и ключи](brief/network.md#вход-по-websocket-и-несколько-ключей), [подключения агентов](brief/network.md#подключения-агентов) [незнакомый агент стучится](brief/network.md#незнакомый-агент-стучится) и [участник просит инструмент](brief/network.md#участник-просит-инструмент)
- [Решения](brief/decisions.md#решения) — в том числе [Где записаны решения](brief/decisions.md#где-записаны-решения): принятые риски, лимиты, права в группе
- [Открытые вопросы](brief/decisions.md#открытые-вопросы) — в том числе разборы: [изоляция исполнения](brief/agent-loop.md#изоляция-исполнения), [границы самоулучшения](brief/self-improvement.md#границы) и [самоулучшение: что сделано](brief/self-improvement.md#самоулучшение), [участники: люди и агенты](brief/participants.md#участники-люди-и-агенты)
- [Структура репозитория](brief/architecture.md#структура-репозитория)
- [Соглашения](brief/architecture.md#соглашения)
- [База знаний](brief/knowledge.md#спецификация-база-знаний): [карта конспектов](brief/knowledge.md#карта-конспектов) ([по источникам](brief/knowledge.md#по-источникам), [по вопросам](brief/knowledge.md#по-вопросам)), [ключевые выводы](brief/knowledge.md#ключевые-выводы), [выводы из эксплуатации](brief/knowledge.md#выводы-из-эксплуатации), [расхождения между источниками](brief/knowledge.md#расхождения-между-источниками)

Если в `brief/` появился новый раздел — добавь ссылку сюда и в оглавление `BRIEF.md`. Заголовки не переименовывай без правки ссылок (`bin/spec-links.php` покажет оборванные).

## Как вести разработку

1. **Задача сверяется с требованиями спецификации.** Перед задачей найди её подсистему в `brief/`: требования, решения, что решено оставить как есть и почему ([история](brief/history.md#что-решено-оставить-как-есть)). Задача, которой нет в требованиях, — сначала скажи об этом владельцу.
2. **Сначала требования и решения.** Сверься с [требованиями](brief/requirements.md#меркурий-требования) и [решениями](brief/decisions.md#решения). Если задача упирается в [открытый вопрос](brief/decisions.md#открытые-вопросы) — спроси владельца, не решай молча.
3. **Опирайся на базу знаний и референсы — до кода, а не после.** Перед проектированием узла открой конспекты, указанные в «Опора» фазы. Порядок: [карта конспектов](brief/knowledge.md#карта-конспектов) → конспект в `notes/` → по номерам строк в `docs/` или по ссылкам в `references/`. Не читай `docs/` и `references/` целиком, пока конспект не указал место — особенно `references/hermes-agent/` (около 26 тыс. файлов).
   **Не изобретай заново то, что уже есть в референсах.** В hermes-agent и других репозиториях `references/` многое из нужного Меркурию уже реализовано и обкатано: адаптеры платформ (Telegram — `plugins/platforms/telegram/`, около 60 модулей), разметка, разбиение сообщений, повторы, лимиты, память, инструменты, безопасность. Перед **любой** правкой — новой функцией, исправлением, мелкой доработкой — найди соответствующую реализацию в референсах (конспект, затем `Grep` по имени модуля или функции), прочитай её и реши, что берём, а от чего сознательно отступаем. Взятое отмечай в коде ссылкой `ref:` с коммитом сабмодуля и номерами строк ([правила](#ссылки-на-референсы-в-коде)), отступление — одной фразой после ссылки. Если подходящего в референсах нет — так и скажи в описании изменения. Если нашёл модуль, который конспект пропустил, — допиши его в конспект.
4. **Скиллы — до кода.** Перед тем как писать код, вызови нужный скилл из [таблицы ниже](#скиллы-когда-какой). Процесс задачи — по [superpowers](#плагин-superpowers-процесс-разработки): проект → план → тесты вперёд → код → проверка → ревью.
5. **Сквозные требования обязательны** в любой задаче: async-first, лимиты в коде, недоверенные данные, секреты, логи, тесты — см. [Сквозные требования](brief/architecture.md#сквозные-требования).
6. **Проверяй на сервере.** Локального запуска недостаточно — см. [Сервер](#сервер-продакшен-тестирование-разработка).
7. **После работы обнови спецификацию:** новые решения и итог — в разделе подсистемы в `brief/` (и в `BRIEF.md` сабмодуля, если менялся он), то, что ждёт владельца, — в [истории](brief/history.md#ждёт-владельца), изменившиеся правила — здесь.
8. **Коммиты — по просьбе владельца или в рамках порученной задачи** (подробнее — [плагин superpowers](#плагин-superpowers-процесс-разработки)). Во всех репозиториях проекта работаем в ветке `master`. Перед коммитом кода — связанные тесты (`vendor/bin/phpunit` по файлам или `--filter` затронутого) и PHPStan с Psalm по изменённым файлам; полный `composer check` (PHPUnit, PHPStan, Psalm) — перед push и развёртыванием (на пачку коммитов) и в конце задачи, в том репозитории, где менялся код (решение владельца 2026-11-19: полный прогон долгий, не гонять его на каждом шаге). Коммит с красной проверкой не делаем; красный полный прогон перед push — исправить до push. Push — в `git@github.com:opencck/<репозиторий>.git` по SSH-ключу этой машины.
   **CI экономно:** не добавляй в workflow матрицы, расписания и тесты с сетью и секретами; правила — [Качество кода и CI](brief/requirements.md#качество-кода-и-ci). Изменения только в документации CI не запускают — не обходи это.
9. **Граф знаний держи свежим.** После изменения `notes/`, `docs/`, `brief/` или `CLAUDE.md` в заметном объёме (новый конспект, новый раздел, пачка решений) — `/graphify . --update`. Перед вопросом по базе знаний через `/graphify query` проверь, что граф не старее последних изменений этих файлов.
10. **Обязательные файлы каждого репозитория проекта** (основной и все пакеты `opencck/mercurius-*`); README и LICENSE — с первого коммита, проверки и CI — с первого коммита кода:
    - `README.md` — на английском, `README.ru.md` — на русском, с одинаковым содержанием. В начале каждого — ссылка на другой: `**English** | [Русский](README.ru.md)` и `[English](README.md) | **Русский**`. Меняешь один — обнови второй в том же коммите.
    - `LICENSE` — MIT; в `composer.json` (и в `package.json` у `browser-service`) — `"license": "MIT"`.
    - `composer check` и workflow GitHub Actions — по правилам [качества кода и CI](brief/requirements.md#качество-кода-и-ci).
11. **Пакеты.** Компонент, полезный вне Меркурия, живёт в отдельном репозитории `github.com/opencck/mercurius-<имя>` и подключается сабмодулем в `packages/<имя>/`. Что выносится и когда — [Пакеты и репозитории](brief/architecture.md#пакеты-и-репозитории). Код пакета не зависит от основного репозитория; правка пакета — коммит в его репозитории и обновление сабмодуля с записью в `packages/README.md`.
12. **Модули: сервисы, MCP, скиллы** (решение владельца 2026-11-17). Всё тяжёлое и необязательное (ffmpeg, Chromium, библиотеки документов, свой сервер Bot API), каждый MCP и каждый встроенный скилл — отдельный репозиторий `opencck/mercurius-<имя>` сабмодулем в `services/`, `mcp/` или `skills/`, сервис — в своём профиле compose (по умолчанию выключен), инструмент агента — только при работающем сервисе. Ядро — приложение и база. Подробно — [Модули: сервисы, MCP и скиллы](brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы).

## Знания агента о себе

Агент узнаёт о себе по требованию, а не из промпта ([Знания агента о себе](brief/agent-loop.md#знания-агента-о-себе)).
При каждой новой возможности, настройке, канале, команде или модуле:

1. **В системный промпт не добавляй описание возможности.** В промпте — только то, что нужно в каждом запросе:
   правила поведения, безопасности и подтверждений, контекст собеседника. Новый блок в промпте — только если без него
   агент будет ошибаться в обычной работе, и это решение записывается в `BRIEF.md`.
2. **Опиши возможность темой `self_info`.** Есть подходящая тема (`network`, `channels`, `commands`, `tools`,
   `modules`, `limits`, `self_improvement`, `version`) — дополни её текст в `packages/i18n` (`self.<тема>`); нет —
   добавь тему в `boot/11-http.php` (список тем `self_info`; замыкание, считающее сведения в момент вызова) и её строку `self.topic.<тема>`.
3. **Изменчивое — из кода, постоянное — из текстов.** Адреса, состояние модулей, права собеседника, версии
   вычисляются при вызове; секреты (токены, ключи) в ответ не попадают — только где они лежат.
4. **Описание инструмента** — его собственное определение: оно и так у модели, в `self_info` хватает строки.
5. Проверка — тест темы и проверка вживую: вопрос боту «как …» должен привести к вызову `self_info`, а не к догадке.

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
| `claude-api` | **Перед кодом адаптера Claude API** (третий провайдер после OpenRouter и OpenAI) и при выборе моделей Claude: идентификаторы моделей, параметры, tool use, стриминг, кеширование промптов, подсчёт токенов. Не писать по памяти |
| `skill-telegram-widgets` | Интерактивные сообщения бота (Bot Widgets): карточки подтверждений, сводки расхода, живой статус задачи. Для обычных сообщений и кнопок не нужен |
| `skill-3xui` | Нужен тестовый VLESS-сервер или диагностика VLESS/Xray, IPv6, Docker-сети на сервере (фазы 2 и 7) |
| `skill-dockerize` | Не для Меркурия: он про перенос старых сайтов в Docker. Брать только за справкой по работе за Nginx Proxy Manager |
| `skill-creator` | Создание или доработка скилла Claude Code для этого проекта (вместе с `superpowers:writing-skills`) |
| `security-review` | Перед коммитом кода, который исполняет команды, работает с секретами, SSH, прокси или недоверенными данными |
| `code-review`, `simplify` | Перед коммитом заметного объёма кода: сначала поиск ошибок, затем упрощение. Ревью задачи целиком — `superpowers:requesting-code-review` |
| `run` | Запустить приложение и убедиться, что изменение работает |
| `claude-in-chrome` | Перед первым вызовом инструментов `mcp__claude-in-chrome__*`: отладка сервиса браузера (фаза 8), проверка веб-интерфейсов, Telegram Web |
| `update-config` | Настройка Claude Code для проекта: разрешения, хуки, переменные окружения в `.claude/settings*.json` |
| `fewer-permission-prompts` | Если в работе над проектом много однотипных запросов разрешений на чтение |
| `loop`, `schedule` | Повторяющаяся проверка (например, следить за сборкой или развёртыванием) — только по просьбе владельца |
| `workflow-authoring` | Только если владелец явно попросил оркестрацию многими агентами (Workflow) |
| `dataviz` | Графики и дашборды — например, сводки расхода токенов и времени по трассам (фаза 4) |
| `amphp`, `opencck` | Глобальные копии проектных скиллов. В этом проекте используй проектные (`skill-amphp`, `skill-opencck`): их версия зафиксирована вместе с кодом |

Не относятся к разработке и используются только по прямой просьбе: скиллы документов, презентаций и таблиц (`anthropic-skills:*`), артефактов (`artifact-*`), `plugin-authoring`, `keybindings-help`, `init`, `skill-3xui-workspace`.

### Плагин superpowers: процесс разработки

Плагин `superpowers` (маркетплейс `superpowers-marketplace`) установлен у владельца и при старте сессии напоминает
вызывать свои скиллы. Он задаёт процесс разработки; **правила этого файла важнее его умолчаний** — где они
расходятся, действует то, что написано ниже.

| Скилл | Когда использовать в этом проекте |
|-------|-----------------------------------|
| `superpowers:brainstorming` | Перед проектированием нового узла (фаза, пакет, подсистема), когда в требованиях есть пробелы. Итог — подраздел `BRIEF.md`, а не отдельный файл (см. ниже) |
| `superpowers:writing-plans` | Перед кодом многошаговой задачи: план реализации фазы или пакета |
| `superpowers:executing-plans`, `superpowers:subagent-driven-development` | Исполнение плана: сам по шагам или с субагентами на независимые задачи. Субагенты дороги — запускай их, только когда задачи действительно независимы |
| `superpowers:dispatching-parallel-agents` | Две и больше независимых задач без общего состояния |
| `superpowers:test-driven-development` | **Любая новая функция или исправление:** сначала тест, потом код. Совпадает со сквозным требованием о тестах |
| `superpowers:systematic-debugging` | Любая ошибка, падающий тест или странное поведение — до того, как предлагать исправление |
| `superpowers:verification-before-completion` | Перед словами «готово», «работает», «проходит» и перед коммитом: `composer check` и проверка на сервере, с выводом команд |
| `superpowers:requesting-code-review`, `superpowers:receiving-code-review` | Ревью завершённой задачи и разбор замечаний. Вместе со скиллами `code-review` и `security-review` |
| `superpowers:finishing-a-development-branch` | Завершение задачи — только в части проверок; ветки и pull request не используем (см. ниже) |
| `superpowers:using-git-worktrees` | Не используем: работаем на месте, в `master` |
| `superpowers:writing-skills` | Создание и правка скиллов вместе с `skill-creator` |
| `superpowers:diagnosing-superpowers` | Если процесс superpowers пошёл не так и владелец хочет понять почему |

**Наши правила поверх умолчаний superpowers:**

- **Проекты (design) из `brainstorming`** записываются подразделом в `BRIEF.md` (на него потом ссылается код через `spec:`), а не в `docs/superpowers/specs/`. Каталог `docs/` — только для статей базы знаний.
- **Планы из `writing-plans`** сохраняются в `plans/YYYY-MM-DD-<тема>.md`, а не в `docs/superpowers/plans/`. Планы временные: когда задача закрыта и её итог перенесён в `brief/`, план удаляется. Код на планы не ссылается.
- **Ветки и worktree не используем:** во всех репозиториях работаем в `master` на месте. Предложения скиллов создать ветку, worktree или pull request не применяем.
- **Коммиты:** когда владелец поручил реализовать задачу, коммиты в `master` по ходу этой задачи разрешены — после зелёного `composer check`. Без поручения — только по просьбе. Push — в конце задачи или по просьбе владельца.

### Формат сообщений коммитов

Семантические сообщения по [соглашению](https://gist.github.com/joshbuchea/6f47e86d2510bce28f8e7f42ae84c716)
(решение владельца 2026-11-16), во всех репозиториях проекта:

```
<type>(<scope>): <subject>

<body: what and why, as a list>
```

- **type** — на английском, один из:
  - `feat` — новая возможность для пользователя (не для сборки);
  - `fix` — исправление ошибки для пользователя (не в сборке);
  - `docs` — документация и спецификация (`BRIEF.md`, `brief/`, `CLAUDE.md`, README, конспекты);
  - `style` — форматирование без изменения кода;
  - `refactor` — переделка рабочего кода без изменения поведения;
  - `test` — добавление и переделка тестов без изменения рабочего кода;
  - `chore` — сборка, CI, зависимости, Docker, сабмодули без изменения рабочего кода.
- **scope** — необязателен, коротко и на английском: `app`, `db`, `http`, `telegram`, `llm`, `connectors`, `memory`, `docker`, `ci`, `deps`, `spec`, `refs`.
- **Сообщения коммитов — только на английском** (решение владельца 2026-11-16): заголовок и тело.
- **subject** — с маленькой буквы, без точки в конце, в повелительной форме, как в соглашении: `feat(telegram): accept updates through the webhook`. Не длиннее ~72 символов вместе с типом.
- Одно логическое изменение — один коммит. Если в коммит попало разное, тип выбирается по главному, а остальное перечисляется в теле.

### Плагин watermarks-remover

**Тексты коммитов — всегда через `watermarks-remover:clean-user-facing-text`** (решение владельца 2026-11-16).
Порядок для каждого коммита в любом репозитории проекта:

1. Написать сообщение коммита (заголовок и тело) в файл в каталоге scratchpad.
2. Прогнать его через скилл: очистка невидимого Unicode скриптом `clean_text.py … --stats --no-normalize-spaces`, оценка `inspect_text.py --stylometry`, переписывание — только если скилл этого требует (уровень `high`), затем повторная очистка.
3. Сохранить факты, пути, идентификаторы, имена файлов и команд как есть; язык — английский.
4. Коммитить из файла: `git commit -F <файл>`.

**Строку `Co-Authored-By` и другие строки соавторства в коммиты не добавляем** — решение владельца 2026-11-16; оно важнее умолчаний Claude Code. Сообщения pull request — так же.

Скрипты лежат в каталоге скилла (`skills/clean-user-facing-text/scripts/` внутри плагина); путь бери из установленной
версии, а не из памяти.

Другие тексты — код, комментарии, конспекты, спецификацию — через этот плагин не прогоняем. Скилл
`watermarks-remover:remove-ai-marks` — только по прямой просьбе владельца.

## MCP-серверы

| Сервер | Где настроен | Что даёт | Когда использовать |
|--------|--------------|----------|--------------------|
| `ssh-mcp` | `.mcp.json` в корне (включён в `.claude/settings.local.json`); образец — [.mcp.json.example](.mcp.json.example) | SSH на сервер проекта, инструменты `mcp__ssh-mcp__*`: `read-command` (чтение по белому списку), `run-command`, `privileged-command`, интерактивные и фоновые сессии, `sftp-upload` / `sftp-download` | Развёртывание, запуск, проверка и диагностика Меркурия на сервере |
| `openrouter` | локальная настройка Claude Code для этого проекта (`~/.claude.json`, HTTP, `https://mcp.openrouter.ai/mcp`, вход через OAuth); образец — в [.mcp.json.example](.mcp.json.example) | Каталог моделей и цен (`list-models`, `get-model`, `list-model-endpoints`), документация OpenRouter (`search-docs`), баланс (`get-credits`), разбор генерации (`get-generation`); платные вызовы: `send-message`, `send-batch`, генерация изображений, речи, видео | **Выбор моделей** — только через `list-models`/`get-model`, не по памяти: идентификаторы и цены меняются. **Код адаптера OpenRouter** (фаза 2) — сверяй API через `search-docs`. **Отладка расхода** — `get-generation` по id из трассы. Платные вызовы — только для проверки промпта и по согласию владельца; перед серией — `get-credits` |
| `claude-in-chrome` | расширение браузера у пользователя | Управление вкладками Chrome, чтение страниц, консоль и сеть | Отладка сервиса браузера (фаза 8), проверка веб-интерфейсов, **проверка бота в Telegram**. Сначала вызвать скилл `claude-in-chrome` |

**Проверка бота через Telegram Web.** В Chrome владелец авторизован в Telegram Web; чат с ботом — `https://web.telegram.org/k/#8927332395` (клиент K; клиент A — `/a/` — при плохой связи теряет правки сообщений и показывает устаревший статус вместо ответа).
Через `claude-in-chrome` можно писать боту от имени владельца и читать ответы: так проверяются изменения в канале Telegram
после развёртывания. Пиши только в этот чат; другие чаты владельца не открывай и не читай.
| Коннекторы claude.ai (Docs, Data, Meetings; Asana, Atlassian, Gmail, Google, Slack, Notion и др. — без входа) | аккаунт пользователя | Документы, данные, встречи, сторонние сервисы | К разработке не относятся — только по прямой просьбе |

- `.mcp.json` содержит адрес сервера и пароль, поэтому исключён из git. Не коммить его и не печатай его содержимое.
- Исходный код сервера `ssh-mcp` лежит в референсах: [references/ssh-mcp/](references/ssh-mcp/), конспект — [notes/ssh-mcp.md](notes/ssh-mcp.md). Он же — образец политики команд для SSH-инструмента самого Меркурия.

## Сервер: продакшен, тестирование, разработка

- Доступ — через MCP `ssh-mcp`, профиль `default`. Рабочая папка — `/home/rekryt/docker/mercurius/`.
- Там держится продакшен-версия Меркурия. Через неё же ведутся проверка, тестирование и разработка: изменения проверяй на сервере, а не только локально.
- **Ещё два экземпляра для проверки сети агентов** (решение владельца 2026-11-17): `/home/rekryt/docker/mercurius-doorotar/` (@doorotarbot) и `/home/rekryt/docker/mercurius-rekryt/` (@GPT_IIIKA_bot). Это такие же клоны `opencck/mercurius`, обновляются тем же порядком. В `.env` у каждого свой проект Compose, свои ключи и токены, `WEB_ALIAS` и `SANDBOX_SUBNET`; веб-вход через NPM владельца — `https://mercurius-doorotar.srv.rekryt.ru/` и `https://mercurius-rekryt.srv.rekryt.ru/` (2026-11-17), Telegram — через webhook (`APP_URL` задан); с 2026-11-17 у каждого своя песочница (свой `SANDBOX_TOKEN`, подсеть `SANDBOX_SUBNET`: doorotar — `172.31.252.0/24`, rekryt — `172.31.253.0/24`), запускаются все сервисы (`docker compose up -d`), как у основного. Образы общие (`mercurius-app:dev` и т. д.): сборка в любом каталоге обновляет все три. Пишем только в чат основного бота; в чаты этих ботов — только по просьбе владельца.
- **Третий дополнительный экземпляр — под пользователем `yaon`** (решение владельца 2026-11-17): `/home/yaon/mercurius/`, бот @imercurius_bot, владелец агента — Telegram `451898468`, домен `https://mercurius-yaon.srv.rekryt.ru/` (NPM владельца; псевдоним в сети `web` — `mercurius-yaon`), проект Compose `mercurius-yaon`, с 2026-11-17 своя песочница (подсеть `172.31.251.0/24`) и все сервисы. Каталог принадлежит `yaon`, у `rekryt` — запись через ACL (`setfacl`, выдал владелец); `.env` и `storage/browser` (1777) созданы `rekryt`. Для git на этом клоне у `rekryt` задан `safe.directory`. Ключи моделей и VLESS — те же, что у основного. Обновляется тем же порядком, что два других.
- **Четвёртый дополнительный экземпляр — machaon** (решение владельца 2026-11-18): `/home/rekryt/docker/mercurius-machaon/`,
  бот @MercyRouterBot, владелец агента — Telegram `383408372`, домен `https://mercurius-machaon.srv.rekryt.ru/` (NPM
  владельца; псевдоним в сети `web` — `mercurius-machaon`), проект Compose `mercurius-machaon`, песочница
  `172.31.249.0/24`, свой ключ OpenRouter, модули — как у doorotar. Обновляется тем же порядком.
- Работай только внутри `/home/rekryt/docker/mercurius/` (и трёх каталогов выше), а также `/home/yaon/mercurius/`. В `/home/rekryt/docker/` лежат другие проекты владельца — их файлы, контейнеры, сети и тома не трогай.
- Сервисы запускаются через Docker Compose; имя проекта и файл берутся из `.env` (`COMPOSE_PROJECT_NAME`, `COMPOSE_FILE`).
- Веб-вход — `https://mercurius.srv.rekryt.ru/` через Nginx Proxy Manager владельца. Сервис приложения подключается к внешней сети `web` (`networks: web: external: true`), порты наружу не публикует. Настройки NPM меняет только владелец; в сеть `web` подключён только сервис с HTTP-входом, база и песочница — нет.
- Секреты (`.env`) в git не попадают: на сервер их переноси отдельно и в вывод команд не печатай.
- Для чтения используй `read-command`; команды, меняющие систему, — только в рамках задачи.
- Диск сервера заполнен на 90%: следи за размером образов и томов, убирай за собой неиспользуемые образы проекта.

### Развёртывание

Код на сервер попадает только через git: каталог `/home/rekryt/docker/mercurius/` — клон `opencck/mercurius` (`master`).

Одной командой шаги 2–4 и 6 делает `bin/update.sh` из каталога экземпляра (на сервере с таймаутом ssh-mcp — в фоне
через `nohup`); ниже — те же шаги по отдельности.

1. Закоммитить и отправить изменения (`composer check` зелёный, CI прошёл).
2. На сервере: `cd /home/rekryt/docker/mercurius && git pull --ff-only && git submodule update --init --depth 1 packages/llm packages/connectors packages/messenger packages/search packages/i18n packages/mcp-client packages/memory packages/peer mcp/mail services/browser services/telegram-bot-api services/media-video services/media-docs skills/skill-creator skills/memory-file` (референсы на сервере не нужны). Модули включаются в `.env` профилем (`COMPOSE_PROFILES`) и токеном — [Модули: сервисы, MCP и скиллы](brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы).
3. Новые переменные окружения — дописать в серверный `.env` через `sftp-upload` (права `600`), не печатая значения.
4. Копия перед обновлением: `bin/backup.sh` (дамп базы, данные, `.env`; последние 7 — в `backups/`). Затем `docker compose up -d --build` (на сервере с таймаутом ssh-mcp в 60 с — в фоне через `nohup`, затем ждать `EXIT=` в журнале); проверить `docker compose ps` (`app`, `postgres`, `sandbox` и модули из `COMPOSE_PROFILES` — `healthy`) и журнал `docker compose logs --tail=50 app` (строка `Migrations checked` с применёнными миграциями).
5. Интеграционные тесты: `docker compose --profile test build test && docker compose --profile test run --rm test` (Compose на сервере не знает `run --build`; в фоне через `nohup`).
6. Убрать старые образы проекта: `docker image prune -f --filter label=com.docker.compose.project=mercurius` — не трогая чужие.

**Откат:** `git checkout <предыдущий коммит> && docker compose up -d --build`, затем вернуться на `master`, когда исправление запушено.
Миграции только добавляют схему; откат кода не откатывает базу — несовместимые изменения схемы делаются в два шага (добавить → перевести код → удалить).

## Секреты и локальные файлы

| Файл | В git | Содержимое |
|------|-------|------------|
| `.env` | нет | Секреты: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `OPENROUTER_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `AWS_BEARER_TOKEN_BEDROCK` (нужен хотя бы один из четырёх ключей; регион Bedrock — `BEDROCK_REGION`), `VLESS_URI` (задан — запросы к провайдерам моделей идут через VLESS; пуст — напрямую), `TELEGRAM_PROXY` (путь к Telegram: пусто — напрямую, `vless` или прокси), `TELEGRAM_API_ID`/`TELEGRAM_API_HASH` (для локального сервера Bot API, профиль `telegram-api`), `POSTGRES_PASSWORD`, `SANDBOX_TOKEN` (пуст — песочница выключена), `METRICS_TOKEN` (пуст — `/metrics` выключен), `MEMORY_SECRET` (ключ отпечатков забытых фактов; пуст — выводится из токена бота), `SECRETS_KEY` (мастер-ключ хранилища секретов, 32 байта в base64; пуст — хранилище и SSH выключены), `BROWSER_TOKEN` (токен сервиса браузера; пуст — браузера у агента нет), `A2A_TOKEN` (токен входа для соседних агентов; пуст — вход по A2A выключен; кто говорит — по ключам ECDSA агентов), `A2A_TRANSPORT` (`ws` — WebSocket `/a2a/ws`, по умолчанию; `http` — `POST /a2a`). `MAIL_MCP_TOKEN` (токен встроенного MCP почты; пуст — почты у агента нет). `JEV_API_KEY` (ключ сервиса-судьи Jev для `EVAL_JUDGE_MODEL=jev:…`; пуст — Jev не используется). Прочее: `COMPOSE_PROJECT_NAME`, `COMPOSE_FILE`, `TELEGRAM_ADMIN_ID` (первый владелец; дальше владение передаётся `/owner`), `APP_URL` (задан — Telegram через webhook, пуст — long polling), лимиты агента и песочницы, окно и модели (`LLM_*`), часовой пояс и тихие часы владельца (`OWNER_TIMEZONE`, `QUIET_HOURS`), лимит токенов задания (`JOB_TOKEN_BUDGET`), время ежедневной сводки (`DIGEST_TIME`), срок хранения трасс. Настройки агента (модели, лимиты, поиск, почта, язык `AGENT_LANGUAGE` и др.) задаются в `.env` как начальные и меняются в диалоге (`/set`) — [Настройки агента](brief/operations.md#настройки-агента). Полный список с пояснениями — `.env.example` |
| `.env.example` | да | Те же переменные без значений. Добавил переменную в `.env` — добавь и сюда |
| `.mcp.json` | нет | Настройка MCP `ssh-mcp` с адресом и паролем |
| `.mcp.json.example` | да | Образец без адреса и пароля; заодно показывает, как подключить MCP OpenRouter |
| `.claude/settings.local.json` | нет | Локальные настройки Claude Code |
| `graphify-out/` | нет | Граф знаний, пересобирается командой `/graphify` |
| `storage/app`, `storage/postgres`, `storage/workspace`, `storage/browser` | только каталоги с `.keep` | Данные контейнеров (решение владельца 2026-11-16 — каталоги проекта, не тома Docker): приложение, база PostgreSQL (`pgdata/`), рабочие каталоги участников в песочнице, профили браузера со входами (права 1777: пишет пользователь контейнера) |
| `.claude/skills/skill-opencck/`, `.claude/skills/graphify/` | нет | Локальные скиллы без своего репозитория |

Значения секретов не выводи в ответах, логах, коммитах и сообщениях коммитов.

## База знаний: материалы

Конспект — точка входа в каждый материал. Сводка по всем — в [База знаний](brief/knowledge.md#база-знаний).

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

Новые материалы добавляй по [соглашениям](brief/architecture.md#соглашения) и вноси в эти таблицы.

## Референсные репозитории: версии и обновления

Индекс — [references/README.md](references/README.md): таблица зафиксированных версий сабмодулей и журнал изменений. В нём же отслеживаются сабмодули вне `references/` (скиллы).

- Версия репозитория — это коммит сабмодуля. Таблица в индексе должна совпадать с `git submodule status`.
- Конспект репозитория в `notes/` привязан к версии: поле `commit` во frontmatter.
- При любом обновлении сабмодуля в том же коммите обновляй индекс (коммит, даты) и добавляй запись в журнал: что изменилось в upstream, что это значит для проекта, что обновлено у нас.
- После обновления проверь, не нужно ли что-то менять в проекте: конспект, выводы в `BRIEF.md`, код и ссылки на референс в комментариях, `brief/`. Новые возможности и приёмы из upstream — повод пересмотреть наши решения; результат проверки запиши в журнал, даже если это «ничего не требуется».
- Если просят проверить обновления референсов — следуй порядку из раздела «Как проверить и обновить» индекса; без просьбы сабмодули не обновляй.
- `amphp-ssh` и `amphp-vless` станут зависимостями Меркурия через Composer. Версия пакета в `composer.lock` и коммит сабмодуля должны совпадать; расхождение — повод обновить сабмодуль и конспект.

## Ссылки в коде

Временные документы (планы в `plans/`) в коде, комментариях, тестах и сообщениях коммитов не упоминаются.

Код ссылается только на то, что останется в репозитории:

- **на требование или решение** — подраздел спецификации в `brief/` по якорю:
  ```
  // spec: brief/models.md#выбор-моделей-под-задачу — каскад на более сильную модель при неудаче
  ```
- **на референсный материал** — путь до файла, версия сабмодуля, строки (правила ниже).

Префикс `spec:` — для ссылок на спецификацию, `ref:` — для ссылок на референсы. При переименовании заголовка найди все
`spec: <файл>#<старый-якорь>` и обнови их; `php bin/spec-links.php` покажет оборванные.

**Путь в `spec:` — всегда от корня основного репозитория** (решение владельца 2026-11-18): `spec: brief/<файл>.md#…` —
спецификация основного репозитория, `spec: packages/llm/BRIEF.md#…`, `spec: services/browser/BRIEF.md#…` — бриф
сабмодуля. Так ссылка однозначна из любого репозитория. Когда раздел переезжает в другой файл или в бриф сабмодуля,
все ссылки на его якорь во всех репозиториях переписываются в том же коммите, а `bin/spec-links.php` зелёный до и после.

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
