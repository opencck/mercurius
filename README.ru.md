# Меркурий

[English](README.md) | **Русский** | [中文](README.cn.md)

Меркурий — автономный персональный агент. Его ставят на свой сервер через Docker Compose и общаются с ним через Telegram-бота. Он помогает с любой работой: поиск в интернете, работа с серверами по SSH, написание кода и ведение программных проектов, планирование, исследования и тестирование.

**Статус:** работает на сервере владельца и на четырёх тестовых экземплярах. Разработка и доработки после сверки и аудита безопасности закрыты; итоги, решения и то, что ждёт владельца, — в [`brief/history.md`](brief/history.md).

## Что уже работает

- **Telegram:** webhook или long polling, ответ потоком, Markdown, разбиение длинных сообщений, файлы и кнопки. Агент видит номера сообщений, на что отвечает сообщение и какой запуск написал его собственные сообщения.
- **Агентский цикл:** инструменты, лимиты ходов, токенов и времени в коде, запуски переживают перезапуск, сжатие контекста, роли моделей по запросу (OpenRouter и OpenAI API).
- **Подтверждения:** необратимые действия (`git push`, `ssh`, выгрузка, HTTP кроме GET, пост в канал, запрос с секретом) ждут карточки владельца; «разрешать всегда» сохраняет разрешение на это действие и цель.
- **Участники:** незнакомцы ждут решения владельца (в том числе замеченные в группе); у каждого участника свои инструменты, лимиты, проекты и память.
- **Память:** слои фактов с источниками, поиск по смыслу, `/memory`, `/forget`, ночная проверка.
- **Серверы:** хранилище секретов, реестр серверов, SSH с политикой команд и журналом, маршруты через VLESS.
- **Интернет:** поиск через SearXNG, Brave, Tavily или модель с веб-поиском; чтение страниц и документов; браузер в своём контейнере.
- **Скиллы и MCP:** каталог скиллов с версиями и одобрением владельца; обычные скиллы `SKILL.md` других агентов из GitHub или архива вместе с файлами; MCP-серверы по HTTP с фильтром инструментов и предохранителем; встроенная почта (IMAP/SMTP) как MCP-сервер.
- **Планировщик и проекты:** задания по расписанию или по подписанному webhook, тихие часы; проекты с карточкой, папкой, скиллами и заданиями.
- **Группы:** работа в одобренных владельцем группах (по упоминанию или свободно); форум, где тема — проект; посты в канал через карточку владельца.
- **Делегирование:** воркеры с чистым контекстом и своими правами (чтение или запись), модель под часть, проверяющий для дорогих частей, фоновая работа.
- **Сеть агентов:** экземпляры Меркурия дают друг другу задачи по A2A и узнают друг друга по ключам: вызов с ожиданием ответа, части нескольким соседям со сборкой, отмена, сроки, расход по цепочке.
- **WebSocket API:** второй канал: `GET /api/ws` с `Authorization: Bearer <ключ>`; ключи участникам выдаёт владелец командой `/apikey new <участник>`; JSON-кадры сообщений, кнопок и файлов.
- **Самоулучшение:** агент разбирает свои трассы, предлагает заметки к промпту и смену ролей моделей; изменение проходит набор проверочных задач и карточку владельца и откатывается (`/prompt`, `/models`, `/proposals`).
- **Экспорт и импорт:** `/export <пароль>` собирает агента целиком (база, данные, рабочие каталоги, настройки) в архив, запечатанный паролем; `/import` заменяет агента архивом или берёт из него только память или только скиллы.
- **Обслуживание:** настройки в диалоге (`/settings`, `/set`, `/restart`), язык агента (`AGENT_LANGUAGE`, по умолчанию русский), трассы и расход, метрики Prometheus, резервная копия и восстановление (`bin/backup.sh`, `bin/restore.sh`), обновление одной командой (`bin/update.sh`), проверка установки (`/doctor`), набор проверочных задач.

## Планы

MCP-серверы по stdio в песочнице. Открытые вопросы — в [`brief/decisions.md`](brief/decisions.md).

## Запуск

