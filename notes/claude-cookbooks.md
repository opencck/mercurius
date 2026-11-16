---
title: "Claude Cookbooks — конспект репозитория (точка входа по теме агентов)"
type: repository
source: https://github.com/anthropics/claude-cookbooks
local: references/claude-cookbooks/
---

# Claude Cookbooks — конспект

> Конспект составлен по локальному клону `references/claude-cookbooks/`. Глубина чтения разная: что прочитано целиком, что по markdown-ячейкам, а что только по описанию — честно расписано в разделе [«Ограничения и оговорки»](#6-ограничения-и-оговорки). Все пути ниже — относительные ссылки от `notes/`.

## 1. Кратко

Claude Cookbooks — официальный репозиторий Anthropic с рецептами работы с Claude API: 104 ноутбука `.ipynb` плюс сопутствующий Python-код, каталог ноутбуков с описаниями лежит в [registry.yaml](../references/claude-cookbooks/registry.yaml). Для темы агентских систем он ценен тем, что один и тот же набор идей (оркестратор и воркеры, субагенты, верификация, память, бюджеты) показан на трёх уровнях абстракции: «голый» Messages API ([patterns/agents/](../references/claude-cookbooks/patterns/agents/)), Claude Agent SDK ([claude_agent_sdk/](../references/claude-cookbooks/claude_agent_sdk/)) и хостинговый рантайм Claude Managed Agents ([managed_agents/](../references/claude-cookbooks/managed_agents/)). `patterns/agents/` — референсная реализация статьи «Building Effective Agents» (минимальные учебные workflow), а `managed_agents/` и `claude_agent_sdk/08_Dynamic_workflows.ipynb` показывают то же самое в production-образной форме с измерением стоимости. Отдельно полезны материалы про контекст и память агента (`tool_use/`), про стоимость и латентность агентного цикла (`cost_optimization/`, `latency_optimization/`) и про оценку (`tool_evaluation/`, `evals/`). Большинство ноутбуков — учебные демонстрации на синтетических данных, а не готовые к продакшену системы.

## 2. Карта репозитория

Число файлов — по локальному клону (без `.git`).

| Путь | Что внутри | Релевантность для агентов |
|---|---|---|
| [patterns/](../references/claude-cookbooks/patterns/) | Единственный подкаталог `agents/`: 5 ноутбуков с паттернами из «Building Effective Agents» (chaining, routing, parallelization, orchestrator-workers, evaluator-optimizer, async-команды), `util.py`, `prompts/` с промптами research-системы | **высокая** |
| [claude_agent_sdk/](../references/claude-cookbooks/claude_agent_sdk/) | 9 нумерованных ноутбуков (00–08) + standalone-рецепт про scheduled reviewer; companion-код агентов, MCP-сервер SRE, три варианта хостинга (Docker/Modal/Kubernetes) | **высокая** |
| [managed_agents/](../references/claude-cookbooks/managed_agents/) | 21 ноутбук про Claude Managed Agents: мультиагентный координатор, advisor, outcomes/grader, бюджеты, память, HITL, production-обвязка; `utilities.py`, примеры данных, README интеграций | **высокая** |
| [tool_use/](../references/claude-cookbooks/tool_use/) | Tool use в Messages API: базовые инструменты, параллельные вызовы, `tool_choice`, programmatic tool calling, tool search, memory tool, context editing, compaction; агент threat-intel | **высокая** |
| [tool_evaluation/](../references/claude-cookbooks/tool_evaluation/) | Один ноутбук + `evaluation.xml`: прогон задач через агентный цикл для оценки качества инструментов | **высокая** |
| [evals/](../references/claude-cookbooks/evals/) | `agentic_search/` — воспроизведение бенчмарков agentic search (DeepSearchQA, BrowseComp) на Messages API, конфигурация harness | **высокая** |
| [cost_optimization/](../references/claude-cookbooks/cost_optimization/) | Один большой ноутбук: чек-лист снижения стоимости на агенте-«страховом аджастере», Pareto-фронт cost/pass-rate, включая субагентов и advisor | средняя (разделы про агентный цикл — высокая) |
| [latency_optimization/](../references/claude-cookbooks/latency_optimization/) | Один большой ноутбук + `desk_agent/` (задачи, инструменты, грейдеры): рычаги латентности агента, включая субагентов | средняя |
| [observability/](../references/claude-cookbooks/observability/) | OpenTelemetry-трейсинг запросов и сессий Managed Agents; Usage & Cost Admin API | средняя |
| [extended_thinking/](../references/claude-cookbooks/extended_thinking/) | Extended thinking и его сочетание с tool use (сохранение thinking-блоков между ходами) | средняя |
| [skills/](../references/claude-cookbooks/skills/) | 3 ноутбука про Claude Skills (Excel/PowerPoint/PDF, финансовые примеры, свои skills), `custom_skills/` | средняя |
| [misc/](../references/claude-cookbooks/misc/) | Разное: batch, prompt caching, building evals, JSON mode, metaprompt, citations, session memory compaction, admin API | средняя (compaction, evals, caching), остальное низкая |
| [multimodal/](../references/claude-cookbooks/multimodal/) | Vision: изображения, графики, транскрипция, crop tool; плюс `using_sub_agents.ipynb` (Haiku-субагенты под Opus) | низкая, кроме `using_sub_agents.ipynb` |
| [third_party/](../references/claude-cookbooks/third_party/) | Интеграции: LlamaIndex (в т.ч. ReAct agent, multi-document agents), Pinecone/LangChain RAG-агент, MongoDB, VoyageAI, WolframAlpha, Deepgram, ElevenLabs | низкая (агенты там — на чужих фреймворках) |
| [capabilities/](../references/claude-cookbooks/capabilities/) | Гайды с эвалами: classification, RAG, contextual embeddings, summarization, text-to-SQL, knowledge graph, content moderation | низкая |
| [coding/](../references/claude-cookbooks/coding/) | Один ноутбук про промптинг фронтенд-эстетики | низкая |
| [finetuning/](../references/claude-cookbooks/finetuning/) | Файнтюнинг Claude 3 Haiku на Bedrock | низкая |
| [fable_5_fallback_billing/](../references/claude-cookbooks/fable_5_fallback_billing/) | Classifier-блокировки Claude Fable 5, серверный fallback на другую модель, биллинг | низкая |
| [images/](../references/claude-cookbooks/images/) | Картинки для ноутбуков | низкая |
| [scripts/](../references/claude-cookbooks/scripts/), [tests/](../references/claude-cookbooks/tests/) | Валидация ноутбуков и авторов, тесты ноутбуков | низкая |
| [anthropic_cookbook/](../references/claude-cookbooks/anthropic_cookbook/) | Пустой пакет (`__init__.py`) | низкая |
| [.claude/](../references/claude-cookbooks/.claude/), [.github/](../references/claude-cookbooks/.github/) | Служебное: slash-команды ревью, агент `code-reviewer`, skill `cookbook-audit`, CI-воркфлоу | низкая (пример того, как сами авторы используют Claude Code в CI) |
| Корневые файлы | [README.md](../references/claude-cookbooks/README.md), [registry.yaml](../references/claude-cookbooks/registry.yaml) (каталог: title/description/path/categories), [CLAUDE.md](../references/claude-cookbooks/CLAUDE.md), [CONTRIBUTING.md](../references/claude-cookbooks/CONTRIBUTING.md), `authors.yaml`, `pyproject.toml`, `Makefile` | — |

Замечание: корневой README устарел относительно содержимого — в его оглавлении нет ни `patterns/`, ни `claude_agent_sdk/`, ни `managed_agents/`, а ссылки ведут на старое имя `anthropic-cookbook`. Актуальный каталог — `registry.yaml`.

## 3. Агентские материалы подробно

### 3.1. `patterns/agents/` — паттерны на «голом» Messages API

[README](../references/claude-cookbooks/patterns/agents/README.md) называет каталог референсной реализацией статьи «Building Effective Agents» (Erik Schluntz, Barry Zhang): минимальные примеры workflow, не фреймворк.

#### util.py

[util.py](../references/claude-cookbooks/patterns/agents/util.py) — две функции, на которых держатся три «классических» ноутбука:

- `llm_call(prompt, system_prompt="", model="claude-sonnet-5-5") -> str` — один вызов `client.messages.create` с `max_tokens=4096`, возвращает склеенный текст. Без tools, без ретраев, без стриминга.
- `extract_xml(text, tag) -> str` — regex `<tag>(.*?)</tag>` с `re.DOTALL`; если тега нет, возвращает пустую строку (то есть ошибка формата молча превращается в `""`).

#### orchestrator_workers.ipynb (подробно)

Файл: [orchestrator_workers.ipynb](../references/claude-cookbooks/patterns/agents/orchestrator_workers.ipynb). В проекте также есть сохранённая копия опубликованной версии: [docs/claude-cookbook-orchestrator-workers.md](../docs/claude-cookbook-orchestrator-workers.md).

**Идея.** Центральный LLM-оркестратор анализирует конкретную задачу и *в рантайме* решает, какие подзадачи нужны; воркеры-LLM выполняют каждую. Отличие от обычной параллелизации — подзадачи не заданы заранее, а выводятся из входа. Демонстрационная задача — сгенерировать несколько вариантов маркетингового описания товара в разных стилях.

**Когда применять (по тексту авторов).** Когда нужны разные подходы/перспективы, оптимальные подзадачи зависят от входа, нужно сравнить стратегии или стили. Не применять: для простых задач с одним выходом, когда критична латентность, когда подзадачи предсказуемы (тогда — обычная параллелизация).

**Две фазы.**

1. *Analysis & Planning.* Оркестратор получает задачу и возвращает XML: блок `<analysis>` и блок `<tasks>` со списком `<task><type>…</type><description>…</description></task>`.
2. *Execution.* Каждый воркер получает исходную задачу, тип своей подзадачи и её описание; отвечает внутри `<response>`.

**Реализация.**

- `MODEL = "claude-sonnet-5-5"` — одна модель и для оркестратора, и для воркеров.
- `parse_tasks(tasks_xml) -> list[dict]` — построчный парсер: реагирует на строки, начинающиеся с `<task>`, `<type>`, `<description>`, `</task>`; задача без `description` отбрасывается, без `type` получает `"default"`.
- `class FlexibleOrchestrator(orchestrator_prompt, worker_prompt, model)`:
  - `_format_prompt(template, **kwargs)` — `str.format`, `KeyError` превращается в `ValueError("Missing required prompt variable")`;
  - `process(task, context=None) -> dict` — (1) форматирует промпт оркестратора (`task` + ключи `context`), вызывает `llm_call`; (2) достаёт `analysis` и `tasks` через `extract_xml`, парсит `parse_tasks`; (3) в цикле `for` по задачам форматирует промпт воркера (`original_task`, `task_type`, `task_description`, `**context`), вызывает `llm_call`, достаёт `<response>`; (4) если ответ воркера пуст — подставляет строку `"[Error: Worker '…' failed to generate content]"` и идёт дальше; (5) возвращает `{"analysis": …, "worker_results": [{"type", "description", "result"}, …]}`.
- Промпты: `ORCHESTRATOR_PROMPT` просит разбить задачу на «2-3 distinct approaches» и даёт пример XML-структуры (типы `formal` и `conversational` в примере); `WORKER_PROMPT` — «Task / Style / Guidelines» и формат `<response>`.
- Запуск: `orchestrator.process(task="Write a product description for a new eco-friendly water bottle", context={"target_audience": …, "key_features": […]})`.

**Что заявляют авторы.** Преимущества: адаптивность, лёгкий перенос на другой домен сменой промптов, структурированная координация через XML, устойчивость к пустым ответам воркеров. Ограничения: N+1 вызовов LLM; воркеры в этой реализации выполняются **последовательно**; оркестратор может декомпозировать неоптимально (промпт критичен); XML-парсинг ломается, если модель отступила от формата (предлагают JSON как альтернативу). Что рекомендуют доделать: параллельный запуск воркеров через `asyncio` или пул потоков, ретраи упавших воркеров, фазу синтеза (LLM объединяет выходы), эксперименты с числом подзадач, Opus для оркестратора и Haiku для воркеров ради баланса цены и качества.

**Что видно в коде, но не сказано в тексте** (мои наблюдения по прочитанному коду, не утверждения авторов):

- Название класса и docstring говорят «run them in parallel», но цикл строго последовательный — параллелизма нет.
- Фазы синтеза нет вообще: результат — просто список выходов воркеров, оркестратор их больше не видит.
- `context` передаётся в `str.format`, но ни в `ORCHESTRATOR_PROMPT`, ни в `WORKER_PROMPT` нет плейсхолдеров `{target_audience}` / `{key_features}`; лишние именованные аргументы `format` игнорирует, так что в приведённом примере контекст до модели не доходит.
- В `parse_tasks` срез для описания — `line[12:-13]`, хотя `<description>` — 13 символов, а `</description>` — 14; я проверил срез отдельно: на строке `<description>X</description>` он даёт `>X<`, то есть описания подзадач уходят воркерам с лишними `>` и `<` по краям. Для `<type>` срез `line[6:-7]` корректен.
- Парсер построчный: многострочное описание или теги в одну строку с `<task>` он не разберёт.
- Нет лимита на число подзадач в коде (только «2-3» в промпте), нет ретраев, таймаутов, учёта токенов.

Вывод для базы знаний: ноутбук хорош как минимальная схема паттерна и как список «что доделать», но брать из него код как есть нельзя. Параллельный запуск и обмен сообщениями — в `async_multi_agent_orchestration.ipynb`, production-вариант той же идеи с измерением экономики — в `managed_agents/CMA_plan_big_execute_small.ipynb`.

#### basic_workflows.ipynb

[basic_workflows.ipynb](../references/claude-cookbooks/patterns/agents/basic_workflows.ipynb) — три функции, прямо помечено «not production code»:

- `chain(input, prompts)` — prompt chaining: выход шага подаётся на вход следующего (`f"{prompt}\nInput: {result}"`). Пример: извлечь числа из отчёта → привести к процентам → отсортировать → отформатировать в markdown-таблицу.
- `parallel(prompt, inputs, n_workers=3)` — один и тот же промпт на несколько входов через `ThreadPoolExecutor`; результаты в порядке входов. Пример: анализ влияния изменений рынка на четыре группы стейкхолдеров.
- `route(input, routes)` — LLM-классификатор с chain-of-thought: возвращает `<reasoning>` и `<selection>`, затем вход уходит на специализированный промпт `routes[route_key]`. Пример: тикеты поддержки → billing / technical / account / product. Валидации нет: если модель вернёт имя маршрута не из словаря, будет `KeyError`.

Авторская рамка: эти workflow меняют стоимость или латентность на потенциально лучшее качество.

#### evaluator_optimizer.ipynb

[evaluator_optimizer.ipynb](../references/claude-cookbooks/patterns/agents/evaluator_optimizer.ipynb) — цикл «генератор ↔ оценщик»:

- `generate(prompt, task, context="")` → `(thoughts, result)` из тегов `<thoughts>` / `<response>`;
- `evaluate(prompt, content, task)` → `(evaluation, feedback)` из `<evaluation>` (PASS / NEEDS_IMPROVEMENT / FAIL) и `<feedback>`;
- `loop(task, evaluator_prompt, generator_prompt)` — `while True`: пока оценка не `PASS`, собирает контекст «Previous attempts: … Feedback: …» из всех прошлых попыток и генерирует заново.

Когда применять (авторы): есть чёткие критерии оценки и итерации реально улучшают результат; признаки — ответ LLM улучшается от фидбека и LLM сама способна дать содержательный фидбек. Ограничение из кода: цикл без верхней границы итераций — оценщик, который никогда не говорит `PASS` (а промпт требует `PASS` только при отсутствии любых предложений), зациклит выполнение. Ср. с `max_iterations` в Outcomes (п. 3.3).

#### async_multi_agent_orchestration.ipynb

[async_multi_agent_orchestration.ipynb](../references/claude-cookbooks/patterns/agents/async_multi_agent_orchestration.ipynb) — «форма» двух мультиагентных схем (фиксированная команда из N агентов и асинхронные субагенты) без предметной задачи: только механика сообщений на `anthropic.AsyncAnthropic` + `asyncio`.

- `class Hub` — общий «почтовый узел»: `inbox` (список сообщений на агента), `event` (`asyncio.Event` на агента для блокирующего ожидания), `status` (active / idling / done / crashed), `register`, `new_name`, `post(sender, recipients, content)`, `drain(name)`, `render(msgs)` (оборачивает в `<agent-message from="…">`).
- Инструменты, которые получает каждый агент: `send_message(recipient_ids, content)` — в описании подчёркнуто, что это *единственный* способ достучаться до других агентов, обычный текст хода никуда не уходит; `wait_for_message()` — блокирует до сообщения (таймаут 60 с).
- `run_agent(hub, name, system, first_user_turn, tools, extra_dispatch, max_turns=20)` — обычный tool-use цикл; ключевой приём — входящие сообщения **дописываются к последнему `tool_result`** этого хода (`results[-1]["content"] += hub.render(inbox)`), поэтому агентам не нужно опрашивать почту. При исключении статус становится `crashed`.
- Часть 1, фиксированная команда: `lead` + два helper'а с однострочными персонами запускаются как `asyncio.create_task`; lead завершает прогон, остальных отменяют в `finally`.
- Часть 2, динамический spawn: lead получает `create_subagents(base_instruction, per_subagent_instructions[≤10])` (возвращается сразу, субагенты работают в фоне), `get_status()`, `kill_subagents(subagent_ids)`. Демонстрация: три helper'а спят 1/2/3 секунды, отчитываются через `send_message`, lead собирает отчёты и «увольняет» их.

Авторы предлагают подставить свои инструменты в `tools` и обработчики в `extra_dispatch`; остальное переиспользуется.

#### latency_multi_agent.ipynb

[latency_multi_agent.ipynb](../references/claude-cookbooks/patterns/agents/latency_multi_agent.ipynb) — та же команда lead + helpers, но с «осознанием времени». Тезис: сама по себе команда оптимизирует полноту, а не скорость, потому что ни в чьём контексте нет информации, сколько уже ждёт пользователь.

- `TeamClock(budget=None)` — **одни** часы на всю команду; `clock_line` рендерит `[elapsed 192s]` или `[elapsed 192s / 600s]`. Helper, запущенный на 182-й секунде, видит `[elapsed 182s]` на первом же ходу, а не 0.
- `stamp_elapsed(messages, clock)` — перед каждым вызовом API дописывает строку часов в *конец* самого нового user-сообщения (после task prompt — отдельным text-блоком, после tool results — в хвост последнего результата). Поскольку дописывается в хвост, prompt caching по префиксу не ломается (в запросе стоит `cache_control={"type": "ephemeral"}`).
- Два режима давления: `latency_pressure=True` — одна фраза `TIME_MATTERS` в конце задачи lead'а и каждого брифа helper'а; `latency_budget_seconds=N` — только второе число на строке часов, без слов в промпте. Режимы взаимоисключающие. Бюджет нигде не форсируется — это только сигнал модели.
- Отличия цикла от предыдущего ноутбука: tool-вызовы одного хода выполняются конкурентно (`asyncio.gather`); финальный текст helper'а автоматически отправляется lead'у (`report_to`), перед завершением helper дочитывает почту, чтобы не потерять перенаправление; упавший helper не роняет команду — его «crashed»-сообщение уходит lead'у как отчёт; обрезка по `max_tokens` явно помечается в отчёте.
- Ограничители стоимости: `MAX_HELPERS = 4`, `max_turns=15` у helper'ов и 40 у lead'а.
- `LEAD_SYSTEM` — хороший компактный образец промпта оркестратора: «Decompose the task, delegate the independent parts, check what comes back, and finish with a short final answer…».

Выводы авторов: pressure — когда «чем раньше, тем лучше» и нет конкретного числа; budget — когда есть целевая латентность, лучше подходит длинным задачам (на коротких Claude скорее превысит бюджет, чем бросит задачу). Клиентские инструменты держат часы свежими: пока ответ сэмплируется на сервере (включая серверные tools), вставить строку нельзя. Оговорки: влияние на качество ноутбук не измеряет; подход не полностью протестирован на Claude Opus 5 и более ранних моделях; встроенной задачи нет — только smoke-тест проводки.

#### prompts/

Три промпта исследовательской мультиагентной системы; ни один ноутбук репозитория их не загружает (поиск по именам файлов ничего не нашёл), на каталог лишь ссылается ноутбук 00 Agent SDK как на «agent prompting guide». Шаблонизация — `{{.CurrentDate}}`.

- [research_lead_agent.md](../references/claude-cookbooks/patterns/agents/prompts/research_lead_agent.md) — самый содержательный текст репозитория про оркестрацию. Процесс: (1) разбор запроса; (2) классификация — *depth-first* (один вопрос, много перспектив), *breadth-first* (независимые подвопросы), *straightforward*; (3) план с распределением по субагентам; (4) исполнение. Правила числа субагентов: 1 для простого, 2–3 стандарт, 3–5 средняя сложность, 5–10 высокая, максимум 20; «больше субагентов = больше накладных расходов». Инструкция делегирования: каждому субагенту — одна цель, ожидаемый формат вывода, контекст, ключевые вопросы, источники, инструменты, границы scope; есть образец хорошего брифа. Lead координирует и синтезирует, а не исследует сам; финальный отчёт пишет только lead, никогда не субагент; останавливаться при убывающей отдаче; субагентов запускать параллельными tool-вызовами (обычно 3 сразу). Инструменты в тексте — `run_blocking_subagent` и `complete_task`.
- [research_subagent.md](../references/claude-cookbooks/patterns/agents/prompts/research_subagent.md) — воркер: сначала план и «research budget» в tool-вызовах (до 5 для простых задач, около 10 для сложных, до 15 для очень сложных; жёсткий потолок 20 вызовов и около 100 источников, иначе субагент будет завершён), цикл OODA, критика качества источников, конфликтующие факты передавать lead'у, отчёт — плотный и сжатый.
- [citations_agent.md](../references/claude-cookbooks/patterns/agents/prompts/citations_agent.md) — отдельный агент расставляет цитаты в готовом отчёте, не меняя ни символа текста (текст без цитат сверяется с оригиналом, при расхождении результат отклоняется).

### 3.2. `claude_agent_sdk/` — Claude Agent SDK

[README](../references/claude-cookbooks/claude_agent_sdk/README.md) описывает «foundations sequence» 00–08 и standalone-рецепты. SDK управляет рантаймом Claude Code (CLI входит в пакет `claude-agent-sdk`).

| Файл | Что показывает | Главное |
|---|---|---|
| [00_The_one_liner_research_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/00_The_one_liner_research_agent.ipynb) | Минимальный research-агент | `query(prompt, options=ClaudeAgentOptions(model, allowed_tools=["WebSearch"], setting_sources=[]))` — stateless; `ClaudeSDKClient` — диалог с памятью. Критерий «когда агент»: информация не содержится во входе и путь выясняется по ходу исследования. `setting_sources=[]` изолирует запуск: начиная с SDK 0.2 иначе подхватываются пользовательские и проектные настройки Claude Code |
| [01_The_chief_of_staff_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/01_The_chief_of_staff_agent.ipynb) | Набор фич SDK на примере «AI Chief of Staff» | См. ниже |
| [02_The_observability_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/02_The_observability_agent.ipynb) | Подключение MCP-серверов (Git, GitHub через Docker) | `mcp_servers={…}`, `allowed_tools=["mcp__github"]`, `disallowed_tools=["Bash", "Agent", "WebSearch", "WebFetch"]`, чтобы агент пользовался только MCP |
| [03_The_site_reliability_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/03_The_site_reliability_agent.ipynb) | SRE-агент с правом записи | См. ниже |
| [04_migrating_from_openai_agents_sdk.ipynb](../references/claude-cookbooks/claude_agent_sdk/04_migrating_from_openai_agents_sdk.ipynb) | Перенос с OpenAI Agents SDK | Таблица соответствий примитивов; см. ниже |
| [05_Building_a_session_browser.ipynb](../references/claude-cookbooks/claude_agent_sdk/05_Building_a_session_browser.ipynb) | Управление сессиями | Транскрипты — JSONL в `~/.claude/projects/<encoded-cwd>/<session-id>.jsonl`; `list_sessions`, `get_session_messages`, `rename_session`, `tag_session`, `fork_session` + `resume` |
| [06_The_vulnerability_detection_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/06_The_vulnerability_detection_agent.ipynb) | Цепочка стадий: threat model → find → triage → report | `ClaudeSDKClient` для диалога (bootstrap + интервью), отдельные `query()` для независимых стадий; triage — отдельный проход, перепроверяющий находки независимо от find-агента |
| [07_Hosting_the_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/07_Hosting_the_agent.ipynb) + [hosting/](../references/claude-cookbooks/claude_agent_sdk/hosting/) | Три уровня хостинга одного образа | Docker → Modal → Kubernetes; на K8s — gateway с аутентификацией, pod-per-session (gateway → Redis → pod), пул прогретых подов, egress lockdown (NetworkPolicy + allowlist-прокси), чтобы агент после prompt injection доставал только до `api.anthropic.com`. Сам `server.py` без аутентификации |
| [08_Dynamic_workflows.ipynb](../references/claude-cookbooks/claude_agent_sdk/08_Dynamic_workflows.ipynb) | Оркестрация субагентов скриптом | См. ниже |
| [scheduled_repository_reviewer.ipynb](../references/claude-cookbooks/claude_agent_sdk/scheduled_repository_reviewer/scheduled_repository_reviewer.ipynb) | Автономный агент по расписанию | Read-only набор (`Read`, `Glob`, `Grep`), `permission_mode="dontAsk"`, ограничители `max_turns` и `max_budget_usd`, `resume` сессии между запусками, `output_format`-схема, требующая повторить id прошлого ревью (проверяемая непрерывность) |

**01 — Chief of Staff (субагенты и управление).** Фичи вводятся по одной:

- Память через `CLAUDE.md` в `cwd` (`setting_sources=["project"]`). Вывод авторов: CLAUDE.md — контекст и ориентиры, а не жёсткое ограничение; при наличии более точных файлов данных агент предпочтёт их.
- Bash + скрипты как «процедурное знание» (скрипты в [chief_of_staff_agent/scripts/](../references/claude-cookbooks/claude_agent_sdk/chief_of_staff_agent/scripts/)).
- Output styles (`settings='{"outputStyle": "executive"}'`), plan mode (`permission_mode="plan"`; в headless-режиме агент пытается вызвать `ExitPlanMode`, поэтому план вылавливают из потока по тегам `<plan>` с несколькими fallback'ами), slash-команды как шаблоны промптов.
- Hooks — детерминированные скрипты до/после tool-вызовов: аудит записи отчётов (`PostToolUse` на `Write`/`Edit`), настройки в `.claude/settings.local.json`, для чего нужен `setting_sources=["project", "local"]`.
- **Субагенты через инструмент `Agent`** (до SDK 0.2 назывался `Task`): `allowed_tools=["Agent"]`, системный промпт с правилом делегирования, определения в `.claude/agents/*.md` — frontmatter `name`, `description`, `tools` и системный промпт (пример: [financial-analyst.md](../references/claude-cookbooks/claude_agent_sdk/chief_of_staff_agent/.claude/agents/financial-analyst.md)). `description` — то, по чему главный агент решает, кого вызвать. Вызов: `{"description", "prompt", "subagent_type"}`; субагент работает в отдельном контексте и возвращает результат. Заявленные плюсы: специализация, отдельный контекст, параллелизм.
- Сборка всего вместе — в [chief_of_staff_agent/agent.py](../references/claude-cookbooks/claude_agent_sdk/chief_of_staff_agent/agent.py) (сам файл я не читал, только его описание в ноутбуке).

**03 — SRE-агент.** Архитектура: Agent SDK → MCP-сервер как подпроцесс (JSON-RPC по stdin/stdout, [sre_mcp_server.py](../references/claude-cookbooks/claude_agent_sdk/site_reliability_agent/sre_mcp_server.py)) → Prometheus / Docker / конфиги. 12 инструментов в четырёх группах. Выводы авторов:

- Системный промпт намеренно простой (общая методика расследования), а выбор инструментов определяют их описания: «clear tool descriptions drive autonomous agent behavior more effectively than elaborate prompts».
- Безопасная запись — в два слоя: проверки в обработчиках инструментов (запись только в `config/`, команды только с префиксом `docker`/`docker-compose`, белый список контейнеров) и `PreToolUse`-хуки, проверяющие *содержимое* изменения (диапазон `DB_POOL_SIZE` 5–100); ненулевой код возврата блокирует вызов, агент видит ошибку и корректирует подход.
- Human-in-the-loop разделением фаз: сначала «расследуй, но ничего не чини», затем отдельный запрос на исправление.
- Подпроцесс для инструментов — изоляция: упавший обработчик не роняет агента.

**04 — миграция с OpenAI Agents SDK.** Соответствия: `Agent(...)` → `ClaudeAgentOptions` + system prompt; `@function_tool` → `@tool` + `create_sdk_mcp_server` (in-process MCP-сервер, схема задаётся явно); guardrails → обычные функции до и после цикла либо хук `UserPromptSubmit`; `Runner.run` → `ClaudeSDKClient`; сессии → один клиент (в памяти) или `resume=session_id` (транскрипт на локальном диске, не переживает смену машины). Имена инструментов: `mcp__{server}__{tool}`. Про handoffs: в OpenAI специалист *забирает* разговор, в Claude оркестратор *делегирует* через `Agent` и остаётся главным; субагенты задаются программно через `AgentDefinition(description, prompt, tools)` в `ClaudeAgentOptions(agents={…})`. Если критично «агент B забрал управление навсегда» — авторы советуют тонкий Python-диспетчер вместо LLM-маршрутизации. Трейсинг — нативный OpenTelemetry (`CLAUDE_CODE_ENABLE_TELEMETRY=1` и OTLP-переменные); стоимость и usage — в каждом `ResultMessage`.

**08 — Dynamic workflows (ключевой материал про масштаб).** Claude пишет JavaScript-скрипт оркестрации и передаёт его инструменту `Workflow`; рантайм исполняет скрипт в фоне. Запуск из SDK: добавить `Workflow` в `allowed_tools` и попросить в промпте «use a workflow to…». Требуется `claude-agent-sdk>=0.2.90`.

Центральный вопрос — **кто держит план**:

| | Один агент | Субагенты | Dynamic workflow |
|---|---|---|---|
| Кто решает, что дальше | Claude, ход за ходом | Claude, ход за ходом | Скрипт |
| Где промежуточные результаты | Контекст Claude | Контекст lead-агента | Переменные скрипта |
| Что воспроизводимо | Промпт | Определения воркеров | Сама оркестрация |
| Масштаб | Одно контекстное окно | Несколько делегаций за ход | Десятки–сотни агентов |
| Прерывание | Перезапуск хода | Перезапуск хода | Возобновляемо в той же сессии |

- Примитивы скрипта: `export const meta = {name, description, phases}`; `agent(prompt, options)` — субагент с **чистым контекстом** (видит только строку промпта; опции `label`, `phase`, JSON `schema` для структурированного вывода, `model`); `parallel([...])` — барьер, ждёт всех; `pipeline(items, stage1, stage2, …)` — элементы идут по стадиям независимо; `phase("…")`; обычный JavaScript между вызовами (фильтрация, дедупликация, циклы — точно, мгновенно и без токенов); `return`.
- Лимиты: до 16 агентов одновременно, до 1000 на запуск; сам скрипт не имеет доступа к файловой системе и shell — только порождённые агенты. Скрипт сохраняется файлом, его можно положить в `.claude/workflows/` и запускать по имени.
- Пример — паттерн **fan-out + adversarial verification** на фактчекинге письма инвесторам (10 утверждений, 4 источника, часть ошибок подсажена): EXTRACT (один агент) → VERIFY (по агенту на утверждение, параллельно, вердикт структурированным выводом с цитатой) → SKEPTIC (на каждый «confirmed» отдельный агент пытается опровергнуть) → REPORT.
- Выводы авторов: план, закреплённый кодом, гарантирует одинаковую обработку каждого элемента; «перепроверь себя» в промпте — лишь инструкция, под давлением контекста её пропускают, а структурная верификация выполняется всегда; контекст главной сессии остаётся чистым. С workflow промпт описывает *форму harness'а*, а не только задачу. Цена — токены (прогон ноутбука оценён в $2–4). Правило выбора: один агент — задача помещается в окно и не требует независимой проверки (большинство задач); субагенты — несколько специалистов и план должен адаптироваться по ходу; workflow — задача больше одного окна, проверку нельзя пропустить, или процесс будет повторяться.

### 3.3. `managed_agents/` — Claude Managed Agents

[README](../references/claude-cookbooks/managed_agents/README.md): хостинговый рантайм для stateful-агентов. Три ресурса: **Agent** (модель, системный промпт, инструменты — переиспользуемая конфигурация), **Environment** (шаблон контейнера: пакеты, сеть), **Session** (связывает агента и окружение, монтирует файлы, порождает поток событий). Всё под бета-заголовком `managed-agents-2026-04-01`. Входная точка — [CMA_iterate_fix_failing_tests.ipynb](../references/claude-cookbooks/managed_agents/CMA_iterate_fix_failing_tests.ipynb).

**Мультиагентность и оркестрация**

- [CMA_coordinate_specialist_team.ipynb](../references/claude-cookbooks/managed_agents/CMA_coordinate_specialist_team.ipynb) — координатор и три специалиста собирают коммерческое предложение. Специалисты создаются обычным `client.beta.agents.create`, у каждого свой промпт, формат вывода и *только свои* инструменты: исследователь — веб-поиск, «библиотекарь» — чтение локальной библиотеки кейсов, «прайсер» — только файл правил. Координатор получает блок `multiagent={"type": "coordinator", "agents": [id1, id2, id3, {"type": "advisor", "model": …}]}`; инструменты делегирования появляются автоматически. Системный промпт координатора — нумерованная последовательность: кому что отправить, что дождаться, когда спросить advisor'а, что и куда записать. События: `session.thread_created`, `agent.thread_message_sent`, `agent.thread_message_received`; специалисты отвечают через `send_to_parent`. Аргументы «зачем трое, а не один»: scoping инструментов исключает класс ошибок (прайсер физически не может взять цену конкурента из сети), объёмное чтение остаётся в контексте субагента, координатор управляет порядком и не делает работу специалистов.
- [CMA_plan_big_execute_small.ipynb](../references/claude-cookbooks/managed_agents/CMA_plan_big_execute_small.ipynb) — **экономика orchestrator-workers с измерением**. Frontier-оркестратор (`claude-fable-5-1`) *без собственных инструментов* планирует и синтезирует; дешёвые воркеры (`claude-sonnet-5-5`) только с `web_search` + `web_fetch` читают веб в своих параллельных контекстах и возвращают выжимку. Задача — 20 фактов (10 парков × 2 атрибута), каждый надо проверить по авторитетному источнику. Контрольная группа — один frontier-агент с теми же инструментами и **тем же стандартом проверки**. Измерение — серверный `usage.list_cost` по каждому thread'у. Выводы авторов:
  - на их прогонах 84–98% входных токенов команды тарифицировались по ставке воркера; обе руки прочитали примерно одинаковый объём (ноутбук оговаривает, что прогоны сделаны до перехода на тип `multiagent_20261001`);
  - сравнивать только при равной строгости: frontier-агент, предоставленный сам себе, читает по одному источнику на факт и выходит дешевле, но это продукт более низкого качества;
  - у делегирования есть «пол» стоимости: дробление на более узкие брифы *увеличило* счёт — у гранулярности есть оптимум;
  - стандарт проверки покрывает только то, что в него включено: в одном прогоне обе руки проверили все 20 фактов, но сам список парков взяли из памяти модели и ошиблись — декомпозицию вопроса никто не проверял;
  - оркестратор **не видит системные промпты воркеров** (`create_agent` принимает имя агента и строку задачи); всё, что он знает о воркерах, — из собственного промпта, согласованность на вашей совести;
  - список воркеров снапшотится при создании или обновлении оркестратора;
  - не окупается: узкие вопросы (нечего арбитражить), оркестратор ответил из своих знаний без делегирования, задача требует frontier-суждения по сырому материалу (дешёвый читатель «усреднит» важное);
  - coverage-задачи (чтение обязательно) — идеальный случай, discovery-задачи выигрывают меньше;
  - scoping инструментов воркера — ещё и граница безопасности: воркеры читают недоверенные веб-страницы, а оркестратор без инструментов читает только их отчёты;
  - fan-out зависит от данных, поэтому на сессию ставится `budget`.
- [CMA_watch_subagents_live.ipynb](../references/claude-cookbooks/managed_agents/CMA_watch_subagents_live.ipynb) — наблюдаемость команды: текст субагента по умолчанию виден только после завершения его хода; решение — отдельный стрим на каждый thread с `event_deltas`, подключаемый по событию `session.thread_created`. Превью — черновой буфер, запись — буферизованное событие. Также: `initial_events` при создании сессии, `model.effort` на агенте как рычаг стоимости по ролям (глубина только там, где нужна), `agents.update` без версии. Подводный камень: roster координатора пинит версии детей на момент создания — после обновления субагента нужно обновить и координатора.
- [CMA_consult_an_advisor.ipynb](../references/claude-cookbooks/managed_agents/CMA_consult_an_advisor.ipynb) — обратная схема: работает модель среднего уровня и в середине хода консультируется с более сильной. В roster — запись `{"type": "advisor", "model": …}` (не более одной); у рабочей модели появляется инструмент `advisor` *без входных параметров* — advisor читает весь разговор до точки вызова. Политику «когда спрашивать» задаёт системный промпт (в примере — перед решениями, которые дорого откатывать). Консультация — отдельный короткоживущий thread со своим `usage`; токены advisor'а оплачиваются сверх основных, лимита на консультацию нет — поэтому `budget` на сессию. Совет может вернуться `redacted` (политика модели): рабочая модель его всё равно получает, а наблюдатель — нет. Консультируется только основной thread; спауненные специалисты advisor'а не имеют.
- [CMA_orchestrate_issue_to_pr.ipynb](../references/claude-cookbooks/managed_agents/CMA_orchestrate_issue_to_pr.ipynb) — несмотря на название, это **один** агент, ведущий длинную цепочку issue → fix → PR → CI → review → merge через mock `gh` CLI с двумя сбоями посередине. Про многоходовое управление и перенос состояния, а не про orchestrator-workers. Прочитана только первая часть markdown.

**Верификация и контроль качества**

- [CMA_verify_with_outcome_grader.ipynb](../references/claude-cookbooks/managed_agents/CMA_verify_with_outcome_grader.ipynb) — платформенный evaluator-optimizer. Событие `user.define_outcome` даёт сессии *description* (читает writer) и *rubric* (читает grader); после каждого хода writer'а поднимается свежий grader в собственном контексте с той же моделью и инструментами, выносит вердикт по каждому критерию, провалы уходят writer'у на доработку. `max_iterations` — по умолчанию 3, максимум 20. Терминальные состояния: `satisfied`, `max_iterations_reached`, `failed`, `interrupted`. Выводы авторов:
  - рубрика — единственный рычаг на grader; режим отказа по умолчанию — grader, одобряющий всё. «Проверь, что раскрыта тема X» позволяет пробежать глазами; «открой документ, найди раздел и подтверди, что там есть цифра в $/kW» заставляет предъявить доказательство;
  - рубрика должна быть конкретнее задачи; каждый критерий проверяемым; grader обязан «заработать» `satisfied` (загруженная страница, ссылка `file:line`);
  - почему не положить рубрику в системный промпт: writer, знающий критерии, всё равно оценивает собственную работу; grader независим и stateless, writer не может его «уговорить»;
  - если каждый прогон упирается в лимит итераций с одним и тем же замечанием — writer не может выполнить фидбек, и вы платите за несходящиеся итерации;
  - description и rubric должны согласованно указывать место и формат артефакта, иначе цикл вернёт `failed`.
- [CMA_gate_human_in_the_loop.ipynb](../references/claude-cookbooks/managed_agents/CMA_gate_human_in_the_loop.ipynb) — HITL через custom tools `decide()` и `escalate()`: при вызове сессия ставится на паузу, эмитит `agent.custom_tool_use`, приложение выполняет код и возвращает `user.custom_tool_result`. Про калибровку: агент, эскалирующий всё, изматывает; не эскалирующий ничего — опасен. Dev-режим — стрим; production — webhook на `session.status_idled` без долгого соединения.
- [CMA_agent_security_review.ipynb](../references/claude-cookbooks/managed_agents/CMA_agent_security_review.ipynb) — пост-фактум ревью завершённых сессий на безопасность через dynamic workflow (по описанию в README; сам ноутбук не читал).

**Стоимость, память, эксплуатация**

- [CMA_cap_session_spend.ipynb](../references/claude-cookbooks/managed_agents/CMA_cap_session_spend.ipynb) — `budget={"type": "limit", "max_list_cost": {"currency": "USD", "amount": "<центы строкой>"}}` на `sessions.create`. Считается list-стоимость токенов по *всем* thread'ам сессии, включая субагентов. При достижении — пауза со `stop_reason` `budget_reached` (не ошибка: файлы и контекст целы), подняли лимит через `sessions.update` — работа продолжается сама. Лимит проверяется между запросами к модели, поэтому возможен перелёт на один запрос. Бюджет можно прикрепить только при создании; снятие необратимо. Для флота — webhook `session.budget_reached`.
- [CMA_remember_user_preferences.ipynb](../references/claude-cookbooks/managed_agents/CMA_remember_user_preferences.ipynb) — memory stores: именованный контейнер текстовых файлов, монтируется в сессию через `resources` (`type: memory_store`, `access: read_write | read_only`, `instructions` на конкретное подключение), агент сам читает и пишет файлы под `/mnt/memory/`. Паттерн: один read-write store на пользователя + общий read-only store знаний; до 8 store'ов на сессию; `description` store'а попадает в системный промпт.
- [CMA_dreaming_with_a_dynamic_workflow.ipynb](../references/claude-cookbooks/managed_agents/CMA_dreaming_with_a_dynamic_workflow.ipynb) + [dreaming_nightly.py](../references/claude-cookbooks/managed_agents/dreaming_nightly.py) — «dreaming»: фоновый проход по транскриптам завершённых сессий, дистилляция в wiki-подобную память (мелкие связанные файлы + `_index.md`). Lead-агент (только `read`/`glob`, транскрипты сам не читает) пишет workflow: по analyzer'у на транскрипт (дешёвая модель), затем один organizer. Сложности, названные авторами: факты устаревают (нужно датировать и замещать), детали закопаны в выводе инструментов, темы пересекаются. Оговорки: память не лучше транскриптов; недоверенный ввод может исказить store, а если текст store'а грузится в системный промпт — управлять каждой сессией, поэтому монтировать read-only; лимиты — до 500 файлов на сессию, до 64 thread'ов одновременно в workflow run.
- [CMA_operate_in_production.ipynb](../references/claude-cookbooks/managed_agents/CMA_operate_in_production.ipynb) — MCP toolsets (правило: сервис доступен из интернета по bearer-токену → MCP; только из вашей сети → custom tool), vaults для учётных данных конкретного конечного пользователя (агент токен не видит), webhooks для HITL и надзора за расходами, `inference_geo`, жизненный цикл ресурсов (archive против delete).
- [CMA_iterate_fix_failing_tests.ipynb](../references/claude-cookbooks/managed_agents/CMA_iterate_fix_failing_tests.ipynb) — базовый цикл do → observe → fix и канонический event loop: «сначала открой стрим, потом отправляй»; выходить по `session.status_idle` со `stop_reason.type == "end_turn"` (idle бывает и при ожидании custom tool); не верить агенту на слово — независимо перезапустить проверки; хелперы `stream_until_end_turn` и `wait_for_idle_status` в [utilities.py](../references/claude-cookbooks/managed_agents/utilities.py).
- Не читал, только описание в README: [CMA_explore_unfamiliar_codebase.ipynb](../references/claude-cookbooks/managed_agents/CMA_explore_unfamiliar_codebase.ipynb) (grounding в незнакомом коде, ловушка с устаревшей документацией), [CMA_prompt_versioning_and_rollback.ipynb](../references/claude-cookbooks/managed_agents/CMA_prompt_versioning_and_rollback.ipynb) (версии промпта, eval на размеченном наборе, откат пином версии), [CMA_use_skills_from_a_repo.ipynb](../references/claude-cookbooks/managed_agents/CMA_use_skills_from_a_repo.ipynb), [CMA_pin_inference_geo.ipynb](../references/claude-cookbooks/managed_agents/CMA_pin_inference_geo.ipynb), [CMA_with_mongodb_atlas.ipynb](../references/claude-cookbooks/managed_agents/CMA_with_mongodb_atlas.ipynb), [data_analyst_agent.ipynb](../references/claude-cookbooks/managed_agents/data_analyst_agent.ipynb), [slack_data_bot.ipynb](../references/claude-cookbooks/managed_agents/slack_data_bot.ipynb), [sre_incident_responder.ipynb](../references/claude-cookbooks/managed_agents/sre_incident_responder.ipynb) (алерт → сессия → расследование → PR → пауза на одобрение человека).

### 3.4. `tool_use/` и смежное — инструменты, контекст, память

- [tool_use/parallel_tools.ipynb](../references/claude-cookbooks/tool_use/parallel_tools.ipynb) — параллельные tool-вызовы включены по умолчанию; правильный клиентский цикл — ответить на **каждый** `tool_use`-блок хода одним user-сообщением со всеми `tool_result`. «Batch tool» (мета-инструмент, оборачивающий несколько вызовов) — опциональный приём на случай, когда модель упорно вызывает по одному.
- [tool_use/programmatic_tool_calling_ptc.ipynb](../references/claude-cookbooks/tool_use/programmatic_tool_calling_ptc.ipynb) — PTC: Claude пишет код, вызывающий инструменты внутри code execution, вместо круга через модель на каждый вызов. Включается полем `allowed_callers` у инструмента и бетой `advanced-tool-use-2025-11-20`. Сырые данные фильтруются и агрегируются в песочнице и не попадают в контекст. API-вызовов может стать больше, но токенов заметно меньше.
- [tool_use/tool_search_with_embeddings.ipynb](../references/claude-cookbooks/tool_use/tool_search_with_embeddings.ipynb), [tool_use/tool_search_alternate_approaches.ipynb](../references/claude-cookbooks/tool_use/tool_search_alternate_approaches.ipynb) — когда инструментов сотни: давать один мета-инструмент `tool_search` (эмбеддинги + косинусная близость) или `describe_tool` при списке имён в системном промпте; `defer_loading=True` держит определение вне кешируемого префикса, чтобы обнаружение инструмента не инвалидировало кеш.
- [tool_use/context_engineering/context_engineering_tools.ipynb](../references/claude-cookbooks/tool_use/context_engineering/context_engineering_tools.ipynb) — **лучший материал репозитория про контекст долгих агентов**. Три примитива на одном research-агенте (8 документов примерно по 40K токенов):

  | Примитив | Над чем работает | Чем платим | Что решает |
  |---|---|---|---|
  | Compaction (`compact_20260112`) | Весь разговор в текущем окне | Дословные детали сжаты в саммари, редкие частности теряются | Любой внутрисессионный рост |
  | Tool-result clearing | Результаты инструментов в окне | Старые результаты исчезают (нужно перезапросить) | Раздувание от tool results |
  | Memory (`memory_20250818`) | Внешнее хранилище между окнами | Накладные tool-вызовы; качество = суждение агента о том, что сохранить | Межсессионная устойчивость |

  Выводы авторов: context rot наступает раньше жёсткого лимита (на окне 1M агент не падает, но recall ранних документов деградирует, а латентность растёт); примитивы компонуются, а не конкурируют; правильный вопрос — не «включить ли все три», а «какая из трёх проблем есть у моей нагрузки»; при совмещении clearing и memory нужен `exclude_tools: ["memory"]`; пользовательские `instructions` компакции *заменяют* дефолтный промпт целиком; memory tool сам внедряет протокол «сначала посмотри в память… контекст может быть сброшен в любой момент»; для многосессионной работы полезна отдельная initializer-сессия, создающая артефакты состояния (журнал прогресса, чек-лист).
- [tool_use/memory_cookbook.ipynb](../references/claude-cookbooks/tool_use/memory_cookbook.ipynb) + [tool_use/memory_tool.py](../references/claude-cookbooks/tool_use/memory_tool.py) — memory tool (файлы под `/memories`, клиентская реализация) и context editing на примере code-review-ассистента: паттерн, выученный в одной беседе, применяется в новой. Прочитана первая треть markdown.
- [tool_use/automatic-context-compaction.ipynb](../references/claude-cookbooks/tool_use/automatic-context-compaction.ipynb) — компакция, управляемая tool runner'ом SDK (`runner.compact_before_next_turn()`): код сам решает, когда сжимать (например, на естественных границах — после каждых N тикетов). Сценарий — очередь тикетов поддержки, 7 tool-вызовов на тикет.
- [misc/session_memory_compaction.ipynb](../references/claude-cookbooks/misc/session_memory_compaction.ipynb) — «мгновенная» компакция: саммари строится в фоновом потоке после мягкого порога, поэтому при достижении лимита пользователь не ждёт (в их примере традиционная компакция заняла более 25 секунд).
- [multimodal/using_sub_agents.ipynb](../references/claude-cookbooks/multimodal/using_sub_agents.ipynb) — ранний и простой orchestrator-workers: Opus *пишет промпт* для Haiku-субагентов по вопросу пользователя, Haiku параллельно (`ThreadPoolExecutor`) извлекают данные из квартальных PDF (как изображений), каждый видит только свой отчёт, результаты в XML-тегах уходят Opus на синтез и генерацию matplotlib-кода. Авторы сами отмечают: `exec` кода модели вне песочницы — плохая практика.
- [tool_use/threat_intel_enrichment_agent.ipynb](../references/claude-cookbooks/tool_use/threat_intel_enrichment_agent.ipynb), [tool_use/customer_service_agent.ipynb](../references/claude-cookbooks/tool_use/customer_service_agent.ipynb) — одноагентные tool-use циклы с симулированными бэкендами (просмотрены по вводным ячейкам).
- [tool_use/tool_choice.ipynb](../references/claude-cookbooks/tool_use/tool_choice.ipynb) — `auto` / `any` / `tool`; в ноутбуке отмечено, что принудительный `tool_choice` принимается Haiku 5.5, но отклоняется с 400 на Opus 5.5, Sonnet 5.5 и Fable 5.1.
- [extended_thinking/extended_thinking_with_tool_use.ipynb](../references/claude-cookbooks/extended_thinking/extended_thinking_with_tool_use.ipynb) — thinking-блоки нужно возвращать неизменными вместе с подписями и не редактировать более ранние ходы, иначе 400.

### 3.5. Оценка, стоимость, латентность, наблюдаемость

- [tool_evaluation/tool_evaluation.ipynb](../references/claude-cookbooks/tool_evaluation/tool_evaluation.ipynb) — оценка *инструментов* через агента: задачи из [evaluation.xml](../references/claude-cookbooks/tool_evaluation/evaluation.xml) (`<task><prompt>…<response>…`), `agent_loop` с учётом числа и длительности вызовов по каждому инструменту; системный промпт требует от агента `<summary>` (шаги, какие инструменты и зачем), `<feedback>` (замечания к самим инструментам) и `<response>`. Отчёт: accuracy, среднее время, среднее число tool-вызовов. Демо — калькулятор с намеренно пустым описанием.
- [evals/agentic_search/reproduce_agentic_search_benchmarks.ipynb](../references/claude-cookbooks/evals/agentic_search/reproduce_agentic_search_benchmarks.ipynb) — тезис: опубликованные оценки agentic search воспроизводимы на публичном API, а занижение у третьих сторон обычно из-за конфигурации harness — параметры, неважные в коротких диалогах, становятся несущими при 30+ tool-вызовах. Рычаги: PTC (поиск и fetch вызываются из песочницы, результаты исключены из ответа), thinking/effort, task budget, серверная компакция с **кастомными инструкциями** — дефолтный промпт не знает, что исходный вопрос и формат ответа обязаны пережить сжатие; без этого агент после компакции просит повторить вопрос. Прочитана примерно первая половина markdown.
- [cost_optimization/cost_optimization.ipynb](../references/claude-cookbooks/cost_optimization/cost_optimization.ipynb) — чек-лист на агенте-аджастере страховых претензий: baseline и eval → prompt caching → управление входом → эффективность цикла → управление выходом → Batch API → модель и effort. Про агентов:
  - субагент в Messages API — «просто вложенный цикл `messages` со своим контекстом», который поглощает объёмные результаты и возвращает одну строку; он стартует без общего кеша с родителем, поэтому выгоден, когда результаты подзадачи велики относительно закешированного промпта родителя;
  - декомпозиция «пять Haiku-субагентов собирают факты, один Sonnet решает по сжатой карточке правил» дала примерно −90% стоимости, но стабильно ошибалась на одном кейсе: в карточке было исключение, но не исключение из исключения. Вывод авторов: «сплющивание» руководства в список правил — место, где декомпозиция теряет точность;
  - advisor помогает, когда есть дешёвый сигнал, по которому консультироваться (порог суммы, диапазон fraud-скора); просить слабую модель саму распознать трудный случай — значит требовать ровно того суждения, которого ей не хватает;
  - общие правила: мерить стоимость *выполненной задачи*, а не токена; сначала планка качества, затем минимизация цены; около 50 eval-кейсов и не менее 5 прогонов на конфигурацию перед production-решением; смена архитектуры — самостоятельный рычаг.
- [latency_optimization/latency_optimization.ipynb](../references/claude-cookbooks/latency_optimization/latency_optimization.ipynb) — формула `TTLT ≈ calls × TTFT + output tokens ÷ OTPS + прочее`. Про субагентов: три независимых lookup'а на Haiku-субагентах параллельно — 5,7 с против 9,5 с при сохранении точности; подводный камень — одновременный старт субагентов с общим длинным контекстом означает, что каждый читает его без кеша (решение — pre-warm либо запускать остальных после начала стрима первого). Общие выводы: «чини цикл раньше модели» (меньше кругов — и быстрее, и дешевле), ограничивай худший случай (лимит ходов, timeout, last-call message), оптимизируй *воспринимаемую* латентность.
- [observability/opentelemetry_tracing.ipynb](../references/claude-cookbooks/observability/opentelemetry_tracing.ipynb) — OTel-спаны SDK и API в одном трейсе (через `traceparent`), включая сессии Managed Agents; [observability/usage_cost_api.ipynb](../references/claude-cookbooks/observability/usage_cost_api.ipynb) — Usage & Cost Admin API. Оба просмотрены по вводным ячейкам.
- [misc/building_evals.ipynb](../references/claude-cookbooks/misc/building_evals.ipynb) — общие основы eval'ов (вход, выход, golden answer, оценка; грейдинг кодом, человеком, моделью). Не специфично для агентов.

### 3.6. Сторонние фреймворки (обзорно)

[third_party/LlamaIndex/ReAct_Agent.ipynb](../references/claude-cookbooks/third_party/LlamaIndex/ReAct_Agent.ipynb), [Multi_Document_Agents.ipynb](../references/claude-cookbooks/third_party/LlamaIndex/Multi_Document_Agents.ipynb), [SubQuestion_Query_Engine.ipynb](../references/claude-cookbooks/third_party/LlamaIndex/SubQuestion_Query_Engine.ipynb), [Router_Query_Engine.ipynb](../references/claude-cookbooks/third_party/LlamaIndex/Router_Query_Engine.ipynb) — ReAct-агент, агент на документ с верхнеуровневым ретривером, декомпозиция на подвопросы и роутинг средствами LlamaIndex; [third_party/Pinecone/claude_3_rag_agent.ipynb](../references/claude-cookbooks/third_party/Pinecone/claude_3_rag_agent.ipynb) — RAG-агент на LangChain. Видел только заголовки ячеек; это короткие туториалы по API фреймворков, архитектурных выводов там не искал.

## 4. Что читать под вопрос

| Вопрос / задача | Файл(ы) |
|---|---|
| Что такое orchestrator-workers в минимальном виде | [patterns/agents/orchestrator_workers.ipynb](../references/claude-cookbooks/patterns/agents/orchestrator_workers.ipynb) |
| Как декомпозировать задачу и сколько субагентов запускать | [patterns/agents/prompts/research_lead_agent.md](../references/claude-cookbooks/patterns/agents/prompts/research_lead_agent.md) |
| Как написать бриф субагенту (что в нём должно быть) | [research_lead_agent.md](../references/claude-cookbooks/patterns/agents/prompts/research_lead_agent.md) (блок `delegation_instructions`), [research_subagent.md](../references/claude-cookbooks/patterns/agents/prompts/research_subagent.md) |
| Как запускать воркеров параллельно (потоки) | [patterns/agents/basic_workflows.ipynb](../references/claude-cookbooks/patterns/agents/basic_workflows.ipynb) (`parallel`), [multimodal/using_sub_agents.ipynb](../references/claude-cookbooks/multimodal/using_sub_agents.ipynb) |
| Как запускать воркеров асинхронно и обмениваться сообщениями | [patterns/agents/async_multi_agent_orchestration.ipynb](../references/claude-cookbooks/patterns/agents/async_multi_agent_orchestration.ipynb) |
| Как динамически спаунить, опрашивать и убивать субагентов | [async_multi_agent_orchestration.ipynb](../references/claude-cookbooks/patterns/agents/async_multi_agent_orchestration.ipynb) (часть 2) |
| Как заставить команду работать быстрее или уложиться во время | [patterns/agents/latency_multi_agent.ipynb](../references/claude-cookbooks/patterns/agents/latency_multi_agent.ipynb) |
| Chaining, routing, простая параллелизация | [patterns/agents/basic_workflows.ipynb](../references/claude-cookbooks/patterns/agents/basic_workflows.ipynb) |
| Цикл «генератор — оценщик» | [patterns/agents/evaluator_optimizer.ipynb](../references/claude-cookbooks/patterns/agents/evaluator_optimizer.ipynb); платформенный вариант — [CMA_verify_with_outcome_grader.ipynb](../references/claude-cookbooks/managed_agents/CMA_verify_with_outcome_grader.ipynb) |
| Как писать рубрику для проверяющего агента | [CMA_verify_with_outcome_grader.ipynb](../references/claude-cookbooks/managed_agents/CMA_verify_with_outcome_grader.ipynb) |
| Один агент, субагенты или workflow — как выбрать | [claude_agent_sdk/08_Dynamic_workflows.ipynb](../references/claude-cookbooks/claude_agent_sdk/08_Dynamic_workflows.ipynb) |
| Как оркестрировать десятки–сотни агентов | [08_Dynamic_workflows.ipynb](../references/claude-cookbooks/claude_agent_sdk/08_Dynamic_workflows.ipynb), [CMA_dreaming_with_a_dynamic_workflow.ipynb](../references/claude-cookbooks/managed_agents/CMA_dreaming_with_a_dynamic_workflow.ipynb) |
| Как сделать верификацию обязательной (fan-out + скептик) | [08_Dynamic_workflows.ipynb](../references/claude-cookbooks/claude_agent_sdk/08_Dynamic_workflows.ipynb) |
| Как делать субагентов в Agent SDK | [01_The_chief_of_staff_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/01_The_chief_of_staff_agent.ipynb) (файлы `.claude/agents/*.md`), [04_migrating_from_openai_agents_sdk.ipynb](../references/claude-cookbooks/claude_agent_sdk/04_migrating_from_openai_agents_sdk.ipynb) (программно, `AgentDefinition`) |
| Чем делегирование в Claude SDK отличается от handoffs в OpenAI | [04_migrating_from_openai_agents_sdk.ipynb](../references/claude-cookbooks/claude_agent_sdk/04_migrating_from_openai_agents_sdk.ipynb) (аппендикс) |
| Основы Agent SDK: `query()` против `ClaudeSDKClient` | [00_The_one_liner_research_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/00_The_one_liner_research_agent.ipynb) |
| Guardrails, hooks, права на инструменты | [01](../references/claude-cookbooks/claude_agent_sdk/01_The_chief_of_staff_agent.ipynb) (PostToolUse-аудит), [03](../references/claude-cookbooks/claude_agent_sdk/03_The_site_reliability_agent.ipynb) (PreToolUse-валидация), [04](../references/claude-cookbooks/claude_agent_sdk/04_migrating_from_openai_agents_sdk.ipynb) (вход/выход) |
| Как безопасно дать агенту право записи в инфраструктуру | [03_The_site_reliability_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/03_The_site_reliability_agent.ipynb) |
| Как подключить внешние системы через MCP | [02_The_observability_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/02_The_observability_agent.ipynb), [03](../references/claude-cookbooks/claude_agent_sdk/03_The_site_reliability_agent.ipynb), [CMA_operate_in_production.ipynb](../references/claude-cookbooks/managed_agents/CMA_operate_in_production.ipynb) |
| Автономный агент по расписанию с ограничителями | [scheduled_repository_reviewer.ipynb](../references/claude-cookbooks/claude_agent_sdk/scheduled_repository_reviewer/scheduled_repository_reviewer.ipynb) |
| Сессии: возобновление, форк, история | [05_Building_a_session_browser.ipynb](../references/claude-cookbooks/claude_agent_sdk/05_Building_a_session_browser.ipynb) |
| Как хостить агента (Docker / serverless / K8s, изоляция, egress) | [07_Hosting_the_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/07_Hosting_the_agent.ipynb), [hosting/](../references/claude-cookbooks/claude_agent_sdk/hosting/) |
| Как устроены Managed Agents (agent / environment / session, event loop) | [CMA_iterate_fix_failing_tests.ipynb](../references/claude-cookbooks/managed_agents/CMA_iterate_fix_failing_tests.ipynb), [managed_agents/README.md](../references/claude-cookbooks/managed_agents/README.md) |
| Координатор и команда специалистов в Managed Agents | [CMA_coordinate_specialist_team.ipynb](../references/claude-cookbooks/managed_agents/CMA_coordinate_specialist_team.ipynb) |
| Окупается ли «большая модель планирует, малые исполняют» | [CMA_plan_big_execute_small.ipynb](../references/claude-cookbooks/managed_agents/CMA_plan_big_execute_small.ipynb), [cost_optimization.ipynb](../references/claude-cookbooks/cost_optimization/cost_optimization.ipynb) |
| Слабая модель консультируется у сильной (advisor) | [CMA_consult_an_advisor.ipynb](../references/claude-cookbooks/managed_agents/CMA_consult_an_advisor.ipynb) |
| Как ограничить расходы агента или команды | [CMA_cap_session_spend.ipynb](../references/claude-cookbooks/managed_agents/CMA_cap_session_spend.ipynb); в SDK — `max_budget_usd`, `max_turns` в [scheduled_repository_reviewer](../references/claude-cookbooks/claude_agent_sdk/scheduled_repository_reviewer/scheduled_repository_reviewer.ipynb) |
| Как наблюдать за субагентами в реальном времени | [CMA_watch_subagents_live.ipynb](../references/claude-cookbooks/managed_agents/CMA_watch_subagents_live.ipynb) |
| Human-in-the-loop | [CMA_gate_human_in_the_loop.ipynb](../references/claude-cookbooks/managed_agents/CMA_gate_human_in_the_loop.ipynb), [CMA_operate_in_production.ipynb](../references/claude-cookbooks/managed_agents/CMA_operate_in_production.ipynb) (webhooks), [03_The_site_reliability_agent.ipynb](../references/claude-cookbooks/claude_agent_sdk/03_The_site_reliability_agent.ipynb) (разделение фаз) |
| Память агента между сессиями | [context_engineering_tools.ipynb](../references/claude-cookbooks/tool_use/context_engineering/context_engineering_tools.ipynb), [memory_cookbook.ipynb](../references/claude-cookbooks/tool_use/memory_cookbook.ipynb), [CMA_remember_user_preferences.ipynb](../references/claude-cookbooks/managed_agents/CMA_remember_user_preferences.ipynb) |
| Что делать, когда контекст долгого агента переполняется | [context_engineering_tools.ipynb](../references/claude-cookbooks/tool_use/context_engineering/context_engineering_tools.ipynb), [automatic-context-compaction.ipynb](../references/claude-cookbooks/tool_use/automatic-context-compaction.ipynb), [session_memory_compaction.ipynb](../references/claude-cookbooks/misc/session_memory_compaction.ipynb) |
| Консолидация опыта прошлых сессий в память | [CMA_dreaming_with_a_dynamic_workflow.ipynb](../references/claude-cookbooks/managed_agents/CMA_dreaming_with_a_dynamic_workflow.ipynb) |
| Слишком много инструментов / большие ответы инструментов | [tool_search_with_embeddings.ipynb](../references/claude-cookbooks/tool_use/tool_search_with_embeddings.ipynb), [programmatic_tool_calling_ptc.ipynb](../references/claude-cookbooks/tool_use/programmatic_tool_calling_ptc.ipynb) |
| Параллельные tool-вызовы в одном ходе | [parallel_tools.ipynb](../references/claude-cookbooks/tool_use/parallel_tools.ipynb) |
| Как оценивать агента и его инструменты | [tool_evaluation.ipynb](../references/claude-cookbooks/tool_evaluation/tool_evaluation.ipynb), [reproduce_agentic_search_benchmarks.ipynb](../references/claude-cookbooks/evals/agentic_search/reproduce_agentic_search_benchmarks.ipynb), [cost_optimization.ipynb](../references/claude-cookbooks/cost_optimization/cost_optimization.ipynb) (eval как ограничение), [building_evals.ipynb](../references/claude-cookbooks/misc/building_evals.ipynb) |
| Версионирование промпта агента и откат | [CMA_prompt_versioning_and_rollback.ipynb](../references/claude-cookbooks/managed_agents/CMA_prompt_versioning_and_rollback.ipynb) (не читал) |
| Снизить стоимость / латентность агентного цикла | [cost_optimization.ipynb](../references/claude-cookbooks/cost_optimization/cost_optimization.ipynb), [latency_optimization.ipynb](../references/claude-cookbooks/latency_optimization/latency_optimization.ipynb) |
| Трейсинг и учёт расходов | [opentelemetry_tracing.ipynb](../references/claude-cookbooks/observability/opentelemetry_tracing.ipynb), [usage_cost_api.ipynb](../references/claude-cookbooks/observability/usage_cost_api.ipynb), [04](../references/claude-cookbooks/claude_agent_sdk/04_migrating_from_openai_agents_sdk.ipynb) (раздел Tracing) |
| Ревью сессий агентов на безопасность | [CMA_agent_security_review.ipynb](../references/claude-cookbooks/managed_agents/CMA_agent_security_review.ipynb) (не читал) |

## 5. Ключевые идеи и приёмы

1. **Главный вопрос архитектуры — кто держит план.** Модель ход за ходом (один агент, субагенты) или детерминированный скрипт (workflow). От этого зависят масштаб, воспроизводимость и гарантии. — `claude_agent_sdk/08_Dynamic_workflows.ipynb`
2. **Orchestrator-workers оправдан, когда подзадачи нельзя предсказать заранее.** Если они предсказуемы — достаточно параллелизации; если задача простая или критична латентность — паттерн избыточен. Цена — N+1 вызовов. — `patterns/agents/orchestrator_workers.ipynb`
3. **Субагент — это прежде всего изоляция контекста.** Он поглощает объёмное чтение и возвращает выжимку; контекст оркестратора остаётся чистым. Параллелизм и специализация — вторичные выгоды. — `managed_agents/CMA_plan_big_execute_small.ipynb`, `cost_optimization/cost_optimization.ipynb`, `managed_agents/CMA_coordinate_specialist_team.ipynb`
4. **Оркестратор знает о воркерах только то, что написано в его промпте.** Системные промпты воркеров ему не видны; бриф — единственный канал. Хороший бриф: одна цель, формат вывода, контекст, источники, инструменты, границы. — `managed_agents/CMA_plan_big_execute_small.ipynb`, `patterns/agents/prompts/research_lead_agent.md`
5. **Число субагентов масштабируется по сложности, и у гранулярности есть оптимум.** 1 → 2–3 → 3–5 → 5–10, потолок 20; у каждого thread'а фиксированные накладные расходы, более мелкое дробление увеличивало счёт. — `patterns/agents/prompts/research_lead_agent.md`, `managed_agents/CMA_plan_big_execute_small.ipynb`
6. **Верификация должна быть структурной, а не инструкцией.** «Перепроверь себя» под давлением контекста пропускается; отдельный проверяющий с чистым контекстом (skeptic, grader, triage) — нет. Автор работы не должен быть её единственным судьёй. — `claude_agent_sdk/08_Dynamic_workflows.ipynb`, `managed_agents/CMA_verify_with_outcome_grader.ipynb`, `claude_agent_sdk/06_The_vulnerability_detection_agent.ipynb`
7. **Проверяющий по умолчанию одобряет всё.** Критерии должны требовать предъявить доказательство и быть конкретнее самой задачи. — `managed_agents/CMA_verify_with_outcome_grader.ipynb`
8. **Проверяется только то, что включено в стандарт проверки.** Все факты были проверены, а сама декомпозиция вопроса (взятая из памяти модели) — нет, и была ошибочна. Проверяйте и посылку. — `managed_agents/CMA_plan_big_execute_small.ipynb`
9. **Декомпозиция теряет точность там, где знание сжимают.** Сжатая «карточка правил» для дешёвого решателя потеряла исключение из исключения; дешёвый читатель может «усреднить» ровно то, что важно. — `cost_optimization/cost_optimization.ipynb`, `managed_agents/CMA_plan_big_execute_small.ipynb`
10. **Сравнивать мультиагентную схему с одиночной можно только при равной строгости.** Иначе сравниваются разные продукты. Мерить стоимость выполненной задачи, фиксировать планку качества eval'ом, затем минимизировать цену. — `managed_agents/CMA_plan_big_execute_small.ipynb`, `cost_optimization/cost_optimization.ipynb`
11. **Описания инструментов важнее длинных промптов.** Простой системный промпт и чёткие описания инструментов дают автономное поведение; у инструментов тоже можно собирать фидбек от агента. — `claude_agent_sdk/03_The_site_reliability_agent.ipynb`, `tool_evaluation/tool_evaluation.ipynb`
12. **Scoping инструментов по ролям — и качество, и безопасность.** Роль физически не может сделать лишнего; агент, читающий недоверенный ввод, имеет минимальный blast radius, а оркестратор без инструментов видит только отчёты. — `managed_agents/CMA_coordinate_specialist_team.ipynb`, `managed_agents/CMA_plan_big_execute_small.ipynb`
13. **Ограничители — на уровне платформы или кода, а не промпта.** Бюджет сессии, `max_turns`, `max_budget_usd`, лимит одновременных helper'ов, `max_iterations`; fan-out зависит от данных, поэтому потолок обязателен. — `managed_agents/CMA_cap_session_spend.ipynb`, `claude_agent_sdk/scheduled_repository_reviewer/scheduled_repository_reviewer.ipynb`, `patterns/agents/latency_multi_agent.ipynb`
14. **Детерминированные хуки дополняют свободу агента.** Валидация содержимого изменений до вызова и аудит после; защита в два слоя (обработчик инструмента + хук). — `claude_agent_sdk/03_The_site_reliability_agent.ipynb`, `claude_agent_sdk/01_The_chief_of_staff_agent.ipynb`
15. **Сообщения между агентами доставляются «попутно».** Входящие дописываются к очередному tool result — агенту не нужно опрашивать почту; `send_message` — единственный канал; финальный отчёт helper'а уходит lead'у автоматически; упавший helper не должен ронять команду. — `patterns/agents/async_multi_agent_orchestration.ipynb`, `patterns/agents/latency_multi_agent.ipynb`
16. **Контекст — конечный ресурс с убывающей отдачей.** Context rot наступает до лимита. Три разных инструмента под три разные проблемы: clearing (tool results), compaction (весь разговор), memory (между сессиями); кастомные инструкции компакции должны сохранять исходную задачу и формат ответа. — `tool_use/context_engineering/context_engineering_tools.ipynb`, `evals/agentic_search/reproduce_agentic_search_benchmarks.ipynb`
17. **Не пропускать сырые данные через модель.** PTC и скриптовая логика между агентами делают фильтрацию и агрегацию «бесплатными» по токенам. — `tool_use/programmatic_tool_calling_ptc.ipynb`, `claude_agent_sdk/08_Dynamic_workflows.ipynb`
18. **Разные модели на разные роли — в обе стороны.** Сильная планирует, дешёвые исполняют; либо средняя работает и консультируется с сильной (advisor) — последнее работает, когда есть дешёвый сигнал для консультации. Плюс `effort` по ролям. — `managed_agents/CMA_plan_big_execute_small.ipynb`, `managed_agents/CMA_consult_an_advisor.ipynb`, `cost_optimization/cost_optimization.ipynb`, `managed_agents/CMA_watch_subagents_live.ipynb`
19. **Делегирование, а не передача управления.** В экосистеме Claude оркестратор вызывает субагента и остаётся главным; финальный синтез пишет lead, а не субагент. — `claude_agent_sdk/04_migrating_from_openai_agents_sdk.ipynb`, `patterns/agents/prompts/research_lead_agent.md`
20. **Человек в контуре — через разделение фаз и паузы.** Расследование отдельно от исправления; план → ревью → исполнение; custom tool `escalate()`; в продакшене — webhooks вместо удерживаемого соединения. — `claude_agent_sdk/03_The_site_reliability_agent.ipynb`, `claude_agent_sdk/01_The_chief_of_staff_agent.ipynb`, `managed_agents/CMA_gate_human_in_the_loop.ipynb`
21. **Время и срочность можно сообщить агентам явно.** Общие часы команды в хвосте сообщения (не ломая кеш) и одна фраза давления или число бюджета меняют темп работы. — `patterns/agents/latency_multi_agent.ipynb`
22. **Параллельный старт субагентов с общим длинным контекстом теряет кеш.** Прогрейте кеш заранее или запускайте остальных после начала ответа первого. — `latency_optimization/latency_optimization.ipynb`

## 6. Ограничения и оговорки

**Чего в репозитории нет.**

- Очередей задач, брокеров сообщений, durable-состояния оркестратора вне Managed Agents — в `patterns/agents/` всё в памяти одного процесса (`asyncio`, `ThreadPoolExecutor`). Ближе всего к теме — `claude_agent_sdk/hosting/kubernetes/` (gateway → Redis → pod), но это маршрутизация сессий, а не очередь задач.
- Ретраев, идемпотентности, обработки частичных отказов воркеров в коде паттернов: в `orchestrator_workers.ipynb` это только перечислено в «Next steps».
- Фазы синтеза в `orchestrator_workers.ipynb` (тоже только в «Next steps»).
- Количественного сравнения паттернов между собой на одной задаче. Измерения есть лишь в отдельных ноутбуках (`CMA_plan_big_execute_small`, `cost_optimization`, `latency_optimization`) и на синтетических нагрузках.
- Сравнений с LangGraph, Swarms, Microsoft Agent Framework. Из чужих фреймворков — только туториалы LlamaIndex и LangChain в `third_party/` и миграция с OpenAI Agents SDK.
- Законченного примера research-системы, использующего промпты из `patterns/agents/prompts/`: промпты есть, harness'а под них (`run_blocking_subagent`, `complete_task`) в репозитории нет.

**Учебное, не production.**

- `patterns/agents/basic_workflows.ipynb` прямо помечен «not production code»; `util.py` — вызов без ретраев и обработки ошибок, `extract_xml` молча возвращает пустую строку.
- `orchestrator_workers.ipynb`: последовательные воркеры при заявленном «parallel», нет синтеза, `context` не доходит до промптов, срез описания подзадачи в `parse_tasks` оставляет лишние `>` и `<` (подробности в п. 3.1; срез проверен отдельным запуском). Это мои наблюдения по коду, в тексте ноутбука их нет.
- `evaluator_optimizer.ipynb`: цикл `while True` без лимита итераций.
- `route()` в `basic_workflows.ipynb` не валидирует выбранный маршрут.
- `multimodal/using_sub_agents.ipynb` и `tool_evaluation.ipynb` используют `exec` / `eval` — авторы сами оговаривают, что это только для демо.
- Почти все агентные примеры работают на синтетических данных и симулированных инструментах (mock `gh`, симулированные threat-intel API, выдуманные компании).
- `latency_multi_agent.ipynb` не измеряет влияние давления на качество, не содержит задачи и, по словам авторов, не полностью протестирован на Opus 5 и более ранних моделях.

**Что может устареть.**

- Managed Agents и связанные фичи — в бете (`managed-agents-2026-04-01`; PTC — `advanced-tool-use-2025-11-20`; компакция в tool runner — `compact-2026-09-04`); формы API и идентификаторы версий инструментов (`agent_toolset_20260401`, `memory_20250818`, `compact_20260112`, `code_execution_20260521`) привязаны к датам.
- `CMA_plan_big_execute_small.ipynb` сам оговаривает, что цифры получены до перехода на тип `multiagent_20261001`. В `CMA_coordinate_specialist_team.ipynb` используется форма `{"type": "coordinator", "agents": […]}`, а текст `plan_big` описывает уже другую (`subagents.predefined_agents`, отключение `inline_agents` и `workflows`) — между ноутбуками есть расхождение поколений API. Точную актуальную форму нужно сверять с документацией: полный код конфигурации в `plan_big` я не читал.
- В тексте `plan_big` говорится о лимите $10, а в коде стоит `"amount": "1000"`; по `CMA_cap_session_spend.ipynb` сумма задаётся в центах, то есть это согласуется, но в ячейке не пояснено.
- Dynamic workflows требуют `claude-agent-sdk>=0.2.90`; инструмент субагентов переименован из `Task` в `Agent` в SDK 0.2; поведение `setting_sources` по умолчанию изменилось в 0.2.
- Цены в `cost_optimization`, `latency_optimization`, `plan_big` — захардкоженные снимки прайса на момент написания.
- Имена моделей в ноутбуках — недатированные алиасы (`claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5`, `claude-fable-5-1`); ограничения вроде «forced `tool_choice` отклоняется на Opus/Sonnet/Fable» привязаны к этому поколению.
- Корневой `README.md` отстаёт от содержимого (см. п. 2). Раздел «Project Structure» в `CLAUDE.md` репозитория тоже не упоминает `patterns/`, `claude_agent_sdk/`, `managed_agents/`.
- Вывод ячеек (сохранённые результаты прогонов) я не читал, поэтому конкретные цифры в конспекте — только те, что авторы повторяют в markdown.

**Что именно и насколько глубоко прочитано.**

- *Целиком (markdown + код):* `patterns/agents/` — README, `util.py`, все три файла `prompts/`, все пять ноутбуков.
- *Почти целиком (длинные ячейки кода обрезаны):* `claude_agent_sdk/00`, `01`, `08`; `managed_agents/CMA_coordinate_specialist_team`; `tool_evaluation`; `multimodal/using_sub_agents`; `tool_use/parallel_tools`.
- *Markdown полностью или почти, код — только начала ячеек:* `claude_agent_sdk/02`, `03`, `04`; `managed_agents/CMA_plan_big_execute_small`, `CMA_verify_with_outcome_grader`, `CMA_consult_an_advisor`.
- *Markdown с обрезкой длинных ячеек, без кода:* `managed_agents/CMA_watch_subagents_live`, `CMA_cap_session_spend`, `CMA_gate_human_in_the_loop`, `CMA_iterate_fix_failing_tests`, `CMA_operate_in_production`, `CMA_remember_user_preferences`, `CMA_dreaming_with_a_dynamic_workflow` (конец обрезан), `CMA_orchestrate_issue_to_pr` (только начало).
- *Выборочно:* `tool_use/context_engineering/context_engineering_tools` (вступление и заключительная треть; разделы про clearing и середина про memory — нет), `tool_use/memory_cookbook` (первая треть), `tool_use/automatic-context-compaction` (первая половина), `tool_use/programmatic_tool_calling_ptc` (markdown почти весь), `tool_search_*` (вступления), `misc/session_memory_compaction` (первая половина), `evals/agentic_search` (первая половина), `cost_optimization` и `latency_optimization` (вступление, разделы про субагентов и advisor, выводы — из примерно 110 КБ текста каждого прочитана малая часть).
- *Только заголовки или первые ячейки:* `claude_agent_sdk/05`, `06`, `07`, `scheduled_repository_reviewer`; `observability/*`; `tool_use/threat_intel_enrichment_agent`, `customer_service_agent`, `tool_choice`; `extended_thinking/extended_thinking_with_tool_use`; `misc/building_evals`; `skills/notebooks/01`; `third_party/LlamaIndex/*`, `third_party/Pinecone/claude_3_rag_agent`; `fable_5_fallback_billing/guide`.
- *Не читал вообще (только название и описание в `registry.yaml` или README):* `managed_agents/CMA_agent_security_review`, `CMA_explore_unfamiliar_codebase`, `CMA_prompt_versioning_and_rollback`, `CMA_use_skills_from_a_repo`, `CMA_pin_inference_geo`, `CMA_with_mongodb_atlas`, `data_analyst_agent`, `slack_data_bot`, `sre_incident_responder`; README подкаталогов `managed_agents/` (`cma-mcp`, `linear`, `sentry`, `slack`, `roadtrip_planner`, `self_hosted_sandboxes`, `mongodb_on_cma`); весь companion-код `claude_agent_sdk/` (`*/agent.py`, `sre_mcp_server.py`, `infra_setup.py`, `hosting/*`, `utils/*`, `scheduled_review.py`) кроме `financial-analyst.md`; `managed_agents/utilities.py` (только имена функций), `log_review_utils.py`, `memory_distill_utils.py`, `dreaming_nightly.py`, `example_data/`; `tool_use/memory_tool.py`, `tool_use/utils/`, `tool_use/memory_demo/`; `evals/agentic_search/utils/agentic_search.py`; `latency_optimization/desk_agent/`; все ноутбуки `capabilities/`, `multimodal/` (кроме `using_sub_agents`), `finetuning/`, `coding/`, остальные `misc/`, `skills/notebooks/02`–`03`, остальные `third_party/`; `CONTRIBUTING.md`, `.claude/`, `.github/`, `scripts/`, `tests/`.
