# packages/ — индекс пакетов Меркурия

Компоненты Меркурия, вынесенные в отдельные репозитории `github.com/opencck/mercurius-<имя>`, подключены сюда
git-сабмодулями (ветка `master`). Правила — в [CLAUDE.md](../CLAUDE.md) (пункт «Пакеты») и
[BRIEF.md → Качество кода и CI](../BRIEF.md#качество-кода-и-ci).

## Подключённые пакеты

| Пакет | Каталог | Коммит | Проверено | Назначение |
|-------|---------|--------|-----------|------------|
| [mercurius-llm](https://github.com/opencck/mercurius-llm) | [llm/](llm/) | `83c021f` | 2026-11-16 | Единый интерфейс вызова LLM, адаптер OpenRouter |
| [mercurius-connectors](https://github.com/opencck/mercurius-connectors) | [connectors/](connectors/) | `6920581` | 2026-11-16 | Маршруты исходящих соединений: напрямую, HTTP CONNECT, SOCKS5, VLESS |
| [mercurius-messenger](https://github.com/opencck/mercurius-messenger) | [messenger/](messenger/) | `6744d13` | 2026-11-16 | Канал связи и адаптер Telegram |

Остальные пакеты (`command-policy`, `memory`, `browser-service`, `mcp-client`, `peer`) подключаются в своих фазах.

## Как работать с пакетом

- Код пакета правится в его каталоге и коммитится в его репозиторий (`master`), затем в основном репозитории
  коммитится новый указатель сабмодуля.
- В основном `composer.json` пакеты подключены Composer-репозиториями типа `path` с копированием (`symlink: false`).
  Версия у пакета всегда `dev-master`, поэтому `composer update` копию не обновляет: после правки пакета —
  `rm -rf vendor/opencck/mercurius-<имя> && composer install`.
- В Docker-образ пакеты попадают при сборке из каталога `packages/` (после `git submodule update --init`).
- У каждого пакета свой `composer check` и свой CI.

## Журнал

### 2026-11-16 — llm: индексы моделей в каталоге

- `mercurius-llm` `83c021f`: `ModelInfo` получил индексы Artificial Analysis (интеллект, код, агентность) из
  `benchmarks.artificial_analysis` ответа `/models`. Нужно выбору модели по профилю задачи (фаза 5, часть 2).

### 2026-11-16 — messenger: кнопки, нажатия, файлы

- `mercurius-messenger` `6744d13`: `Button` и кнопки у `OutgoingMessage` и `edit()` (правка без кнопок убирает их);
  `IncomingCallback`, `CallbackHandler` и `ChatDispatcher::dispatchCallback` — нажатия идут в обход очереди чата;
  `Channel::answerCallback`, `Channel::sendFile`. Нужно подтверждениям агента.

### 2026-11-16 — messenger: разметка сверена с hermes

- `mercurius-messenger` `cbb9e93`: конвертер сверен с адаптером Telegram в hermes-agent (`telegram_markdown.py`,
  `telegram_split.py`, `gateway/platforms/helpers.py`): плоский текст без маркеров при отказе разметки, проверка адресов
  ссылок, голые URL вне выделения, таблицы — группами пунктов. Ссылки `ref:` — в коде.

### 2026-11-16 — messenger: разметка ответов модели

- `mercurius-messenger` `0e50a11`: режим `ParseMode::Markdown` и `MarkdownToTelegramHtml` — Markdown, который пишет
  модель, уходит в Telegram как HTML; при отказе Telegram — исходный текст без разметки. Приложение отправляет ответы
  модели в этом режиме, служебные тексты — плоским текстом.

### 2026-11-16 — подключены llm, connectors, messenger

- `mercurius-llm` `cdc9a0b`: `LlmClient`, потоковый клиент OpenRouter, `RetryingClient`, каталог моделей.
- `mercurius-connectors` `6920581`: маршруты напрямую, HTTP CONNECT, SOCKS5, VLESS и их цепочки.
- `mercurius-messenger` `a1a2f2a`: `Channel`, адаптер Telegram (вебхук, long polling, разбиение сообщений, очередь по чатам).
