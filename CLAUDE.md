# CLAUDE.md

Исследовательский проект: сбор материалов по автономным агентским системам и их построению.

## Контекст проекта → BRIEF.md

Весь контекст проекта хранится в [BRIEF.md](BRIEF.md). Здесь — только ссылки на его разделы:

- [Цель](BRIEF.md#цель)
- [Область исследования](BRIEF.md#область-исследования)
- [Структура репозитория](BRIEF.md#структура-репозитория)
- [Соглашения](BRIEF.md#соглашения)
- [Карта конспектов](BRIEF.md#карта-конспектов) — навигация по `notes/`: [по источникам](BRIEF.md#по-источникам) и [по вопросам](BRIEF.md#по-вопросам)
- [Ключевые выводы](BRIEF.md#ключевые-выводы)
- [Расхождения между источниками](BRIEF.md#расхождения-между-источниками)
- [Решения](BRIEF.md#решения)
- [Открытые вопросы](BRIEF.md#открытые-вопросы)

**Правила работы с контекстом:**
- Новый контекст, выводы и решения записывай в `BRIEF.md` в подходящий раздел, а не сюда.
- Если появился новый раздел в `BRIEF.md` — добавь на него ссылку в список выше.
- `CLAUDE.md` — навигация и инструкции; `BRIEF.md` — содержание.

**Как искать ответ:** сначала [карта конспектов](BRIEF.md#карта-конспектов) → нужный конспект в `notes/` → из него по номерам строк в статью (`docs/`) или по ссылкам в репозиторий (`references/`). Не читай `docs/` и `references/` целиком, пока конспект не указал место.

## Материалы

### Статьи (`docs/`)

| Материал | Конспект | Локальная копия | Оригинал |
|----------|----------|-----------------|----------|
| Anthropic — Building effective AI agents | [notes/anthropic-building-effective-agents.md](notes/anthropic-building-effective-agents.md) | [docs/anthropic-building-effective-agents.md](docs/anthropic-building-effective-agents.md) | [anthropic.com](https://www.anthropic.com/research/building-effective-agents) |
| Claude Cookbook — Orchestrator-Workers | [notes/claude-cookbook-orchestrator-workers.md](notes/claude-cookbook-orchestrator-workers.md) | [docs/claude-cookbook-orchestrator-workers.md](docs/claude-cookbook-orchestrator-workers.md) | [platform.claude.com](https://platform.claude.com/cookbook/patterns-agents-orchestrator-workers) |
| Swarms — Orchestrator-Worker Pattern for LLM Agents | [notes/swarms-orchestrator-worker-pattern.md](notes/swarms-orchestrator-worker-pattern.md) | [docs/swarms-orchestrator-worker-pattern.md](docs/swarms-orchestrator-worker-pattern.md) | [swarms.ai](https://www.swarms.ai/blog/orchestrator-worker-pattern-llm-agents) |
| BackendBytes — Multi-agent orchestrator backend | [notes/backendbytes-multi-agent-orchestrator-backend.md](notes/backendbytes-multi-agent-orchestrator-backend.md) | [docs/backendbytes-multi-agent-orchestrator-backend.md](docs/backendbytes-multi-agent-orchestrator-backend.md) | [backendbytes.com](https://backendbytes.com/articles/multi-agent-orchestrator-backend/) |
| MetaCTO — AI agent orchestration patterns | [notes/metacto-ai-agent-orchestration-patterns.md](notes/metacto-ai-agent-orchestration-patterns.md) | [docs/metacto-ai-agent-orchestration-patterns.md](docs/metacto-ai-agent-orchestration-patterns.md) | [metacto.com](https://www.metacto.com/blogs/ai-agent-orchestration-patterns) |
| Microsoft Azure — AI agent orchestration patterns | [notes/microsoft-azure-ai-agent-design-patterns.md](notes/microsoft-azure-ai-agent-design-patterns.md) | [docs/microsoft-azure-ai-agent-design-patterns.md](docs/microsoft-azure-ai-agent-design-patterns.md) | [learn.microsoft.com](https://learn.microsoft.com/en-us/azure/architecture/ai-ml/guide/ai-agent-design-patterns) |
| Vantaige — Orchestrator-workers multi-agent pattern (2026) | [notes/vantaige-orchestrator-workers-2026.md](notes/vantaige-orchestrator-workers-2026.md) | [docs/vantaige-orchestrator-workers-2026.md](docs/vantaige-orchestrator-workers-2026.md) | [vantaige.io](https://vantaige.io/blog/orchestrator-workers-multi-agent-pattern-2026) |
| Built with LangGraph #31 — Orchestrator-Worker ⚠️ не скачана | — | [docs/plainenglish-langgraph-orchestrator-worker.md](docs/plainenglish-langgraph-orchestrator-worker.md) | [ai.plainenglish.io](https://ai.plainenglish.io/built-with-langgraph-31-orchestrator-worker-design-pattern-aa4ed663fc17) |
| Glukhov — Multi-agent orchestration patterns | [notes/glukhov-multi-agent-orchestration-patterns.md](notes/glukhov-multi-agent-orchestration-patterns.md) | [docs/glukhov-multi-agent-orchestration-patterns.md](docs/glukhov-multi-agent-orchestration-patterns.md) | [glukhov.org](https://www.glukhov.org/ai-systems/architecture/multi-agent-orchestration-patterns/) |
| CompileNRun — Orchestrator-Workers | [notes/compilenrun-agents-orchestrator-workers.md](notes/compilenrun-agents-orchestrator-workers.md) | [docs/compilenrun-agents-orchestrator-workers.md](docs/compilenrun-agents-orchestrator-workers.md) | [compilenrun.com](https://www.compilenrun.com/docs/ai/agents/agents-multi-agent-systems/agents-orchestrator-workers/) |

### Репозитории (`references/`, git-сабмодули)

| Репозиторий | Конспект | Локальная копия | Оригинал |
|-------------|----------|-----------------|----------|
| anthropics/claude-cookbooks | [notes/claude-cookbooks.md](notes/claude-cookbooks.md) | [references/claude-cookbooks/](references/claude-cookbooks/) | [GitHub](https://github.com/anthropics/claude-cookbooks) |

Ключевые места в `claude-cookbooks`:
- [patterns/agents/orchestrator_workers.ipynb](references/claude-cookbooks/patterns/agents/orchestrator_workers.ipynb) — паттерн orchestrator-workers
- [patterns/agents/basic_workflows.ipynb](references/claude-cookbooks/patterns/agents/basic_workflows.ipynb) — prompt chaining, routing, parallelization
- [patterns/agents/evaluator_optimizer.ipynb](references/claude-cookbooks/patterns/agents/evaluator_optimizer.ipynb) — evaluator-optimizer
- [patterns/agents/async_multi_agent_orchestration.ipynb](references/claude-cookbooks/patterns/agents/async_multi_agent_orchestration.ipynb) — асинхронная мультиагентная оркестрация
- [patterns/agents/latency_multi_agent.ipynb](references/claude-cookbooks/patterns/agents/latency_multi_agent.ipynb) — задержки в мультиагентных системах
- [claude_agent_sdk/](references/claude-cookbooks/claude_agent_sdk/), [managed_agents/](references/claude-cookbooks/managed_agents/) — агенты на Claude Agent SDK и Managed Agents

## Добавление материалов

Порядок описан в [BRIEF.md → Соглашения](BRIEF.md#соглашения). Новые материалы всегда добавляй в таблицы выше.
