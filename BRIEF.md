# BRIEF — контекст проекта mercurius

Единый источник контекста проекта. `CLAUDE.md` ссылается на разделы этого файла по якорям,
поэтому **заголовки разделов не переименовывать** (иначе сломаются ссылки) — только дополнять содержимое.

## Цель

Собрать базу знаний по автономным агентским системам и их построению: архитектурные паттерны
(orchestrator-workers, routing, parallelization, evaluator-optimizer и др.), практики реализации,
примеры кода, сравнение подходов и фреймворков.

## Область исследования

- Паттерны мультиагентной оркестрации (в первую очередь orchestrator-workers).
- Когда нужен агент, а когда достаточно workflow.
- Декомпозиция задач, делегирование, агрегация результатов.
- Надёжность: обработка ошибок, ретраи, контроль стоимости, наблюдаемость.
- Бэкенд-реализация: очереди, состояние, параллелизм.
- Фреймворки: Claude Agent SDK, LangGraph, Swarms, Azure/Microsoft Agent Framework.

## Структура репозитория

| Путь | Что там |
|------|---------|
| `docs/` | Статьи, сохранённые в Markdown (один файл = одна статья, во frontmatter — `source` и дата) |
| `references/` | Репозитории-первоисточники, каждый — git-сабмодуль в своей папке |
| `notes/` | Конспекты: один файл на статью или репозиторий, с описанием и навигацией по источнику |
| `CLAUDE.md` | Инструкции для Claude и навигация по материалам |
| `BRIEF.md` | Этот файл — контекст, карта конспектов, выводы, решения |

## Соглашения

