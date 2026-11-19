# packages/ — индекс пакетов Меркурия

Компоненты Меркурия, вынесенные в отдельные репозитории `github.com/opencck/mercurius-<имя>`, подключены сюда
git-сабмодулями (ветка `master`). Правила — в [CLAUDE.md](../CLAUDE.md) (пункт «Пакеты») и
[BRIEF.md → Качество кода и CI](../BRIEF.md#качество-кода-и-ci).

## Подключённые пакеты

| Пакет | Каталог | Коммит | Проверено | Назначение |
|-------|---------|--------|-----------|------------|
| [mercurius-llm](https://github.com/opencck/mercurius-llm) | [llm/](llm/) | `e703b85` | 2026-11-19 | Единый интерфейс вызова LLM, адаптеры OpenRouter, OpenAI (Chat Completions и Responses), Anthropic и Bedrock |
| [mercurius-connectors](https://github.com/opencck/mercurius-connectors) | [connectors/](connectors/) | `6920581` | 2026-11-16 | Маршруты исходящих соединений: напрямую, HTTP CONNECT, SOCKS5, VLESS |
| [mercurius-messenger](https://github.com/opencck/mercurius-messenger) | [messenger/](messenger/) | `efdba72` | 2026-11-18 | Канал связи: адаптеры Telegram и WebSocket |
| [mercurius-search](https://github.com/opencck/mercurius-search) | [search/](search/) | `6160633` | 2026-11-19 | Поиск в интернете за одним интерфейсом: SearXNG, Brave, Tavily, OpenRouter, порядок и кеш |
| [mercurius-i18n](https://github.com/opencck/mercurius-i18n) | [i18n/](i18n/) | `04aab43` | 2026-11-19 | Тексты агента на русском и английском: `Translator`, каталоги, формы числа |

Остальные пакеты (`command-policy`, `memory`, `browser-service`, `mcp-client`, `peer`) пока живут в основном репозитории и выносятся, когда интерфейс устоится.

## Модули: сервисы, MCP, скиллы

Тяжёлое и необязательное — отдельные репозитории сабмодулями в `services/`, `mcp/`, `skills/`, сервис — в своём профиле
compose, по умолчанию выключен ([BRIEF.md → Модули](../BRIEF.md#модули-сервисы-mcp-и-скиллы)). Журнал модулей ведётся
здесь же.

| Модуль | Каталог | Коммит | Проверено | Профиль | Назначение |
|--------|---------|--------|-----------|---------|------------|
| [mercurius-media-video](https://github.com/opencck/mercurius-media-video) | [../services/media-video/](../services/media-video/) | `cc4c9a9` | 2026-11-17 | `media-video` | ffmpeg за HTTP API: кадры и звук видео, конвертация аудио |
| [mercurius-media-docs](https://github.com/opencck/mercurius-media-docs) | [../services/media-docs/](../services/media-docs/) | `479b35c` | 2026-11-18 | `media-docs` | Текст docx, pptx, xlsx, odt, PDF, RTF и сборка docx, xlsx, pptx по описанию (Python) |
| [mercurius-browser](https://github.com/opencck/mercurius-browser) | [../services/browser/](../services/browser/) | `d916e69` | 2026-11-19 | `browser` | Chromium под puppeteer-core за API на Fastify, проверка адресов |
| [mercurius-skill-creator](https://github.com/opencck/mercurius-skill-creator) | [../skills/skill-creator/](../skills/skill-creator/) | `f110a21` | 2026-11-18 | — | Встроенный скилл: создание и доработка скиллов агентом |
| [mercurius-telegram-bot-api](https://github.com/opencck/mercurius-telegram-bot-api) | [../services/telegram-bot-api/](../services/telegram-bot-api/) | `7e43a9f` | 2026-11-18 | `telegram-api` | Локальный сервер Bot API из исходников tdlib (файлы до 2000 МБ) |
| [mercurius-mail-mcp](https://github.com/opencck/mercurius-mail-mcp) | [../mcp/mail/](../mcp/mail/) | `10c83be` | 2026-11-19 | `mail` | MCP-сервер почты со своим асинхронным IMAP и SMTP, проверка подлинности отправителя |

## Как работать с пакетом

- Код пакета правится в его каталоге и коммитится в его репозиторий (`master`), затем в основном репозитории
  коммитится новый указатель сабмодуля.
- В основном `composer.json` пакеты подключены Composer-репозиториями типа `path` с копированием (`symlink: false`).
  Версия у пакета всегда `dev-master`, поэтому `composer update` копию не обновляет: после правки пакета —
  `rm -rf vendor/opencck/mercurius-<имя> && composer install`.
- В Docker-образ пакеты попадают при сборке из каталога `packages/` (после `git submodule update --init`).
- У каждого пакета свой `composer check` и свой CI.

## Журнал

### 2026-11-19 — i18n: поиск инструментов MCP

- `mercurius-i18n` `04aab43`: тексты мостов `tool_search`, `tool_describe`, `tool_call`; `self.tools` говорит о мосте.

### 2026-11-19 — i18n: обновление скилла

- `mercurius-i18n` `a64f11e`: тексты `/skill <имя> update`.

### 2026-11-19 — i18n: журнал правок скиллов

- `mercurius-i18n` `77775ce`: тексты `/skill <имя> history` и `rollback`.

### 2026-11-19 — i18n: выключение и удаление скилла

- `mercurius-i18n` `dd6ba20`: тексты `/skill <имя> off`, `on`, `remove`.

### 2026-11-19 — i18n: папки скиллов

- `mercurius-i18n` `00f5b86`: тексты называют `.mercurius/skills` вместо `skills/`.

### 2026-11-19 — mail-mcp и i18n: вложения писем

- `mercurius-mail-mcp` `10c83be`: `mail_attachment` (вложение встроенным ресурсом), `files` у `mail_send`
  (`multipart/mixed`, до 10 и 25 МБ), номера вложений и authserv-id в `mail_read`.
- `mercurius-i18n` `02450ed`: тексты файлов MCP-инструментов и строки почты в `doctor`.

### 2026-11-19 — i18n: окна длинной страницы

- `mercurius-i18n` `035ec9e`: `offset` у `web_read`, строки окна и продолжения.

### 2026-11-19 — search: расход поиска через модель

- `mercurius-search` `6160633`: `SearchUsage` (модель, токены, деньги) в `SearchResult::$usage`; `OpenRouterProvider`
  просит `usage`; ответ из `CachedSearch` расхода не несёт.

### 2026-11-19 — i18n: правило «действуй инструментами»

- `mercurius-i18n` `f2fda1d`: текст правила для моделей, склонных отвечать текстом, и описание настройки `AGENT_TOOL_USE_GUIDANCE`.

### 2026-11-19 — i18n: проверка себя перед отказом

- `mercurius-i18n` `bff0e50`: напоминание вызвать `self_info`, прежде чем сказать, что возможности нет.

### 2026-11-19 — browser и i18n: окна, вкладки, списки, прокрутка, назад

- `mercurius-browser` `e45c771`: окно страницы закрывается сразу, его текст приходит со следующим ответом; новая вкладка
  становится текущей; `POST /select`, `POST /scroll`, `POST /back`; у списка в снимке видны варианты.
- `mercurius-i18n` `758a1f5`: описание `select`, `scroll`, `back` и строка закрытых окон.

### 2026-11-19 — i18n: расход по запускам

- `mercurius-i18n` `33919a6`: `spending action=runs` и строки итога по запускам.

### 2026-11-19 — i18n: предел файлов скилла

- `mercurius-i18n` `75cb1f1`: настройка `SKILL_MAX_FILES`, отказ называет её; тексты о размере скилла убраны.

### 2026-11-19 — i18n: отчёт вместо молчания

- `mercurius-i18n` `f63a61e`: напоминание запуску, который выполнил действия и хотел промолчать.

### 2026-11-19 — i18n: цепочка журнала команд

- `mercurius-i18n` `3cf9f45`: строки `doctor` и ежедневной сводки о цепочке хешей журнала команд.

### 2026-11-19 — i18n: правка и удаление сервера

- `mercurius-i18n` `f61905b`: тексты `server_edit`, `server_remove` и `/server - <имя>`.

### 2026-11-19 — i18n: противоречие по отрицанию

- `mercurius-i18n` `f00ec89`: строка ночного отчёта о фактах, которые расходятся отрицанием.

### 2026-11-19 — i18n: выдача факта на карточке

- `mercurius-i18n` `3d01dcf`: строка карточки — сколько раз факт выдавался в ответах, помог и не помог.

### 2026-11-19 — i18n: происхождение и повторы на карточке памяти

- `mercurius-i18n` `6940d85`: строки карточки предложения памяти — происхождение факта и число дней, в которые человек
  это говорил.

### 2026-11-19 — i18n: смена модели роли и открытые беседы

- `mercurius-i18n` `962faa0`: описание `set_model_role` — новая модель действует и для открытых бесед роли со следующего
  запуска; ответ называет число переведённых бесед.

### 2026-11-19 — i18n: сбои окружения и слабые факты

- `mercurius-i18n` `bec3418`: промпт сборщика фактов без сбоев окружения, слабые факты в `self-review`, `faded` у `memory_search`.

### 2026-11-19 — i18n: страницы и поиск /memory

- `mercurius-i18n` `afee59f`: страница и поиск `/memory`, метки происхождения, метка «(забыто)».

### 2026-11-19 — i18n: происхождение и ключевые слова факта

- `mercurius-i18n` `51890fc`: поля `source` и `keywords` у `memory_propose`, их ошибки.

### 2026-11-19 — i18n: факты проекта

- `mercurius-i18n` `ad3556b`: поле `project` у `memory_propose` и отказ, когда проекта нет.

### 2026-11-19 — i18n: файл памяти MEMORY.md

- `mercurius-i18n` `b097325`: блок `MEMORY.md` в промпте, слово о сжатии, настройка `MEMORY_FILE_LIMIT`.

### 2026-11-19 — i18n: правки слоя 1 по записям

- `mercurius-i18n` `d112293`: ошибки правки записи, поля `edit` и `old_text` у `memory_propose`.

### 2026-11-19 — i18n: очередь предложений памяти в сводке

- `mercurius-i18n` `8a59c35`: строка «Память: ждут решения N, старейшему D дн.» ежедневной сводки.

### 2026-11-19 — i18n: аргументы /runs и /trace

- `mercurius-i18n` `0a5344d`: `/runs [число] [статус]`, `/trace <номер> [шаг]` в приветствии и подвале трассы; текст шага без промпта.

### 2026-11-19 — i18n: инструменты и долгие шаги в отчётах

- `mercurius-i18n` `c1e8926`: разделы инструментов и самых долгих шагов в `/usage`, доля оркестратора в `/delegation`.

### 2026-11-19 — i18n: отчёты по версии промпта

- `mercurius-i18n` `ea7339c`: раздел «По версии системного промпта» в `/usage` и `/delegation`.

### 2026-11-19 — i18n: ориентир числа частей

- `mercurius-i18n` `7b5aedd`: в описании `delegate_task` — одна часть не делегируется, 2–4 — сравнения, 5 — потолок.

### 2026-11-19 — i18n: схема ответа воркера

- `mercurius-i18n` `493a581`: поле `output_schema`, контракт ответа, ход на исправление, строка о схеме в отчёте.

### 2026-11-19 — i18n: права воркера

- `mercurius-i18n` `fb76f3b`: поля `mode`, `memory`, `context_of` у `delegate_task` и строка «ТВОИ ПРАВА» воркера.

### 2026-11-19 — llm: картинки от моделей изображений

- `mercurius-llm` `e703b85`: `OpenRouter\ImageClient` — `POST /images`, картинки base64 в ответе; `ImageRequest`,
  `ImageResult`.
- `mercurius-i18n` `e18a6c5`: тексты инструмента `image_generate` и настроек `MEDIA_IMAGE_*`.

### 2026-11-19 — llm: OpenAI Responses API

- `mercurius-llm` `757ae67`: `OpenAI\ResponsesClient` — Responses API (`store: false`, `instructions`, элементы
  `function_call`/`function_call_output`, `reasoning.effort`); `OpenAICatalog` с `responses: true` — семейства codex
  и `-pro`, инструменты у моделей, которые рассуждают всегда; `EventStream` — общий разбор потока с таймером
  простоя для клиентов Anthropic и Responses.

### 2026-11-19 — llm: Claude в Amazon Bedrock

- `mercurius-llm` `5de2415`: `AnthropicClient::bedrock()` — Messages API по адресу bedrock-mantle региона с ключом
  Bedrock API; `BedrockCatalog` — модели Claude из каталога OpenRouter в написании Bedrock.
- `mercurius-i18n` `75cc174`: тексты ключа и региона Bedrock для настроек и `doctor`.

### 2026-11-19 — llm: провайдер Anthropic

- `mercurius-llm` `7a16bdf`: `Anthropic\AnthropicClient` — Messages API на HTTP-клиенте AMPHP (`x-api-key`,
  `anthropic-version: 2026-10-21`, усилие в `output_config.effort`, без температуры, слияние реплик одной роли,
  картинки base64, разбор потока); `HttpTransport` принимает дополнительные заголовки.
- `mercurius-llm` `7d1af68`: `ModelCatalog\AnthropicCatalog` — модели ключа, описанные каталогом OpenRouter (версии
  через точку, идентификатор с датой — без даты).
- `mercurius-i18n` `792cd92`: тексты `settings.about.anthropic_api_key`, `service.doctor.klyuch_anthropic_zadan`, проверка
  ключей в `doctor` называет `ANTHROPIC_API_KEY`.

### 2026-11-19 — llm: изображения и видео в сообщении

- `mercurius-llm` `832c2ef`: `MediaPart` (изображение или видео) в `Message::user`; сообщение уходит частями — текст и
  `image_url`/`video_url` с data: URL. Приложение шлёт через это картинки и видео моделям зрения.

### 2026-11-19 — llm: модели, хорошо вызывающие инструменты

- `mercurius-llm` `466179d`: `OpenRouterCatalog::toolReliable` — модели каталога с долей вызовов инструментов,
  прошедших схему, не ниже заданной (`min_tool_success_rate`).

### 2026-11-19 — i18n: почему выбрана модель

- `mercurius-i18n` `964de3b`: строка `/trace` о каждом решении о модели запуска.

### 2026-11-19 — i18n: команды ролей в приветствии

- `mercurius-i18n` `134f750`: `/roles` и `/role` в списке команд владельца.

### 2026-11-19 — i18n: выдача своих ролей

- `mercurius-i18n` `6d47571`: роль, выданная на карточке посетителя; отказ выдать роль выше своей; роль агента — из списка.

### 2026-11-19 — i18n: роли участников

- `mercurius-i18n` `bee7529`: тексты `/roles` и `/role add|set|remove` — список, справка, ответы.

### 2026-11-19 — i18n: передача владения

- `mercurius-i18n` `d13628e`: тексты `/owner` — карточка, кнопки, ответы, уведомление новому владельцу; `/owner` в
  приветствии среди команд владельца.

### 2026-11-19 — i18n: итог задания

- `mercurius-i18n` `c09889d`: запуск задания знает, что итог сам придёт туда, где задание поставлено (чат или тема), и
  не отправляет его отдельно.

### 2026-11-19 — llm: метка кеша на сообщении

- `mercurius-llm` `14136c6`: `Message::cached()` — точка кеша в конце сообщения; системное или пользовательское
  сообщение с меткой уходит одной текстовой частью с `cache_control`. Приложение ставит её на системный промпт Claude
  через OpenRouter, кроме разовых вызовов.

### 2026-11-19 — i18n: повтор результата

- `mercurius-i18n` `a8de667`: ссылка на вызов, чей результат повторился.

### 2026-11-19 — i18n: поиск по файлам

- `mercurius-i18n` `ed2e6d4`: описание инструмента `search_files`.

### 2026-11-18 — i18n: самоизменение навсегда

- `mercurius-i18n` `a8ea53c`: кнопка «Разрешать всегда» у улучшений, уведомление о принятом по разрешению, настройка `IMPROVE_GATE`.

### 2026-11-18 — i18n: агент сам записывает новые факты

- `mercurius-i18n` `fc8253b`: уведомление о записанном факте с кнопкой «Забыть», описание `memory_propose`.

### 2026-11-18 — i18n: решение получено, разрешений нет

- `mercurius-i18n` `992e8f4`: статус после решения владельца и пустой список разрешений.

### 2026-11-18 — i18n: правила «никогда»

- `mercurius-i18n` `6bc9efc`: правила удаления домашнего каталога и `kill -1`, настройка `SSH_NEVER_OFF`.

### 2026-11-18 — i18n: подтверждения браузера и потерянная карточка

- `mercurius-i18n` `159be82`: настройка `BROWSER_CONFIRM`; отказ «карточка не дошла» велит не повторять действие.

### 2026-11-18 — i18n: сведения о другом человеке

- `mercurius-i18n` `085e27e`: флаг `about_person` у `memory_propose`, пометка на карточке, отказ вне памяти владельца.

### 2026-11-18 — i18n: белый список воркера

- `mercurius-i18n` `4262bb3`: описание настройки `AGENT_WORKER_TOOLS`.

### 2026-11-18 — media-video: только файлы

- `mercurius-media-video` `7be2f90`: `ffmpeg` и `ffprobe` читают вход с `-protocol_whitelist file` — плейлист внутри
  файла не уведёт их в сеть.

### 2026-11-18 — browser: соединение на проверенный адрес

- `mercurius-browser` `81a1866`: локальный прокси на профиль — имя резолвится один раз, соединение идёт на
  проверенный адрес; вкладки, открытые страницей, проверяются так же, как первая.

### 2026-11-18 — i18n: команда песочницы через stdin

- `mercurius-i18n` `d633030`: обёртка песочницы читает команду со stdin до NUL; отказ для команды с нулевым байтом.

### 2026-11-18 — browser и i18n: профили по участникам

- `mercurius-browser` `c5e506f`: профиль — только `p<участник>/<имя>` или `p<участник>/projects/<проект>/<имя>`,
  `POST /adopt` переносит профили плоской раскладки в набор владельца.
- `mercurius-i18n` `4354efe`: описание профиля у инструмента `browser`.

### 2026-11-18 — i18n: доверие источнику задания

- `mercurius-i18n` `25b7b7f`: действия `trust`/`distrust` у `job_manage` и их ответы.

### 2026-11-18 — i18n: чтение git без программ репозитория

- `mercurius-i18n` `80a790b`: текст отказа читающей команды git, когда в конфиге репозитория есть фильтры или программы diff.

### 2026-11-18 — i18n: токен форм в http_request

- `mercurius-i18n` `3efa6b5`: описание `http_request` — токен `csrf-token` страницы код отправляет сам, длинные значения
  копируются целиком.

### 2026-11-18 — browser: ввод только в поля

- `mercurius-browser` `8a9f8d8`: `/type` пишет только в текстовые поля (кнопки и ссылки — отказ), фокус без щелчка,
  текст вставляется как текст — перевод строки не отправляет форму; отправка только по `submit`.

### 2026-11-18 — i18n: секреты в теле запроса и cookie

- `mercurius-i18n` `eac343d`: описания `http_request` о метках `{{secret:имя}}` и хранимых cookie.

### 2026-11-18 — i18n: карточка соседа без доказательства ключа

- `mercurius-i18n` `1ae7930`: текст отказа добавить соседа, чья карточка не доказывает ключ подписью.

### 2026-11-18 — i18n: права только в личном чате

- `mercurius-i18n` `ef6dc63`: текст отказа выдавать права участникам вне личного чата владельца.

### 2026-11-18 — i18n: грамматика читающих команд

- `mercurius-i18n` `be0e0b7`: текст классификатора команд об аргументе вне грамматики чтения.

### 2026-11-18 — i18n: команды клиентов SQL и секреты git

- `mercurius-i18n` `b199e33`: тексты отказа `sql_query` для команд клиентов psql и mysql и незакрытой строки, текст
  `git` о командах, которым даётся секрет.

### 2026-11-18 — browser: внутренние адреса IPv4 в записи IPv6

- `mercurius-browser` `2bf1a94`: страж адресов пропускал `http://[::ffff:169.254.169.254]/`, `[::ffff:127.0.0.1]`,
  NAT64 и `fec0::` — разбор URL переписывает адрес в шестнадцатеричную запись, а проверка ждала запись с точками
  (найдено сверкой роадмапа). Теперь адрес разбирается в 16 байт и сверяется с сетями, как `AddressGuard` приложения.

### 2026-11-18 — i18n: ключи агентов в списке участников

- `mercurius-i18n` `253a20b`: строка о возвращённом ключе (`peer.agent_requests.restored`) и отметки ключа агента в
  инструменте `participants`.

### 2026-11-18 — i18n: оценка фактов и фоновое предложение фактов

- `mercurius-i18n` `3e4a3ff`: тексты инструмента `memory_feedback`, промпт дешёвой модели фонового разбора беседы
  (`agent.fact_nudge.*`) и описание настройки `MEMORY_NUDGE_TURNS`.

### 2026-11-18 — i18n: итог по цепочке агентов

- `mercurius-i18n` `2c82e95`: текст `peer.a2a_server.chain_answered` — запуск, который сводит ответы соседей и даёт итог
  задачи агенту, который её поставил.

### 2026-11-18 — messenger: файлы локального Bot API

- `mercurius-messenger` `efdba72`: путь файла локального сервера содержит каталог бота `<id>:<токен>/`; двоеточие не
  проходило проверку пути, и голосовые, фото и документы не читались. Теперь двоеточие допускается только в абсолютном
  пути локального сервера.

### 2026-11-18 — i18n: /proposals

- `mercurius-i18n` `7904109`: текст «нерешённых предложений нет».

### 2026-11-18 — скиллы как папки

- `mercurius-skill-creator` `f110a21`: сохранение через `skill_save` в папку участника или проекта.
- `mercurius-i18n` `2f50ba1`: тексты индекса скиллов по местам, `skill_view`, `skill_save`, `skill_import`, тема `skills`.

### 2026-11-18 — i18n: расход соседа в трассе

- `mercurius-i18n` `eb71178`: строка трассы с запуском и расходом соседа; тексты архивов скиллов.

### 2026-11-18 — встроенный скилл skill-creator

- Новый репозиторий `mercurius-skill-creator` (`00447ca`), сабмодуль `skills/skill-creator`: как агент создаёт и
  дорабатывает скиллы (замысел, расспрос, SKILL.md с файлами, проверка со скиллом и без через `delegate_task`,
  описание, `skill_propose`). Загружается при старте `BuiltinSkills`.
- `mercurius-i18n` `7115600`: тексты файлов предлагаемого скилла.

### 2026-11-18 — i18n: использование скиллов

- `mercurius-i18n` `d8e7292`: открытия, «давно не открывался», закрепление скилла.

### 2026-11-18 — i18n: знания агента о себе

- `mercurius-i18n` `aed8d4d`: указатель на `self_info` в промпте, темы и их тексты.

### 2026-11-18 — i18n: пояс и тихие часы участника

- `mercurius-i18n` `d209e96`: тексты инструмента `my_preferences`.

### 2026-11-18 — i18n: самоулучшение и сеть агентов в промпте

- `mercurius-i18n` `98ffa41`: предложения, ворота набора задач, `/prompt`, `trace_review`, задание `self-review`,
  блок «как подключить ко мне другого агента».

### 2026-11-18 — i18n: потолок расхода участника

- `mercurius-i18n` `bea91bb`: тексты потолка расхода участника.

### 2026-11-18 — messenger и i18n: WebSocket API

- `mercurius-messenger` `ea23faf`: канал `Websocket\WebsocketChannel` (JSON-кадры, личный чат клиента, очередь без
  клиента, файлы в обе стороны, нажатия) и `RoutingChannel` (канал по приставке чата). Ядро держит один `Channel`.
- `mercurius-i18n` `5335568`, `40c545c`: тексты `/apikey`, канал WebSocket API в промпте.

### 2026-11-18 — i18n: инициатива, сводная карточка, скилл проекта

- `mercurius-i18n` `44a7efb`: промпт задания инициативы и его настройки, сводная карточка незнакомцев группы,
  привязка скилла к проекту.

### 2026-11-18 — i18n: инструмент git

- `mercurius-i18n` `abfb7d9`: тексты инструмента `git` — описание, параметры, отказы.

### 2026-11-18 — i18n: doctor и модули

- `mercurius-i18n` `7fdf0ff`: подсказка о профиле compose у модуля, который не отвечает.

### 2026-11-18 — i18n: снимки рабочего каталога

- `mercurius-i18n` `e6d6888`: инструмент `checkpoint`.

### 2026-11-18 — i18n: наблюдение в заданиях

- `mercurius-i18n` `d868f58`: параметр `watch` у `job_create`, тексты наблюдаемой страницы.

### 2026-11-18 — telegram-bot-api: сторож

- `mercurius-telegram-bot-api` `7e43a9f`: сторож в контейнере — три пропущенных ответа подряд, и сервер перезапускается
  (2026-11-18 сервер завис и перестал доставлять вебхуки до ручного перезапуска).

### 2026-11-18 — i18n: файлы в peer_delegate

- `mercurius-i18n` `a408371`: файлы к задаче соседу, ошибка задачи без `peer`.

### 2026-11-18 — i18n: файлы между агентами

- `mercurius-i18n` `1e7f422`: файл в ответ на задачу агента, ошибки загрузки и скачивания файлов соседа.

### 2026-11-18 — mail-mcp стал модулем

- `mercurius-mail-mcp` `1406472`: свой Dockerfile; сабмодуль переехал из `packages/mail-mcp` в `mcp/mail`, сервис — в
  профиле `mail`, приложение больше не ставит его через Composer.

### 2026-11-18 — модули browser и telegram-bot-api, i18n: скриншот глазами агента

- `mercurius-browser` `429aa10`: каталог `browser/` вынесен с историей (`git subtree split`), README, LICENSE, CI;
  сервис теперь в профиле `browser`.
- `mercurius-telegram-bot-api` `ecc9627`: каталог `docker/telegram-bot-api/` вынесен с историей, README, LICENSE, CI.
- `mercurius-i18n` `4143896`: аргументы `question` и `send` у `browser screenshot`.

### 2026-11-18 — модуль media-docs, i18n: инструмент documents

- `mercurius-media-docs` `479b35c` (новый, `services/media-docs`, профиль `media-docs`): `POST /extract`, `POST /build`,
  `GET /health`, токен `MEDIA_DOCS_TOKEN`. Библиотеки документов и `extract_text.py` убраны из песочницы.
- `mercurius-i18n` `0eb2d3c`: тексты инструмента `documents`, `media.intake.no_docs_module`, `run_command` больше не обещает
  python-pptx в песочнице.

### 2026-11-17 — модуль media-video, i18n: как включить модуль

- `mercurius-media-video` `cc4c9a9` (новый, `services/media-video`, профиль `media-video`): `POST /frames`, `POST /audio`,
  `GET /health`, токен `MEDIA_VIDEO_TOKEN`. ffmpeg и `video_frames.sh` убраны из песочницы.
- `mercurius-i18n` `2f8986c`: `tool.media.no_video_module` — что сказать владельцу, если модуля нет.

### 2026-11-17 — i18n: инструмент media

- `mercurius-i18n` `a83d8c0`: описание и ответы инструмента `media`.

### 2026-11-17 — i18n: обработка вложений моделями

- `mercurius-i18n` `53b3b2b`: описание фото, речь, принципы выбора модели, режимы участника.

### 2026-11-17 — i18n: ошибки медиамоделей

- `mercurius-i18n` `26a1fab`: нет ключа, нет адреса, пустой ответ.

### 2026-11-17 — i18n: принципы выбора медиамоделей

- `mercurius-i18n` `73d78ce`: настройки `MEDIA_*_RULES`, инструмент `media_prefs`.

### 2026-11-17 — i18n: заметка системы о действиях

- `mercurius-i18n` `2912123`: заметка о сделанном — от системы; напоминание, если действие написано текстом.

### 2026-11-17 — messenger и i18n: файлы сообщения, на которое ответ

- `mercurius-messenger` `dbbb7af`: `IncomingMessage::$repliedAttachments`.
- `mercurius-i18n` `b3e3ac1`: пометка «из сообщения, на которое этот ответ».

### 2026-11-17 — messenger и i18n: локальный сервер Bot API

- `mercurius-messenger` `22f88a9`: файлы локального сервера (`--local`) читаются с его диска замыканием `localFile`.
- `mercurius-i18n` `80edb3a`: предел файла бота называет локальный сервер Bot API.

### 2026-11-17 — i18n: приём вложений

- `mercurius-i18n` `41a3fe2`: виды файлов, пометка «модель не назначена» с подсказкой, почему файл не скачан или не прочитан.

### 2026-11-17 — i18n: настройки вложений

- `mercurius-i18n` `c25e8ef`: группа «вложения» и описания восьми настроек `MEDIA_*`.

### 2026-11-17 — messenger: вложения входящих

- `mercurius-messenger` `ea24da8`: `IncomingMessage::$attachments` (голос, аудио, кружок, фото, видео, анимация, документ) и `Channel::download(fileId, maxBytes)`.

### 2026-11-17 — i18n: сосед на моём подключении

- `mercurius-i18n` `2739b4b`: карточка «Сосед X, к которому я подключён, хочет ставить мне задачи».

### 2026-11-17 — i18n: участник просит инструмент

- `mercurius-i18n` `375652d`: `request_tool` — описание, карточка, ответы; задача соседа говорит, как пришла.

### 2026-11-17 — i18n: карточка допуска агента

- `mercurius-i18n` `c810057`: «Ко мне подключается агент…» — направление в первой строке; заметки для беседы владельца о карточке и решении.

### 2026-11-17 — i18n: скрипты браузера

- `mercurius-i18n` `3ea139f`, `51a0977`: действие `script` инструмента `browser` — описание, ошибки пути и сайта, результат.

### 2026-11-17 — i18n: незнакомый агент стучится

- `mercurius-i18n` `1097a50`: карточка владельцу о незнакомом агенте — транспорт, имя, адрес, отпечаток; кнопки ролей и отказа.

### 2026-11-17 — i18n: длинные скрипты в песочнице

- `mercurius-i18n` `bd4f3ef`: подсказка `run_command` про библиотеки документов и скрипты через файл.

### 2026-11-17 — i18n: всё, что умеет мессенджер

- `mercurius-i18n` `80fa21d`: `send_file` по виду и адресату, инструмент `send_content`.

### 2026-11-17 — messenger: фото, аудио, видео, геопозиция, контакт, опрос

- `mercurius-messenger` `6d17da7`: `sendMedia` по виду (`MediaKind`), `sendLocation`, `sendContact`, `sendPoll`.
  Нужно инструментам `send_file` и `send_content`.

### 2026-11-17 — i18n: файлы людям и агентам

- `mercurius-i18n` `eac2676`: файлы в `send_message`, сообщения агентам, вложения задачи, снимок браузера.

### 2026-11-17 — i18n: файлы в чат

- `mercurius-i18n` `0fc3773`: тексты инструмента `send_file`.

### 2026-11-17 — messenger: документ в тему форума

- `mercurius-messenger` `a3a9b3d`: `Channel::sendFile` принимает тему форума, Telegram шлёт документ с `message_thread_id`.
  Нужно инструменту `send_file`: файл из песочницы приходит в ту тему, где его просили.

### 2026-11-17 — i18n: подключения агентов

- `mercurius-i18n` `ca3b289`: инструмент `peer_links`, команды `/peer open|close`, подключения в `/peers`.

### 2026-11-17 — i18n: ключи агента и WebSocket

- `mercurius-i18n` `0f39cc5`: инструмент `peer_keys`, команда `/peer key`, связь и ключ соседа в `/peers`.

### 2026-11-17 — i18n: разбивка расхода

- `mercurius-i18n` `48e1255`: тексты разбивки расхода по темам, проектам, участникам, моделям и дням.

### 2026-11-17 — i18n: потоковый API и stdio-серверы MCP

- `mercurius-i18n` `25c4849`: отказы потокового API исполнителя, ошибки stdio-процесса MCP, справка `/mcp stdio`.

### 2026-11-17 — i18n: ресурсы MCP

- `mercurius-i18n` `fde2382`: тексты инструмента `mcp_resources`.

### 2026-11-17 — i18n: заметка о сделанном и сообщения без текста

- `mercurius-i18n` `1f36ce8`: строка «Сделано в этом запуске», ожидание ответа по умолчанию в `send_message`, пометки
  стикеров, фото, голосовых и других сообщений без текста.

### 2026-11-17 — i18n: личные сообщения участникам

- `mercurius-i18n` `a0b3349`: тексты инструмента `send_message` и возврата ответа тому, кто просил.

### 2026-11-17 — i18n: решения владельца об опасных действиях

- `mercurius-i18n` `6c22e33`: предупреждение «ОПАСНО» и пометка внутреннего адреса на карточке, заметка о прочитанных
  внешних данных, новые описания инструментов и правило системного промпта: владелец может разрешить любое действие.

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
