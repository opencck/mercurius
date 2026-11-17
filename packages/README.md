# packages/ — индекс пакетов Меркурия

Компоненты Меркурия, вынесенные в отдельные репозитории `github.com/opencck/mercurius-<имя>`, подключены сюда
git-сабмодулями (ветка `master`). Правила — в [CLAUDE.md](../CLAUDE.md) (пункт «Пакеты») и
[BRIEF.md → Качество кода и CI](../BRIEF.md#качество-кода-и-ci).

## Подключённые пакеты

| Пакет | Каталог | Коммит | Проверено | Назначение |
|-------|---------|--------|-----------|------------|
| [mercurius-llm](https://github.com/opencck/mercurius-llm) | [llm/](llm/) | `0907de0` | 2026-11-16 | Единый интерфейс вызова LLM, адаптеры OpenRouter и OpenAI |
| [mercurius-connectors](https://github.com/opencck/mercurius-connectors) | [connectors/](connectors/) | `6920581` | 2026-11-16 | Маршруты исходящих соединений: напрямую, HTTP CONNECT, SOCKS5, VLESS |
| [mercurius-messenger](https://github.com/opencck/mercurius-messenger) | [messenger/](messenger/) | `7e046dc` | 2026-11-17 | Канал связи и адаптер Telegram |
| [mercurius-search](https://github.com/opencck/mercurius-search) | [search/](search/) | `47e3d0c` | 2026-11-17 | Поиск в интернете за одним интерфейсом: SearXNG, Brave, Tavily, OpenRouter, порядок и кеш |
| [mercurius-i18n](https://github.com/opencck/mercurius-i18n) | [i18n/](i18n/) | `f0cdc30` | 2026-11-17 | Тексты агента на русском и английском: `Translator`, каталоги, формы числа |
| [mercurius-mail-mcp](https://github.com/opencck/mercurius-mail-mcp) | [mail-mcp/](mail-mcp/) | `6a68991` | 2026-11-17 | MCP-сервер почты со своим асинхронным IMAP и SMTP, проверка подлинности отправителя |

Остальные пакеты (`command-policy`, `memory`, `browser-service`, `mcp-client`, `peer`) пока живут в основном репозитории и выносятся, когда интерфейс устоится.

## Как работать с пакетом

- Код пакета правится в его каталоге и коммитится в его репозиторий (`master`), затем в основном репозитории
  коммитится новый указатель сабмодуля.
- В основном `composer.json` пакеты подключены Composer-репозиториями типа `path` с копированием (`symlink: false`).
  Версия у пакета всегда `dev-master`, поэтому `composer update` копию не обновляет: после правки пакета —
  `rm -rf vendor/opencck/mercurius-<имя> && composer install`.
- В Docker-образ пакеты попадают при сборке из каталога `packages/` (после `git submodule update --init`).
- У каждого пакета свой `composer check` и свой CI.

## Журнал

### 2026-11-17 — i18n: карточка дневного лимита группы

- `mercurius-i18n` `f0cdc30` (после `019f0de` были ещё правки текстов отказов и безопасности): тексты карточки о
  достигнутом дневном лимите группы с кнопками «Поднять до …» и «Снять лимит навсегда».

### 2026-11-17 — i18n: тексты новых возможностей

- `mercurius-i18n` `16b5116`, `743c104`, `019f0de`: тексты карточки гостя из группы и решения о правах участника,
  импорта скиллов и файлов скилла, секрета в `http_request`.

### 2026-11-17 — i18n: все тексты агента

- `mercurius-i18n` `c9c21df`: каталоги `ru` и `en` — 1146 текстов, перенесённых из кода приложения; проверка
  совпадения учитывает форматы `sprintf` и их порядок.

### 2026-11-17 — подключён i18n

- Новый пакет `mercurius-i18n` (`1ceb9bd`, требование владельца 2026-11-17 — язык агента настраивается):
  `Translator`, `CatalogTranslator`, `Plural`, `Lang` с `t()`/`tp()`, каталоги `lang/ru.php` и `lang/en.php`
  (пока ответы чата); проверка одинаковых ключей и подстановок. Устройство — [BRIEF.md → Язык агента](../BRIEF.md#язык-агента).

### 2026-11-17 — messenger: посты канала и права бота

- `mercurius-messenger` `7e046dc`: `IncomingPost`, `PostHandler`, `UpdateParser::parsePost()`,
  `ChatDispatcher::dispatchPost()` — `channel_post` и `edited_channel_post` в обход очереди чата;
  `IncomingMembership::$rights` — права администратора. Приложение: `ChannelRegistry` и инструмент `channel_post`.

### 2026-11-17 — messenger: темы форума

- `mercurius-messenger` `d29de6d`: `Channel::createTopic()` (номер темы или null), `editTopic`, `closeTopic`,
  `reopenTopic`, `deleteTopic`; имя темы обрезается до 128 символов. Приложение: проекты ведут свои темы.

### 2026-11-17 — messenger: добавление и удаление бота

- `mercurius-messenger` `5c4993d`: `IncomingMembership`, `MembershipHandler`, `UpdateParser::parseMembership()`,
  `ChatDispatcher::dispatchMembership()` — обновления `my_chat_member` в обход очереди чата; long polling
  запрашивает их по умолчанию. Приложение передаёт их в `GroupGate`: карточка владельцу — сразу при добавлении.

### 2026-11-17 — messenger: выход из чата

- `mercurius-messenger` `3e5a367`: `Channel::leave()` и `TelegramApi::leaveChat()` — бот выходит из группы, которую
  владелец не одобрил.

### 2026-11-17 — подключён mail-mcp

- Новый пакет `mercurius-mail-mcp` (`6a68991`, решение владельца 2026-11-17 — почта встроенным клиентом IMAP/SMTP в
  виде MCP): `mail_folders`, `mail_list`, `mail_read` (только чтение, письма не помечаются прочитанными), `mail_send`
  (необратимое — карточка владельца); проверка подлинности отправителя по `Authentication-Results` (образец — почтовый
  адаптер hermes). Учётные данные сервер не хранит: приложение шлёт ящик из настроек агента в заголовках.
- В приложении — сервис `mail-mcp` в compose и встроенный MCP-сервер `mail`; ящик — настройки `MAIL_*`.

### 2026-11-17 — подключён search

- Новый пакет `mercurius-search` (`47e3d0c`, решение владельца 2026-11-17 — поиск через интерфейс): `SearchProvider`,
  поставщики SearXNG, Brave, Tavily, OpenRouter (веб-плагин), `FallbackSearch` по порядку, `CachedSearch` на 10 минут.
  Образец — поставщики поиска hermes (`agent/web_search_provider.py`, `plugins/web/*`).
- В приложении `web_search` работает через пакет; порядок — `SEARCH_PROVIDERS`, по умолчанию `openrouter` (как было).

### 2026-11-17 — messenger: удаление сообщения

- `mercurius-messenger` `7ecab10`: `Channel::delete()` и `TelegramApi::deleteMessage()` — бот удаляет сообщение, в
  котором владелец прислал секрет (`/secret`); сбой пишется в журнал, а не бросается.

### 2026-11-17 — messenger: тест правок после склеенных

- `mercurius-messenger` `f0380d8`: тест — две правки одного сообщения, начатые вместе, склеиваются в один вызов, а
  следующие правки (поток и итог с разметкой) всё равно уходят. Поводом был ответ агента, не дошедший в Telegram после
  двух инструментов, запущенных разом; канал ошибку не воспроизвёл, в приложении добавлена запись в журнал о
  недоставленном итоге.

### 2026-11-16 — llm: инструменты OpenAI без рассуждения

- `mercurius-llm` `0907de0`: запрос OpenAI с инструментами — `reasoning_effort: none` (иначе 400 в Chat Completions,
  проверено ключом владельца); модели, которые рассуждают всегда, в каталоге OpenAI без инструментов.

### 2026-11-16 — llm: таймаут потока без событий

- `mercurius-llm` `d8d6d67`: поток без событий с данными дольше `firstEventTimeout` (240 с) или `eventTimeout`
  (120 с) — повторяемый `Timeout`; keep-alive-комментарии OpenRouter не продлевают вызов (фаза 5а).

### 2026-11-16 — llm: клиент и каталог OpenAI

- `mercurius-llm` `d0ae5b6`: исчерпанный баланс OpenAI (429 `insufficient_quota`) — `InsufficientCredits`, а не повтор;
  усилие `max` для OpenAI — `xhigh`; из каталога OpenAI исключены семейства только для `/v1/responses` (codex, `-pro`,
  поиск, звук, изображения) — по ревью.

- `mercurius-llm` `96f5145`: `OpenAI\OpenAIClient` на общем с OpenRouter коде протокола Chat Completions
  (`max_completion_tokens`, `reasoning_effort` строкой, без `temperature` при усилии рассуждения),
  `ModelCatalog\OpenAICatalog` — модели ключа, описанные открытым каталогом OpenRouter.
- Для Меркурия: второй провайдер моделей, любой из двух ключей достаточен
  ([BRIEF](../BRIEF.md#провайдеры-моделей-openrouter-и-openai)).

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