- Новая статья → `docs/<источник>-<тема>.md` с frontmatter (`title`, `source`, `fetched`), затем ссылка в `CLAUDE.md`.
- Статьи извлекать с сохранением блоков кода, диаграмм и заголовков (связка BeautifulSoup + markdownify по контейнеру статьи; trafilatura теряет код и заголовки).
- Новый репозиторий → git-сабмодуль: `git submodule add --depth 1 <url> references/<имя-репо>` и `git config -f .gitmodules submodule.references/<имя-репо>.shallow true`, затем ссылка в `CLAUDE.md`.
- После клонирования проекта: `git submodule update --init --depth 1`.
- На каждый материал → конспект `notes/<то же имя>.md`: «Кратко», навигация по источнику (разделы с номерами строк в `docs/` или карта каталогов репозитория), ключевые идеи, практика, «под какие вопросы открывать», оценка. Затем строка в [карте конспектов](#карта-конспектов).
- Номера строк в конспектах привязаны к файлу в `docs/`: если статью перекачали — пересчитать навигацию в конспекте.
- Если материал не удалось скачать — заглушка с `status: not-downloaded` и ссылкой на оригинал.
- Выводы и решения записываются в этот файл, а не в `CLAUDE.md`.

## Карта конспектов

Порядок чтения: сначала конспект, из него — по номерам строк в статью или по ссылкам в репозиторий.

### По источникам

| Конспект | Источник | Что это | Тип и оценка |
|----------|----------|---------|--------------|
| [anthropic-building-effective-agents](notes/anthropic-building-effective-agents.md) | [статья](docs/anthropic-building-effective-agents.md) | Определения workflow/agent, каталог из шести паттернов и автономного агента, три принципа | Первоисточник терминологии; концептуально, без кода и цифр |
| [claude-cookbooks](notes/claude-cookbooks.md) | [репозиторий](references/claude-cookbooks/) | Карта репозитория, разбор `patterns/agents/`, Agent SDK, Managed Agents, таблица «вопрос → файл» | Первоисточник с кодом; агентская часть прочитана глубоко, остальное обзорно (что не прочитано — в разделе 6 конспекта) |
| [claude-cookbook-orchestrator-workers](notes/claude-cookbook-orchestrator-workers.md) | [статья](docs/claude-cookbook-orchestrator-workers.md) | Веб-версия ноутбука orchestrator-workers | Вводный пример; в копии нет кода — код в [ноутбуке](references/claude-cookbooks/patterns/agents/orchestrator_workers.ipynb) |
| [swarms-orchestrator-worker-pattern](notes/swarms-orchestrator-worker-pattern.md) | [статья](docs/swarms-orchestrator-worker-pattern.md) | `PhasedFanOutWorkflow`: план как валидируемый JSON, сотни воркеров, верификаторы | Глубоко, но про один продукт; блог вендора |
| [backendbytes-multi-agent-orchestrator-backend](notes/backendbytes-multi-agent-orchestrator-backend.md) | [статья](docs/backendbytes-multi-agent-orchestrator-backend.md) | Оркестратор как бэкенд-компонент на Go: девять листингов | Самая инженерная статья; концепция и цифры — из Anthropic |
| [metacto-ai-agent-orchestration-patterns](notes/metacto-ai-agent-orchestration-patterns.md) | [статья](docs/metacto-ai-agent-orchestration-patterns.md) | Четыре топологии, их отказы и ранние сигналы, пять вопросов для выбора | Обзор без своих данных и кода; явный маркетинг |
| [microsoft-azure-ai-agent-design-patterns](notes/microsoft-azure-ai-agent-design-patterns.md) | [статья](docs/microsoft-azure-ai-agent-design-patterns.md) | Пять паттернов Azure, «когда избегать», сквозные требования | Вендорское руководство; самый широкий охват, без кода и цифр |
| [vantaige-orchestrator-workers-2026](notes/vantaige-orchestrator-workers-2026.md) | [статья](docs/vantaige-orchestrator-workers-2026.md) | Паттерн на примере Claude Code: `.claude/agents/`, изоляция контекста | Пересказ Anthropic; неглубоко, SEO |
| [glukhov-multi-agent-orchestration-patterns](notes/glukhov-multi-agent-orchestration-patterns.md) | [статья](docs/glukhov-multi-agent-orchestration-patterns.md) | Шесть паттернов по схеме «как работает / когда / как ломается», MAST, A2A, шесть диаграмм | Компиляция; цифры без источников, есть внутренние противоречия (перечислены в конспекте) |
| [compilenrun-agents-orchestrator-workers](notes/compilenrun-agents-orchestrator-workers.md) | [статья](docs/compilenrun-agents-orchestrator-workers.md) | Сквозная реализация на Python: Plan → Execute → Review → Synthesize | Учебный материал, лучший по практике на Python; имена моделей и параметры API не проверены |
| — | [заглушка](docs/plainenglish-langgraph-orchestrator-worker.md) | Built with LangGraph #31 | Не скачана (403), конспекта нет |

### По вопросам

| Вопрос | Куда идти |
|--------|-----------|
| Что такое workflow, agent, какие бывают паттерны | [anthropic](notes/anthropic-building-effective-agents.md) → затем [microsoft-azure](notes/microsoft-azure-ai-agent-design-patterns.md) и [glukhov](notes/glukhov-multi-agent-orchestration-patterns.md) для других классификаций |
| Нужна ли мультиагентность вообще | [backendbytes](notes/backendbytes-multi-agent-orchestrator-backend.md) (три оси отказа одного агента, чек-лист из пяти «да»), [microsoft-azure](notes/microsoft-azure-ai-agent-design-patterns.md) (уровни сложности), [glukhov](notes/glukhov-multi-agent-orchestration-patterns.md) (четыре условия перехода) |
| Какую топологию выбрать | [metacto](notes/metacto-ai-agent-orchestration-patterns.md) (пять вопросов), [glukhov](notes/glukhov-multi-agent-orchestration-patterns.md) (decision framework), [claude-cookbooks](notes/claude-cookbooks.md) (агент / субагенты / workflow — `08_Dynamic_workflows`) |
| Как реализовать orchestrator-workers на Python | [compilenrun](notes/compilenrun-agents-orchestrator-workers.md), минимальный вариант — [claude-cookbooks](notes/claude-cookbooks.md) (`orchestrator_workers.ipynb`, с перечнем его дефектов) |
| Как реализовать на Go, с бюджетом, таймаутами, ретраями | [backendbytes](notes/backendbytes-multi-agent-orchestrator-backend.md) |
| Как писать задание воркеру (brief) | [compilenrun](notes/compilenrun-agents-orchestrator-workers.md), [claude-cookbooks](notes/claude-cookbooks.md) (`prompts/research_lead_agent.md`, `CMA_plan_big_execute_small`) |
| Как валидировать план до запуска, масштаб в сотни воркеров | [swarms](notes/swarms-orchestrator-worker-pattern.md) |
| Как устроить верификацию и оценку | [claude-cookbooks](notes/claude-cookbooks.md) (outcome grader, evaluator-optimizer), [swarms](notes/swarms-orchestrator-worker-pattern.md) (верификаторы), [microsoft-azure](notes/microsoft-azure-ai-agent-design-patterns.md) (maker-checker) |
| Как ломаются мультиагентные системы и по каким сигналам это видно | [metacto](notes/metacto-ai-agent-orchestration-patterns.md), [glukhov](notes/glukhov-multi-agent-orchestration-patterns.md) (MAST), [backendbytes](notes/backendbytes-multi-agent-orchestrator-backend.md) |
| Сколько это стоит и как экономить | [backendbytes](notes/backendbytes-multi-agent-orchestrator-backend.md) (4× / 15×), [claude-cookbooks](notes/claude-cookbooks.md) (`CMA_plan_big_execute_small`, `cost_optimization`), [glukhov](notes/glukhov-multi-agent-orchestration-patterns.md) (множители по паттернам, без источников) |
| Управление контекстом, компакция, память | [claude-cookbooks](notes/claude-cookbooks.md) (`tool_use/context_engineering`, memory) |
| Наблюдаемость | [backendbytes](notes/backendbytes-multi-agent-orchestrator-backend.md) (OpenTelemetry, метрики), [glukhov](notes/glukhov-multi-agent-orchestration-patterns.md), [swarms](notes/swarms-orchestrator-worker-pattern.md) (`last_plan`, `last_run`, `usage`) |
| Безопасность, least privilege, участие человека | [microsoft-azure](notes/microsoft-azure-ai-agent-design-patterns.md), [claude-cookbooks](notes/claude-cookbooks.md) (хуки, `CMA_gate_human_in_the_loop`, разграничение инструментов) |
| Субагенты в Claude Code и Agent SDK, Managed Agents | [claude-cookbooks](notes/claude-cookbooks.md), [vantaige](notes/vantaige-orchestrator-workers-2026.md) (`.claude/agents/`) |
| Межсервисное взаимодействие агентов (A2A), выбор фреймворка | [glukhov](notes/glukhov-multi-agent-orchestration-patterns.md), [microsoft-azure](notes/microsoft-azure-ai-agent-design-patterns.md) |

## Ключевые выводы

Выводы сведены из десяти конспектов; в скобках — где это сказано. Большинство статей пересказывает
«Building effective agents» Anthropic, поэтому совпадение нескольких источников часто означает один
первоисточник, а не независимое подтверждение.

1. **Мультиагентность — не выбор по умолчанию.** Все источники требуют начинать с одного вызова или одного агента и усложнять только при измеримой пользе. Плата одна и та же: задержка, стоимость, новые режимы отказа, сложность отладки. Траектория: один агент → фиксированный пайплайн → оркестратор (anthropic, backendbytes, microsoft-azure, glukhov, metacto).
2. **Orchestrator-workers нужен, когда подзадачи нельзя задать заранее.** Если состав и число подзадач известны до запуска, дешевле и предсказуемее обычная параллелизация в коде. Это единственный критерий, в котором сходятся почти все (anthropic, cookbook, swarms, vantaige, compilenrun). Исключение — glukhov, который рекомендует паттерн и для «известной декомпозиции».
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

## Расхождения между источниками

- **Классификация.** Anthropic — пять workflow-паттернов и агент. Microsoft — sequential, concurrent, group chat, handoff, magentic; паттерна с именем orchestrator-worker у них нет. Metacto и glukhov — топологии (orchestrator-worker, supervisor, hierarchical, swarm, mesh), причём metacto считает sequential/parallel отдельным слоем, а не паттерном. Единой терминологии нет.
- **Supervisor.** Vantaige считает его той же ролью, что orchestrator; metacto и compilenrun — отдельным паттерном с пошаговым планированием.
- **Синтез и обратная связь.** У Anthropic и Swarms оркестратор синтезирует результат; в cookbook синтеза нет. Compilenrun включает цикл «проверка → дополнительный раунд» в паттерн; vantaige настаивает на однонаправленном потоке.
- **Масштаб.** Cookbook — 2–3 воркера последовательно; compilenrun — потолок 5; glukhov — переполнение контекста уже при 4+ и иерархия после 5; swarms — сотни параллельно (до 1000).
- **Одноранговые передачи.** Metacto называет swarm самым опасным в эксплуатации (не более 2–5 агентов); Microsoft описывает handoff как штатный паттерн для поддержки клиентов.
- **Фреймворки.** Anthropic советует начинать с прямых вызовов API; swarms и Microsoft продвигают готовые классы и платформы.
- **Параллельная запись.** Vantaige допускает воркера на файл; compilenrun (со ссылкой на Cognition) предостерегает от параллельных правок.

## Решения

- Материалы хранятся в трёх слоях: источник (`docs/`, `references/`) → конспект (`notes/`) → сводка (`BRIEF.md`).
- Репозитории подключаются как shallow-сабмодули, а не копируются в проект.

## Открытые вопросы

- Статья LangGraph #31 (ai.plainenglish.io) не скачана — Medium отвечает 403, нужно добавить текст вручную.
- `claude-cookbooks` изучен не целиком: часть ноутбуков Managed Agents и вспомогательный код Agent SDK не читались (список — в разделе 6 [конспекта](notes/claude-cookbooks.md)).
- В cookbooks расходится форма мультиагентной конфигурации Managed Agents (`{"type": "coordinator"}` против `multiagent_20261001`) — сверить с актуальной документацией API.
- Имена моделей и параметры API в коде compilenrun не проверены.
- Первоисточники, на которые ссылаются статьи, но которых нет в базе: статья Anthropic о мультиагентной research-системе (цифры 15× и 90,2%), Cognition «Don't Build Multi-Agents», таксономия MAST.
- Нет материалов по LangGraph и по supervisor/handoff-паттернам с кодом.
