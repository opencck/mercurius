# packages/ — индекс пакетов Меркурия

Компоненты Меркурия, вынесенные в отдельные репозитории `github.com/opencck/mercurius-<имя>`, подключены сюда
git-сабмодулями (ветка `master`). Правила — в [CLAUDE.md](../CLAUDE.md) (пункт «Пакеты») и
[BRIEF.md → Качество кода и CI](../BRIEF.md#качество-кода-и-ci).

## Подключённые пакеты

| Пакет | Каталог | Коммит | Проверено | Назначение |
|-------|---------|--------|-----------|------------|
| [mercurius-llm](https://github.com/opencck/mercurius-llm) | [llm/](llm/) | `0907de0` | 2026-11-16 | Единый интерфейс вызова LLM, адаптеры OpenRouter и OpenAI |
| [mercurius-connectors](https://github.com/opencck/mercurius-connectors) | [connectors/](connectors/) | `6920581` | 2026-11-16 | Маршруты исходящих соединений: напрямую, HTTP CONNECT, SOCKS5, VLESS |
| [mercurius-messenger](https://github.com/opencck/mercurius-messenger) | [messenger/](messenger/) | `efdba72` | 2026-11-18 | Канал связи: адаптеры Telegram и WebSocket |
| [mercurius-search](https://github.com/opencck/mercurius-search) | [search/](search/) | `47e3d0c` | 2026-11-17 | Поиск в интернете за одним интерфейсом: SearXNG, Brave, Tavily, OpenRouter, порядок и кеш |
| [mercurius-i18n](https://github.com/opencck/mercurius-i18n) | [i18n/](i18n/) | `253a20b` | 2026-11-18 | Тексты агента на русском и английском: `Translator`, каталоги, формы числа |

Остальные пакеты (`command-policy`, `memory`, `browser-service`, `mcp-client`, `peer`) пока живут в основном репозитории и выносятся, когда интерфейс устоится.

## Модули: сервисы, MCP, скиллы

Тяжёлое и необязательное — отдельные репозитории сабмодулями в `services/`, `mcp/`, `skills/`, сервис — в своём профиле
compose, по умолчанию выключен ([BRIEF.md → Модули](../BRIEF.md#модули-сервисы-mcp-и-скиллы)). Журнал модулей ведётся
здесь же.

| Модуль | Каталог | Коммит | Проверено | Профиль | Назначение |
|--------|---------|--------|-----------|---------|------------|
| [mercurius-media-video](https://github.com/opencck/mercurius-media-video) | [../services/media-video/](../services/media-video/) | `cc4c9a9` | 2026-11-17 | `media-video` | ffmpeg за HTTP API: кадры и звук видео, конвертация аудио |
| [mercurius-media-docs](https://github.com/opencck/mercurius-media-docs) | [../services/media-docs/](../services/media-docs/) | `479b35c` | 2026-11-18 | `media-docs` | Текст docx, pptx, xlsx, odt, PDF, RTF и сборка docx, xlsx, pptx по описанию (Python) |
| [mercurius-browser](https://github.com/opencck/mercurius-browser) | [../services/browser/](../services/browser/) | `429aa10` | 2026-11-18 | `browser` | Chromium под puppeteer-core за API на Fastify, проверка адресов |
| [mercurius-skill-creator](https://github.com/opencck/mercurius-skill-creator) | [../skills/skill-creator/](../skills/skill-creator/) | `f110a21` | 2026-11-18 | — | Встроенный скилл: создание и доработка скиллов агентом |
| [mercurius-telegram-bot-api](https://github.com/opencck/mercurius-telegram-bot-api) | [../services/telegram-bot-api/](../services/telegram-bot-api/) | `7e43a9f` | 2026-11-18 | `telegram-api` | Локальный сервер Bot API из исходников tdlib (файлы до 2000 МБ) |
| [mercurius-mail-mcp](https://github.com/opencck/mercurius-mail-mcp) | [../mcp/mail/](../mcp/mail/) | `1406472` | 2026-11-18 | `mail` | MCP-сервер почты со своим асинхронным IMAP и SMTP, проверка подлинности отправителя |

## Как работать с пакетом

- Код пакета правится в его каталоге и коммитится в его репозиторий (`master`), затем в основном репозитории
  коммитится новый указатель сабмодуля.
- В основном `composer.json` пакеты подключены Composer-репозиториями типа `path` с копированием (`symlink: false`).
  Версия у пакета всегда `dev-master`, поэтому `composer update` копию не обновляет: после правки пакета —
  `rm -rf vendor/opencck/mercurius-<имя> && composer install`.
- В Docker-образ пакеты попадают при сборке из каталога `packages/` (после `git submodule update --init`).
- У каждого пакета свой `composer check` и свой CI.

## Журнал

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
