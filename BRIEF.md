# BRIEF — контекст проекта mercurius

Что строим и почему: требования к агенту, принятые решения, открытые вопросы и база знаний, на которую
опирается разработка. Порядок работ — в [ROADMAP.md](ROADMAP.md) (фазы закрыты) и в
[ROADMAP_FINALIZATION.md](ROADMAP_FINALIZATION.md) (доработки по итогам сверки), правила работы — в [CLAUDE.md](CLAUDE.md).

`CLAUDE.md` и `ROADMAP.md` ссылаются на разделы этого файла по якорям: заголовки разделов не переименовывать
без правки ссылок.

## Оглавление

Спецификация разнесена по подсистемам; путь в `spec:` — от корня репозитория (`spec: brief/<файл>.md#<якорь>`).

- [Требования](brief/requirements.md) — [Цель](brief/requirements.md#цель), [Меркурий: требования](brief/requirements.md#меркурий-требования)
- [Устройство и соглашения](brief/architecture.md) — [Устройство приложения на opencck](brief/architecture.md#устройство-приложения-на-opencck), [Структура репозитория](brief/architecture.md#структура-репозитория), [Соглашения](brief/architecture.md#соглашения)
- [Агентский цикл](brief/agent-loop.md) — [Агентский цикл и харнесс](brief/agent-loop.md#подходы), [Знания агента о себе](brief/agent-loop.md#агентский-цикл-и-харнесс)
- [Модели](brief/models.md) — [Выбор моделей под задачу](brief/models.md#выбор-моделей-под-задачу)
- [Оркестратор и воркеры](brief/orchestration.md) — [Оркестратор и воркеры](brief/orchestration.md#оркестратор-и-воркеры-1)
- [Память](brief/memory.md) — [Память: слои](brief/memory.md#память-слои)
- [Серверы и сеть](brief/servers.md) — [Серверы и сеть](brief/servers.md#серверы-и-сеть-1)
- [Интернет и вложения](brief/internet.md) — [Интернет и браузер](brief/internet.md#интернет-и-браузер), [Входящие вложения](brief/internet.md#входящие-вложения)
- [Скиллы, MCP и модули](brief/skills-mcp.md) — [Скиллы и MCP](brief/skills-mcp.md#скиллы-и-mcp), [Модули: сервисы, MCP и скиллы](brief/skills-mcp.md#модули-сервисы-mcp-и-скиллы)
- [Планировщик и проекты](brief/scheduler.md) — [Планировщик и проекты](brief/scheduler.md#планировщик-и-проекты-1)
- [Каналы](brief/channels.md) — [Telegram: группы, каналы и темы](brief/channels.md#telegram-группы-каналы-и-темы), [Личные сообщения участникам](brief/channels.md#личные-сообщения-участникам)
- [Сеть агентов](brief/network.md) — [Сеть агентов](brief/network.md#сеть-агентов-1)
- [Участники и права](brief/participants.md) — [Несколько пользователей](brief/participants.md#несколько-пользователей), [Права доступа к сущностям](brief/participants.md#права-доступа-к-сущностям)
- [Обслуживание](brief/operations.md) — [Настройки агента](brief/operations.md#трассировка-и-метрики), [Язык агента](brief/operations.md#решено-в-фазе-4), [Трассировка и метрики](brief/operations.md#настройки-агента), [Экспорт и импорт агента](brief/operations.md#экспорт-и-импорт-агента), [Поставка и обслуживание](brief/operations.md#поставка-и-обслуживание)
- [Решения и открытые вопросы](brief/decisions.md) — [Решения](brief/decisions.md#решения), [Открытые вопросы](brief/decisions.md#открытые-вопросы)
- [База знаний](brief/knowledge.md) — [База знаний](brief/knowledge.md#база-знаний), [Карта конспектов](brief/knowledge.md#карта-конспектов), [Ключевые выводы](brief/knowledge.md#ключевые-выводы), [Расхождения между источниками](brief/knowledge.md#расхождения-между-источниками)