Нужен сервер с Docker и Docker Compose v2, около 4 ГБ памяти и 10 ГБ диска для ядра (модули — сверх этого), бот
Telegram от [@BotFather](https://t.me/BotFather) и ключ OpenRouter или OpenAI API.

1. Клонировать код и сабмодули пакетов (референсы для запуска не нужны):
   ```sh
   git clone https://github.com/opencck/mercurius.git && cd mercurius
   git submodule update --init --depth 1 packages/llm packages/connectors packages/messenger packages/search packages/i18n packages/mcp-client packages/memory packages/peer skills/skill-creator skills/memory-file
   ```
2. Скопировать `.env.example` в `.env` (права `600`) и заполнить: как минимум `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_ID`
   (ваш id в Telegram), `OPENROUTER_API_KEY` или `OPENAI_API_KEY` (хватит одного), `POSTGRES_PASSWORD`, `SANDBOX_TOKEN`
   (`openssl rand -hex 24`). Каждая переменная описана в файле; большинство настроек потом меняются в диалоге. Без
   `APP_URL` бот работает через long polling и публичный адрес не нужен; с ним — через webhook за вашим прокси.
3. `chmod 1777 storage/app storage/browser` — контейнеры пишут туда от своих пользователей. Затем
   `docker compose up -d --build`. Миграции базы применяются при старте; `docker compose ps` показывает `app`,
   `postgres` и `sandbox` в состоянии `healthy`.
4. Написать боту. `/start` показывает команды; `/doctor` проверяет установку (ключи, миграции, песочницу, модули,
   webhook, диск) и говорит, что исправить.

### Модули

Тяжёлое и необязательное — модули: у каждого свой репозиторий, здесь он сабмодуль, сервис — в своём профиле compose,
по умолчанию выключен. Включается сабмодулем, профилем в `COMPOSE_PROFILES` и токеном в `.env`; инструмент у агента
появляется, только пока сервис отвечает.

| Модуль | Сабмодуль | Профиль | `.env` | Что получает агент |
|---|---|---|---|---|
| [`mercurius-browser`](https://github.com/opencck/mercurius-browser) | `services/browser` | `browser` | `BROWSER_TOKEN` | Chromium: страницы, нажатия, скрипты, скриншоты |
| [`mercurius-media-video`](https://github.com/opencck/mercurius-media-video) | `services/media-video` | `media-video` | `MEDIA_VIDEO_TOKEN` | кадры и звук видео для моделей, которые видео не смотрят |
| [`mercurius-media-docs`](https://github.com/opencck/mercurius-media-docs) | `services/media-docs` | `media-docs` | `MEDIA_DOCS_TOKEN` | текст офисных документов и PDF; новые docx, xlsx, pptx |
| [`mercurius-skill-memory-file`](https://github.com/opencck/mercurius-skill-memory-file) | `skills/memory-file` | — | — | встроенный скилл: как агент держит `MEMORY.md` участника в пределе |
| [`mercurius-skill-creator`](https://github.com/opencck/mercurius-skill-creator) | `skills/skill-creator` | — | — | встроенный скилл: как агент создаёт и дорабатывает свои скиллы (грузится при старте, без сервиса) |
| [`mercurius-mail-mcp`](https://github.com/opencck/mercurius-mail-mcp) | `mcp/mail` | `mail` | `MAIL_MCP_TOKEN` | почтовые ящики участников по IMAP и SMTP |
| [`mercurius-telegram-bot-api`](https://github.com/opencck/mercurius-telegram-bot-api) | `services/telegram-bot-api` | `telegram-api` | `TELEGRAM_API_ID`, `TELEGRAM_API_HASH`, `TELEGRAM_API_URL` | файлы до 2000 МБ в Telegram |

Например, браузер и документы:

```sh
git submodule update --init --depth 1 services/browser services/media-docs
# .env: COMPOSE_PROFILES=browser,media-docs, BROWSER_TOKEN и MEDIA_DOCS_TOKEN (openssl rand -hex 24)
docker compose up -d --build
```

### Обновление

```sh
bin/update.sh    # сначала копия (неудачная останавливает обновление), git pull, сабмодули модулей, сборка и запуск
```

То же вручную:

```sh
bin/backup.sh                       # база, данные и .env; последние 7 копий — в backups/
git pull --ff-only && git submodule update --init --depth 1 <ваши сабмодули>
docker compose up -d --build        # новые миграции применяются при старте; они только добавляют схему
```

Новые переменные появляются в `.env.example`; `/doctor` называет те, которых нет в вашем `.env`, и сохранённые в
диалоге настройки, которых нет в этой версии. `bin/restore.sh backups/<время>` возвращает копию.

### Несколько экземпляров на одном хосте

Каждый экземпляр — свой клон в своём каталоге со своим `.env`: `COMPOSE_PROJECT_NAME`, токен бота и ключи,
`WEB_ALIAS` (его имя в сети прокси `web`) и `SANDBOX_SUBNET` (свободная `/24` для сети песочницы, например
`172.31.252.0/24`). Образы общие: сборка в любом каталоге обновляет все.

Когда у Docker на хосте кончаются пулы адресов для новых сетей, подсеть сети `browser` задаётся в локальном файле
рядом с `docker-compose.yml`, а он — в `COMPOSE_FILE`:

```yaml
# compose.local.yml — не в git
networks:
  browser:
    ipam:
      config:
        - subnet: 172.31.248.0/24
```

```sh
# .env
COMPOSE_FILE=docker-compose.yml:compose.local.yml
```

Каталог данных `storage/app` — точка монтирования, приложение пишет в него от своего пользователя (uid 10001): права
`1777`, как у `storage/browser`; `/doctor` это проверяет.

## Стек

PHP 8.2+, AMPHP v3 / Revolt, [`opencck/server`](https://github.com/opencck), PostgreSQL с pgvector; модели через OpenRouter и OpenAI API. В каждом репозитории — PHPUnit, PHPStan и Psalm (`composer check`).

## Репозитории

Компоненты, полезные вне Меркурия, живут в своих репозиториях и подключены сабмодулями в `packages/`:

| Репозиторий | Назначение |
|-------------|------------|
| [`mercurius-llm`](https://github.com/opencck/mercurius-llm) | Единый интерфейс вызова больших языковых моделей: сообщения, инструменты, стриминг, учёт токенов и стоимости, ошибки и повторы. |
| [`mercurius-connectors`](https://github.com/opencck/mercurius-connectors) | Маршруты исходящих соединений как фабрика Amp `SocketConnector`: напрямую, HTTP CONNECT, SOCKS5, VLESS (через `amphp/vless`) и их цепочки. |
| [`mercurius-messenger`](https://github.com/opencck/mercurius-messenger) | Единый интерфейс канала связи с нормализованной моделью сообщения и адаптер Telegram Bot API (webhook и long polling, группы, темы форума, посты канала). |
| [`mercurius-search`](https://github.com/opencck/mercurius-search) | Поиск в интернете за одним интерфейсом: SearXNG, Brave, Tavily и модель с веб-поиском, с порядком и кешем. |
| [`mercurius-i18n`](https://github.com/opencck/mercurius-i18n) | Тексты агента на русском и английском: переводчик, каталоги, формы числа и проверка совпадения. |
| [`mercurius-mcp-client`](https://github.com/opencck/mercurius-mcp-client) | Клиент MCP: JSON-RPC по Streamable HTTP, инструменты и ресурсы постранично, файлы в результате вызова. |
| [`mercurius-memory`](https://github.com/opencck/mercurius-memory) | Память агента: факты с версиями и происхождением, вспоминание по ключам и векторам HRR, предложения на решение владельца, ядро, ночная проверка; хранилища PostgreSQL и в памяти со своими миграциями. |
| [`mercurius-peer`](https://github.com/opencck/mercurius-peer) | Связь агентов по A2A: ключи P-256 и подписи, клиент (карточка с доказательством ключа, задачи, отмена, файлы), WebSocket с рукопожатием ключей. |

Модули — браузер, медиа, почта и свой сервер Bot API — перечислены в разделе [Модули](#модули). Репозиторий [`mercurius-command-policy`](https://github.com/opencck/mercurius-command-policy) заведён, но код пока в основном репозитории (`src/CommandPolicy/`).

## Устройство репозитория

| Путь | Содержимое |
|------|------------|
| [`BRIEF.md`](BRIEF.md) | Спецификация: требования, устройство, решения |
| [`CLAUDE.md`](CLAUDE.md) | Правила работы над проектом с Claude Code |
| `bootstrap/index.php`, `src/`, `migrations/`, `test/` | Агент: точка входа, код по слоям opencck, миграции базы, тесты |
| `bootstrap/sandbox.php`, `src/Sandbox/` | Исполнитель песочницы |
| `services/`, `mcp/` | Необязательные модули, каждый в своём репозитории и профиле compose: browser, media-video, media-docs, telegram-bot-api; почта |
| `bin/` | Резервная копия, восстановление, обновление и проверка установки |
| `docs/`, `docs/notes/` | База знаний о построении автономных агентов: статьи и конспекты |
| `references/` | Референсные репозитории — git-сабмодули |

## Лицензия

[MIT](LICENSE)
