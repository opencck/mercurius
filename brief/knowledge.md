# База знаний

## База знаний

Результат исследовательского этапа: статьи (`docs/`), репозитории (`references/`) и конспекты по каждому
материалу (`notes/`). Три раздела ниже — её сводка: [карта конспектов](#карта-конспектов),
[ключевые выводы](#ключевые-выводы), [расхождения между источниками](#расхождения-между-источниками).

Что покрыто:

- Паттерны мультиагентной оркестрации (в первую очередь orchestrator-workers).
- Когда нужен агент, а когда достаточно workflow.
- Декомпозиция задач, делегирование, агрегация результатов.
- Надёжность: обработка ошибок, ретраи, контроль стоимости, наблюдаемость.
- Бэкенд-реализация: очереди, состояние, параллелизм.
- Фреймворки: Claude Agent SDK, LangGraph, Swarms, Azure/Microsoft Agent Framework.
- Библиотеки и образцы под сетевые инструменты агента: SSH, VLESS, политика команд и аудит.

Чего в базе нет — в [открытых вопросах](decisions.md#открытые-вопросы).

## Карта конспектов

Порядок чтения: сначала конспект, из него — по номерам строк в статью или по ссылкам в репозиторий.

### По источникам

| Конспект | Источник | Что это | Тип и оценка |
|----------|----------|---------|--------------|
| [hermes-agent](../notes/hermes-agent.md) | [репозиторий](../references/hermes-agent/) | Образец ядра: архитектура, агентский цикл, промпт и контекст, провайдеры, инструменты, делегирование, состояние | Главный образец для фаз 2–5; репозиторий огромный, прочитан прицельно |
| [hermes-agent-memory-skills](../notes/hermes-agent-memory-skills.md) | [репозиторий](../references/hermes-agent/) | Встроенная память, контракт провайдера памяти, скиллы, цикл самоулучшения, cron, плагины | Образец для фаз 6, 9, 10, 11 |
| [hermes-agent-gateway-security](../notes/hermes-agent-gateway-security.md) | [репозиторий](../references/hermes-agent/) | Шлюз и адаптеры каналов, Telegram, подтверждения, окружения исполнения, браузер, MCP, секреты, развёртывание | Образец для фаз 2, 3, 7, 8, 9 |
| [hermes-plugin-holographic](../notes/hermes-plugin-holographic.md) | [репозиторий](../references/hermes-plugin-holographic/) | Память как хранилище фактов: схема, поиск, гейт релевантности, доверие, версии, забывание; анализ переноса на PHP и PostgreSQL | Образец памяти (фаза 6); ядро кода прочитано, тесты и часть модулей — нет |
| [hermes-dreaming](../notes/hermes-dreaming.md) | [репозиторий](../references/hermes-dreaming/) | Ночная консолидация: конвейер, правила решений, паузы, безопасность изменений; что применять автоматически, а что предлагать владельцу | Образец консолидации (фазы 6, 11); главный скрипт прочитан на треть |
| [amphp-ssh](../notes/amphp-ssh.md) | [репозиторий](../references/amphp-ssh/) | SSH-клиент на AMPHP: API, аутентификация, туннели, свой транспорт; чего в библиотеке нет | Основа SSH-инструмента (фаза 7); код не запускался |
| [amphp-vless](../notes/amphp-vless.md) | [репозиторий](../references/amphp-vless/) | VLESS-клиент: транспорты, `vless-proxy`, произвольный TCP через туннель, ограничения | Основа маршрутов через VLESS (фазы 2, 7); код не запускался |
| [ssh-mcp](../notes/ssh-mcp.md) | [репозиторий](../references/ssh-mcp/) | MCP-сервер SSH: инструменты, классификация команд, политика, подтверждения, квоты, аудит и слабые места подхода | Образец политики команд (фазы 3, 7) и справка по MCP, которым пользуется Claude |
| [anthropic-building-effective-agents](../notes/anthropic-building-effective-agents.md) | [статья](../docs/anthropic-building-effective-agents.md) | Определения workflow/agent, каталог из пяти паттернов workflow и автономного агента, три принципа | Первоисточник терминологии; концептуально, без кода и цифр |
| [claude-cookbooks](../notes/claude-cookbooks.md) | [репозиторий](../references/claude-cookbooks/) | Карта репозитория, разбор `patterns/agents/`, Agent SDK, Managed Agents, таблица «вопрос → файл» | Первоисточник с кодом; агентская часть (паттерны, Agent SDK, Managed Agents, память, компакция, стоимость) прочитана полностью, остальное обзорно |
| [claude-cookbook-orchestrator-workers](../notes/claude-cookbook-orchestrator-workers.md) | [статья](../docs/claude-cookbook-orchestrator-workers.md) | Веб-версия ноутбука orchestrator-workers | Вводный пример; в копии нет кода — код в [ноутбуке](../references/claude-cookbooks/patterns/agents/orchestrator_workers.ipynb) |
| [swarms-orchestrator-worker-pattern](../notes/swarms-orchestrator-worker-pattern.md) | [статья](../docs/swarms-orchestrator-worker-pattern.md) | `PhasedFanOutWorkflow`: план как валидируемый JSON, сотни воркеров, верификаторы | Глубоко, но про один продукт; блог вендора |
| [backendbytes-multi-agent-orchestrator-backend](../notes/backendbytes-multi-agent-orchestrator-backend.md) | [статья](../docs/backendbytes-multi-agent-orchestrator-backend.md) | Оркестратор как бэкенд-компонент на Go: девять листингов | Самая инженерная статья; концепция и цифры — из Anthropic |
| [metacto-ai-agent-orchestration-patterns](../notes/metacto-ai-agent-orchestration-patterns.md) | [статья](../docs/metacto-ai-agent-orchestration-patterns.md) | Четыре топологии, их отказы и ранние сигналы, пять вопросов для выбора | Обзор без своих данных и кода; явный маркетинг |
| [microsoft-azure-ai-agent-design-patterns](../notes/microsoft-azure-ai-agent-design-patterns.md) | [статья](../docs/microsoft-azure-ai-agent-design-patterns.md) | Пять паттернов Azure, «когда избегать», сквозные требования | Вендорское руководство; самый широкий охват, без кода и цифр |
| [vantaige-orchestrator-workers-2026](../notes/vantaige-orchestrator-workers-2026.md) | [статья](../docs/vantaige-orchestrator-workers-2026.md) | Паттерн на примере Claude Code: `.claude/agents/`, изоляция контекста | Пересказ Anthropic; неглубоко, SEO |
| [glukhov-multi-agent-orchestration-patterns](../notes/glukhov-multi-agent-orchestration-patterns.md) | [статья](../docs/glukhov-multi-agent-orchestration-patterns.md) | Шесть паттернов по схеме «как работает / когда / как ломается», MAST, A2A, шесть диаграмм | Компиляция; цифры без источников, есть внутренние противоречия (перечислены в конспекте) |
| [compilenrun-agents-orchestrator-workers](../notes/compilenrun-agents-orchestrator-workers.md) | [статья](../docs/compilenrun-agents-orchestrator-workers.md) | Сквозная реализация на Python: Plan → Execute → Review → Synthesize | Учебный материал, лучший по практике на Python; имена моделей и параметры API не проверены |
| — | [заглушка](../docs/plainenglish-langgraph-orchestrator-worker.md) | Built with LangGraph #31 | Не скачана (403), конспекта нет |

### По вопросам

| Вопрос | Куда идти |
|--------|-----------|
| Как устроить ядро агента: цикл, инструменты, контекст, состояние | [hermes-agent](../notes/hermes-agent.md) |
| Как устроить канал связи и Telegram-адаптер | [hermes-agent-gateway-security](../notes/hermes-agent-gateway-security.md) |
| Как устроить память: факты, поиск, доверие, версии, забывание | [hermes-plugin-holographic](../notes/hermes-plugin-holographic.md), [hermes-agent-memory-skills](../notes/hermes-agent-memory-skills.md) |
| Как консолидировать память в фоне | [hermes-dreaming](../notes/hermes-dreaming.md), [claude-cookbooks](../notes/claude-cookbooks.md) (dreaming с разделением ролей) |
| Как классифицировать команды и подтверждать опасные действия | [ssh-mcp](../notes/ssh-mcp.md), [hermes-agent-gateway-security](../notes/hermes-agent-gateway-security.md), [claude-cookbooks](../notes/claude-cookbooks.md) (`CMA_gate_human_in_the_loop`) |
| Как работать с серверами по SSH, в том числе через VLESS | [amphp-ssh](../notes/amphp-ssh.md), [amphp-vless](../notes/amphp-vless.md) |
| Как устроить скиллы, MCP и самоулучшение | [hermes-agent-memory-skills](../notes/hermes-agent-memory-skills.md), [claude-cookbooks](../notes/claude-cookbooks.md) |
| Как версионировать промпты и ограничивать расходы | [claude-cookbooks](../notes/claude-cookbooks.md) (`CMA_prompt_versioning_and_rollback`, `CMA_cap_session_spend`) |
| Что такое workflow, agent, какие бывают паттерны | [anthropic](../notes/anthropic-building-effective-agents.md) → затем [microsoft-azure](../notes/microsoft-azure-ai-agent-design-patterns.md) и [glukhov](../notes/glukhov-multi-agent-orchestration-patterns.md) для других классификаций |
| Нужна ли мультиагентность вообще | [backendbytes](../notes/backendbytes-multi-agent-orchestrator-backend.md) (три оси отказа одного агента, чек-лист из пяти «да»), [microsoft-azure](../notes/microsoft-azure-ai-agent-design-patterns.md) (уровни сложности), [glukhov](../notes/glukhov-multi-agent-orchestration-patterns.md) (четыре условия перехода) |
| Какую топологию выбрать | [metacto](../notes/metacto-ai-agent-orchestration-patterns.md) (пять вопросов), [glukhov](../notes/glukhov-multi-agent-orchestration-patterns.md) (decision framework), [claude-cookbooks](../notes/claude-cookbooks.md) (агент / субагенты / workflow — `08_Dynamic_workflows`) |
| Как реализовать orchestrator-workers на Python | [compilenrun](../notes/compilenrun-agents-orchestrator-workers.md), минимальный вариант — [claude-cookbooks](../notes/claude-cookbooks.md) (`orchestrator_workers.ipynb`, с перечнем его дефектов) |
| Как реализовать на Go, с бюджетом, таймаутами, ретраями | [backendbytes](../notes/backendbytes-multi-agent-orchestrator-backend.md) |
| Как писать задание воркеру (brief) | [compilenrun](../notes/compilenrun-agents-orchestrator-workers.md), [claude-cookbooks](../notes/claude-cookbooks.md) (`prompts/research_lead_agent.md`, `CMA_plan_big_execute_small`) |
| Как валидировать план до запуска, масштаб в сотни воркеров | [swarms](../notes/swarms-orchestrator-worker-pattern.md) |
| Как устроить верификацию и оценку | [claude-cookbooks](../notes/claude-cookbooks.md) (outcome grader, evaluator-optimizer), [swarms](../notes/swarms-orchestrator-worker-pattern.md) (верификаторы), [microsoft-azure](../notes/microsoft-azure-ai-agent-design-patterns.md) (maker-checker) |
| Как ломаются мультиагентные системы и по каким сигналам это видно | [metacto](../notes/metacto-ai-agent-orchestration-patterns.md), [glukhov](../notes/glukhov-multi-agent-orchestration-patterns.md) (MAST), [backendbytes](../notes/backendbytes-multi-agent-orchestrator-backend.md) |
| Сколько это стоит и как экономить | [backendbytes](../notes/backendbytes-multi-agent-orchestrator-backend.md) (4× / 15×), [claude-cookbooks](../notes/claude-cookbooks.md) (`CMA_plan_big_execute_small`, `cost_optimization`), [glukhov](../notes/glukhov-multi-agent-orchestration-patterns.md) (множители по паттернам, без источников) |
| Управление контекстом, компакция, память | [claude-cookbooks](../notes/claude-cookbooks.md) (`tool_use/context_engineering`, memory) |
| Наблюдаемость | [backendbytes](../notes/backendbytes-multi-agent-orchestrator-backend.md) (OpenTelemetry, метрики), [glukhov](../notes/glukhov-multi-agent-orchestration-patterns.md), [swarms](../notes/swarms-orchestrator-worker-pattern.md) (`last_plan`, `last_run`, `usage`) |
| Безопасность, least privilege, участие человека | [microsoft-azure](../notes/microsoft-azure-ai-agent-design-patterns.md), [claude-cookbooks](../notes/claude-cookbooks.md) (хуки, `CMA_gate_human_in_the_loop`, разграничение инструментов) |
| Субагенты в Claude Code и Agent SDK, Managed Agents | [claude-cookbooks](../notes/claude-cookbooks.md), [vantaige](../notes/vantaige-orchestrator-workers-2026.md) (`.claude/agents/`) |
| Межсервисное взаимодействие агентов (A2A), выбор фреймворка | [glukhov](../notes/glukhov-multi-agent-orchestration-patterns.md), [microsoft-azure](../notes/microsoft-azure-ai-agent-design-patterns.md) |

## Ключевые выводы

Выводы 1–16 сведены из конспектов статей об оркестрации; в скобках — где это сказано. Большинство статей пересказывает
«Building effective agents» Anthropic, поэтому совпадение нескольких источников часто означает один
первоисточник, а не независимое подтверждение.

1. **Мультиагентность — не выбор по умолчанию.** Большинство источников требуют начинать с одного вызова или одного агента и усложнять только при измеримой пользе. Плата одна и та же: задержка, стоимость, новые режимы отказа, сложность отладки. Траектория: один агент → фиксированный пайплайн → оркестратор (anthropic, backendbytes, microsoft-azure, glukhov, metacto).
2. **Orchestrator-workers нужен, когда подзадачи нельзя задать заранее** (MetaCTO, `docs/metacto-ai-agent-orchestration-patterns.md:L158`, ставит границу иначе: orchestrator-worker — когда весь план можно задать до запуска, supervisor — когда план меняется по ходу; расхождение в терминах, не в сути). Если состав и число подзадач известны до запуска, дешевле и предсказуемее обычная параллелизация в коде. Это единственный критерий, в котором сходятся почти все (anthropic, cookbook, swarms, vantaige, compilenrun). Исключение — glukhov, который рекомендует паттерн и для «известной декомпозиции».
3. **Главный вопрос архитектуры — кто держит план.** Модель ход за ходом (агент, субагенты, supervisor) или детерминированный код (workflow, план-данные). Orchestrator-worker планирует один раз до запуска, supervisor перепланирует после каждого шага — это разные паттерны с разной стоимостью (claude-cookbooks, metacto, swarms, compilenrun).
4. **Ценность паттерна — изоляция контекста, а не параллелизм.** Воркер читает много и возвращает сжатую выжимку (порядка 50K токенов на входе, 2K на выходе). Узкое место системы — контекст оркестратора, поэтому наверх идут сводки или структурированные результаты, а не транскрипты (backendbytes, vantaige, claude-cookbooks, glukhov).
5. **Задание воркеру — весь его мир.** Воркер не видит разговора с пользователем. Brief должен содержать цель, контекст, формат вывода, границы и инструменты; границы исключают дублирование работы (compilenrun, claude-cookbooks).
6. **Промпт просит, код гарантирует.** Лимиты на число воркеров, ходов, раундов, токенов и время задаются в коде или на платформе. Проверка бюджета в цикле диспетчеризации не работает — нужен атомарный резерв внутри воркера (compilenrun, backendbytes, swarms, claude-cookbooks).
7. **Отказ воркера — значение, а не исключение.** Один упавший воркер не должен ронять запуск; результат возвращается частичным, с явным перечнем пробелов. Ретраи — только для транзиентных ошибок и с ключом идемпотентности (backendbytes, swarms, compilenrun).
8. **План лучше делать данными и проверять до запуска.** JSON со схемой и валидацией надёжнее XML с разбором регулярками; качество плана — единая точка отказа всей системы (swarms, cookbook, glukhov, compilenrun).
9. **Верификация должна быть структурной.** Инструкция «перепроверь себя» под давлением контекста пропускается, отдельный проверяющий с чистым контекстом — нет. Проверяющий по умолчанию склонен одобрять, поэтому рубрика требует доказательств; проверяется только то, что в неё включено (claude-cookbooks, swarms, microsoft-azure, glukhov).
10. **Синтез — не конкатенация.** Оркестратор должен разрешать противоречия между воркерами. В учебном ноутбуке cookbook синтеза нет вовсе, и воркеры в его примере противоречат друг другу (vantaige, glukhov, compilenrun, claude-cookbook-orchestrator-workers).
11. **Сильная модель — на план, проверку и синтез, дешёвая — на массовую работу.** В замере из cookbook 84–98% входных токенов шли по ставке дешёвого воркера. У дробления есть оптимум: каждый поток несёт фиксированные накладные расходы, и слишком мелкая нарезка увеличивает счёт (claude-cookbooks, swarms, compilenrun, vantaige).
12. **Стоимость высока и плохо сопоставима между источниками.** Данные Anthropic: около 4× токенов чата у одного агента и около 15× у мультиагентной системы. Множители glukhov (2–3× для orchestrator-worker) даны без источников и от другой базы (backendbytes, compilenrun, glukhov).
13. **Параллельные воркеры безопасны на чтении.** Параллельные правки конфликтуют, общее изменяемое состояние — антипаттерн (compilenrun, microsoft-azure). Vantaige при этом приводит «по воркеру на файл» как норму — см. [расхождения](#расхождения-между-источниками).
14. **Инструменты важнее промпта.** Описания инструментов и их разграничение по ролям дают больше, чем длинный системный промпт; ограниченный набор инструментов — ещё и граница безопасности (anthropic, claude-cookbooks, microsoft-azure).
15. **Наблюдаемость закладывается с первого дня.** Первым логируется план; дальше — span на каждого воркера, токены по агентам, доля токенов оркестратора как ранний сигнал перегруза (vantaige, backendbytes, metacto, glukhov).
16. **Реальные системы комбинируют паттерны.** Топология, механизм исполнения (конечный автомат, durable execution) и транспорт (один процесс или A2A) — независимые решения (microsoft-azure, glukhov, metacto).

### Выводы из референсов для Меркурия

Получены из конспектов референсных репозиториев; продолжают нумерацию.

17. **Любое действие агента идёт через один конвейер:** очистка → проверка → политика → подтверждение → квота → запуск → журнал. Одна и та же строка описания действия видна политике, человеку и журналу (ssh-mcp).
18. **«Безопасно по умолчанию» не работает.** В `ssh-mcp` всё нераспознанное считается безопасным, и за пять версий подряд вышло пять исправлений одного вида: опасная команда в непривычном написании. Интерактивная сессия обходит классификацию вовсе (ssh-mcp).
19. **Запрет, записанный только в промпте, — не запрет.** В трёх примерах Anthropic ограничение держится на тексте, а обработчик действие выполнит. Проверка должна стоять в инструменте (claude-cookbooks).
20. **Подтверждение — приостановленный вызов инструмента.** Его идентификатор — токен возобновления: кладётся в кнопку, ожидание живёт в журнале и переживает перезапуск (claude-cookbooks).
21. **Каждому ограничителю нужна пара «стоп и сигнал».** Потолок расходов — пауза, а не ошибка: после поднятия лимита работа продолжается. Стоп без сигнала обрывает молча (claude-cookbooks).
22. **Память — хранилище атомарных фактов**, а не журнал разговоров: у факта доверие, происхождение (сказано, подтверждено, выведено агентом), источник и история версий (hermes-plugin-holographic).
23. **В промпт идёт не «топ похожих», а то, что прошло гейт релевантности.** Болтовня не вспоминает ничего; вспомненное помечается как данные, а не инструкции, и проверяется и при записи, и на выходе (hermes-plugin-holographic).
24. **Поиск по памяти в первую очередь лексический.** Качество держится на анализе слов (стемы, однокоренные, ключевые слова, которые агент дописывает при записи), а не на векторах: HRR — одно из трёх слагаемых оценки, эмбеддинги выключены по умолчанию (hermes-plugin-holographic).
25. **Память меняется пятью разными действиями, а не перезаписью:** изменить, завершить, исправить, слить, забыть. Забывание окончательное, с отпечатками, чтобы фоновые задачи не вернули забытое (hermes-plugin-holographic).
26. **В консолидации код считает, модель формулирует, человек решает спорное.** Отбор, оценки, пороги и паузы — чистая функция без модели; нет работы — модель не запускается (hermes-dreaming).
27. **Подтверждают только люди.** Долговременной памяти заслуживает то, что человек повторял в разные дни; машинный текст и выводы агента подтверждением не считаются (hermes-dreaming).
28. **Память — канал внедрения инструкций во все будущие сессии.** Консолидатору не дают сеть и оболочку, рабочие сессии читают память без права записи (claude-cookbooks, hermes-dreaming).
29. **Версия промпта неизменяема, меняется только указатель на неё.** Продвижение — смена номера после проверки, откат — возврат номера; оценку считать по категориям задач (claude-cookbooks).
30. **Кеш промпта — первый рычаг стоимости.** Изменчивое идёт в хвост; модель и набор инструментов посреди сессии не меняются; живые числа в системном блоке ломают кеш (claude-cookbooks, hermes-plugin-holographic).
31. **Сетевой слой строится вокруг одного интерфейса — `SocketConnector`.** Через него стыкуются VLESS, прокси и SSH, включая SSH поверх VLESS (amphp-vless, amphp-ssh).
32. **У библиотек есть пробелы, которые закрываем сами:** в `amphp-ssh` нет SFTP, таймаутов, keepalive, пула и надёжной остановки команды; `amphp-vless` не поддерживает REALITY и Vision и не содержит клиентов HTTP- и SOCKS5-прокси (amphp-ssh, amphp-vless).

### Выводы из эксплуатации

Что показала работа на сервере (2026-11-16), в дополнение к выводам из материалов:

- **Дешёвые модели многословнее:** на замере фазы 5 они потратили почти вдвое больше токенов, чем сильная модель;
  экономия идёт от цены токена, а не от их числа.
- **Записи каталога `:batch` и семейства только для `/v1/responses`** (codex, `-pro`) не годятся для интерактивного
  запуска — их нельзя выбирать.
- **Зависание провайдера:** OpenRouter держит соединение keep-alive-комментариями, а ответа нет минутами; таймаут
  тишины по байтам этого не видит — нужен таймаут по событиям потока.
- **Выдуманное действие** (2026-11-17): glm-5.3-flash ответил «задача добавлена со сроком», не вызвав
  `project_edit`, — в базе ничего не появилось. После правила в системном промпте (v4, по образцу hermes
  `TOOL_USE_ENFORCEMENT_GUIDANCE`) та же просьба выполнена вызовом инструмента. Проверять итог по базе или трассе, а
  не по ответу модели.
- **Доля делегирования** за первые дни — 21% запусков: простые задачи агент решает сам.
- **Сравнение «одна модель против нескольких»** честно только при равной строгости: несколько прогонов, общий
  судья (cookbook, идеи 10 и 44); замер фазы 5 — четыре простые задачи по одному прогону, предварительный.

## Расхождения между источниками

- **Классификация.** Anthropic — пять workflow-паттернов и агент. Microsoft — sequential, concurrent, group chat, handoff, magentic; паттерна с именем orchestrator-worker у них нет. Metacto и glukhov — топологии (orchestrator-worker, supervisor, hierarchical, swarm, mesh), причём metacto считает sequential/parallel отдельным слоем, а не паттерном. Единой терминологии нет.
- **Supervisor.** Vantaige считает его той же ролью, что orchestrator; metacto и compilenrun — отдельным паттерном с пошаговым планированием.
- **Синтез и обратная связь.** У Anthropic и Swarms оркестратор синтезирует результат; в cookbook синтеза нет. Compilenrun включает цикл «проверка → дополнительный раунд» в паттерн; vantaige настаивает на однонаправленном потоке.
- **Масштаб.** Cookbook — 2–3 воркера последовательно; compilenrun — потолок 5; glukhov — переполнение контекста уже при 4+ и иерархия после 5; swarms — сотни параллельно (до 1000).
- **Одноранговые передачи.** Metacto называет swarm самым опасным в эксплуатации (не более 2–5 агентов); Microsoft описывает handoff как штатный паттерн для поддержки клиентов.
- **Фреймворки.** Anthropic советует начинать с прямых вызовов API; swarms и Microsoft продвигают готовые классы и платформы.
- **Параллельная запись.** Vantaige допускает воркера на файл; compilenrun (со ссылкой на Cognition) предостерегает от параллельных правок.
