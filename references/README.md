# references/ — индекс референсных репозиториев

Здесь лежат репозитории-первоисточники, подключённые как git-сабмодули. Этот файл — точка входа:
какая версия каждого репозитория зафиксирована, что о нём написано в проекте и что менялось при обновлениях.

## Зафиксированные версии

| Репозиторий | Путь | Ветка | Коммит | Дата коммита | Проверено | Конспект |
|-------------|------|-------|--------|--------------|-----------|----------|
| [anthropics/claude-cookbooks](https://github.com/anthropics/claude-cookbooks) | [claude-cookbooks/](claude-cookbooks/) | `main` | `ce8b262` | 2026-11-04 | 2026-11-16 | [notes/claude-cookbooks.md](../notes/claude-cookbooks.md) |
| [rekryt/amphp-ssh](https://github.com/rekryt/amphp-ssh) | [amphp-ssh/](amphp-ssh/) | `master` | `9d171ac` | 2026-08-06 | 2026-11-16 | [notes/amphp-ssh.md](../notes/amphp-ssh.md) |
| [rekryt/amphp-vless](https://github.com/rekryt/amphp-vless) | [amphp-vless/](amphp-vless/) | `master` (тег `v1.1.1`) | `065417f` | 2026-08-06 | 2026-11-16 | [notes/amphp-vless.md](../notes/amphp-vless.md) |
| [tufantunc/ssh-mcp](https://github.com/tufantunc/ssh-mcp) | [ssh-mcp/](ssh-mcp/) | `main` (версия `2.26.0`) | `e04ebf2` | 2026-11-12 | 2026-11-16 | [notes/ssh-mcp.md](../notes/ssh-mcp.md) |
| [NousResearch/hermes-agent](https://github.com/NousResearch/hermes-agent) | [hermes-agent/](hermes-agent/) | `main` | `e96221fa` | 2026-11-16 | 2026-11-16 | [hermes-agent](../notes/hermes-agent.md), [память и скиллы](../notes/hermes-agent-memory-skills.md), [шлюз и безопасность](../notes/hermes-agent-gateway-security.md) |
| [itpartypattaya/hermes-plugin-holographic](https://github.com/itpartypattaya/hermes-plugin-holographic) | [hermes-plugin-holographic/](hermes-plugin-holographic/) | `main` (тег `v0.33.0`) | `9b49943` | 2026-11-27 | 2026-11-27 | [notes/hermes-plugin-holographic.md](../notes/hermes-plugin-holographic.md) |
| [itpartypattaya/hermes-dreaming](https://github.com/itpartypattaya/hermes-dreaming) | [hermes-dreaming/](hermes-dreaming/) | `main` (тег `v3.8.6`) | `21878d3` | 2026-11-07 | 2026-11-16 | [notes/hermes-dreaming.md](../notes/hermes-dreaming.md) |
| [NousResearch/hermes-official-plugins](https://github.com/NousResearch/hermes-official-plugins) | [hermes-official-plugins/](hermes-official-plugins/) | `main` | `269ddcb` | 2026-11-19 | 2026-11-23 | [notes/hermes-claude-subscription-directsdk.md](../docs/notes/hermes-claude-subscription-directsdk.md) (плагин `claude-subscription-directsdk` 0.4.0; остальные плагины не изучались) |
| [google-gemini/gemini-api-cli](https://github.com/google-gemini/gemini-api-cli) | [gemini-api-cli/](gemini-api-cli/) | `main` | `3dcbb6e` | 2026-11-26 | 2026-11-26 | [notes/gemini-api-cli.md](../docs/notes/gemini-api-cli.md) |
| [soyelmismo/hermes-antigravity-subscription](https://github.com/soyelmismo/hermes-antigravity-subscription) | [hermes-antigravity-subscription/](hermes-antigravity-subscription/) | `main` (тег `v1.0.35`) | `6e46b46` | 2026-11-26 | 2026-11-26 | [notes/hermes-antigravity-subscription.md](../docs/notes/hermes-antigravity-subscription.md) |
| [Realtyxxx/hermes-plugin-antigravity-agy](https://github.com/Realtyxxx/hermes-plugin-antigravity-agy) | [hermes-plugin-antigravity-agy/](hermes-plugin-antigravity-agy/) | `main` (автором не поддерживается, `agy` 1.2) | `ec07f86` | 2026-09-28 | 2026-11-26 | [notes/hermes-antigravity-agy.md](../docs/notes/hermes-antigravity-agy.md) |

- **Коммит** — версия, на которую указывает сабмодуль; источник истины — `git submodule status`. Таблица должна с ним совпадать.
- **Проверено** — дата, когда репозиторий последний раз сверяли с upstream (даже если обновлений не было).
- Конспект в `notes/` описывает именно ту версию, что записана в его frontmatter (`commit`).

## Сабмодули вне `references/`

Не референсы, а инструменты проекта, но версия отслеживается здесь же и по тем же правилам.

| Репозиторий | Путь | Ветка | Коммит | Дата коммита | Проверено | Назначение |
|-------------|------|-------|--------|--------------|-----------|------------|
| [opencck/skill-amphp](https://github.com/opencck/skill-amphp) | [.claude/skills/skill-amphp/](../.claude/skills/skill-amphp/) | `master` | `5553260` | 2026-03-11 | 2026-11-16 | Проектный скилл Claude Code `amphp`: правила и шаблоны асинхронного PHP на AMPHP v3 / Revolt |
| [rekryt/skill-compliance](https://github.com/rekryt/skill-compliance) | [.claude/skills/skill-compliance/](../.claude/skills/skill-compliance/) | `master` | `b5570c2` | 2026-07-22 | 2026-11-16 | Скилл `legal-compliance-engine`: документы о персональных данных и оферты |

Пакеты Меркурия — тоже сабмодули, но это наш код, а не референсы: их версии и журнал ведутся в
[packages/README.md](../packages/README.md).

| Пакет | Путь | Коммит |
|-------|------|--------|
| [opencck/mercurius-llm](https://github.com/opencck/mercurius-llm) | [packages/llm/](../packages/llm/) | `0907de0` |
| [opencck/mercurius-connectors](https://github.com/opencck/mercurius-connectors) | [packages/connectors/](../packages/connectors/) | `6920581` |
| [opencck/mercurius-messenger](https://github.com/opencck/mercurius-messenger) | [packages/messenger/](../packages/messenger/) | `cbb9e93` |

## Как проверить и обновить

1. Посмотреть, что нового в upstream, не меняя рабочую копию:
   ```bash
   git -C references/<репо> fetch --depth 50 origin main
   git -C references/<репо> log --oneline HEAD..origin/main
   git -C references/<репо> diff --stat HEAD origin/main
   ```
2. Если обновлений нет — поменять только дату «Проверено» в таблице.
3. Если есть — просмотреть изменения в местах, на которые опирается проект: файлы из конспекта и файлы, на которые ссылаются комментарии в коде (поиск по `ref: references/<репо>/`).
4. Обновить сабмодуль: `git -C references/<репо> checkout origin/main`, затем `git add references/<репо>`.
5. Обновить таблицу выше (коммит, даты) и добавить запись в журнал изменений.
6. Привести проект в соответствие: конспект в `notes/` (включая `commit` во frontmatter), выводы в `BRIEF.md`, ссылки в коде (новый коммит и номера строк), при необходимости — сам код и `ROADMAP.md`.
7. Закоммитить всё одним коммитом: сабмодуль, индекс, конспект и правки проекта.

## Журнал изменений

Новые записи — сверху. Формат записи:

```
### ГГГГ-ММ-ДД — <репо>: <старый коммит> → <новый коммит>
- Что изменилось в upstream: <кратко, со ссылками на файлы>
- Что это значит для проекта: <что перенять, что устарело в наших выводах или коде>
- Что обновлено у нас: <конспект, BRIEF.md, код, ссылки> / «ничего не требуется»
```

### 2026-11-27 — hermes-plugin-holographic: `4a794f8` → `9b49943` (v0.28.0 → v0.33.0)

- Что изменилось в upstream (63 коммита, 0.29.0–0.33.0): число, время и дата в сообщении — обычное слово
  (`retrieval.py`, 0.29.3); проверка содержимого — секреты, как их пишут люди, буквы-двойники, вытягивание системного
  промпта, линейное время шаблонов (`screen.py`, 0.30.0–0.31.7); теги и имена проверяются как текст; падежи русских
  имён в указателе сущностей (`entities.py`, 0.30.0); `auto_extract` не сохраняет служебные вставки шлюза (0.31.0);
  пометка изменённого факта, причина выдачи (`why`), даты относительных дней (`dates.py`, 0.32.0); африканские языки
  (0.33.0); замеры поиска по смыслу (`recall_check.py`, `tools/`).
- Что это значит для проекта: наш `ContentScreen` был написан по 0.28 и не читал буквы-двойники — для русскоязычного
  бота это была дыра; `17.11` и `5pm` считались идентификаторами и решали извлечение сами; «токены GPT-4o» принимались
  за секрет. Полная таблица «что взято, что нет и почему» — в конспекте, раздел «Дополнение: сверка 2026-11-27».
- Что обновлено у нас: `mercurius-memory` — `ContentScreen` (двойники, вытягивание промпта, линейные шаблоны, проверка
  ключевых слов и на выходе), `TextAnalyzer::isPlainNumber` и шаг `number_only`, пометка «(обновлён дата)», предел
  4000 символов, `RelativeDays`; приложение — `SecretMasker` (секреты, как их пишут люди; токены модели — не секрет),
  сборщик фактов без блока памяти, поиск фактов без цитаты, шапки группы и пересланного текста. Конспект: `commit`,
  `version`, строки пересчитаны, дополнение. Все ссылки `ref:` на этот референс переведены на `9b49943` с новыми
  номерами строк. Не взято: первые формы имён, африканские языки, всё для эмбеддингов и кросс-энкодера.
- Заодно разобран родственный `itpartypattaya/holographic-memory` (`37d562d`): референсом не подключён — тот же
  движок с обвязкой под Claude Code и Codex; запись — в конспекте, «Родственный проект».
- `hermes-dreaming`: в upstream 39 новых коммитов (до 3.8.16), сабмодуль не обновлялся — отдельная задача; он
  держит копию `screen.py`, и её свежие правки уже взяты отсюда.

### 2026-11-26 — gemini-api-cli, hermes-antigravity-subscription, hermes-plugin-antigravity-agy: добавлены

- `gemini-api-cli` на `3dcbb6e` (Go, Apache-2.0): клиент Gemini Developer API по ключу. Зачем проекту: основа MCP
  `gemini-api` (инструменты медиа), образец запросов, классификации ошибок и повторов Gemini API. Подписку Google он
  не даёт.
- `hermes-antigravity-subscription` на `6e46b46` (`v1.0.35`, MIT): плагин Hermes для подписки Google через
  официальный `agy` 1.5. Зачем проекту: факты о свежем `agy` — окружение, вход, квоты, ошибки, длинный ввод. Релизы
  выходят почти ежедневно, поэтому коммит закреплён. В каталоге плагинов hermes записан более старый `264bab9e`;
  взята голова на день добавления: факты нужны о свежем `agy`.
- `hermes-plugin-antigravity-agy` на `ec07f86` (MIT): единственный образец конструкции «один процесс `agy` на вызов
  модели» и замеры. Автором помечен как неподдерживаемый с 2026-09-28, проверен на `agy` 1.2.10–1.2.11 — цифры
  старые. В каталоге hermes записан `f85003d`; взята голова — она отличается пометкой о прекращении поддержки.
- Не добавлены: `rhgo1749/hermes-antigravity-acp` (нужен, только если выбран транспорт ACP; коммита из каталога
  hermes в репозитории нет), `neerazz/hermes-antigravity-oauth` (линкует файл входа в чужой HOME),
  `rarf/hermes-quota-plugin` (обновляет токен под идентификатором чужого клиента).
- Что обновлено у нас: конспекты `docs/notes/gemini-api-cli.md`, `hermes-antigravity-subscription.md`,
  `hermes-antigravity-agy.md`; в конспект `hermes-agent` дописаны провайдеры `gemini` и `vertex`; строки в
  `brief/knowledge.md`; ссылки `ref:` в пакете `llm`.

### 2026-11-23 — hermes-official-plugins: добавлен на `269ddcb`

- Официальные плагины Hermes (Python), 23 каталога. Подключён сабмодулем на коммите
  `269ddcbc8598ece2dc5bb82115ac68954bc4024f` от 2026-11-19. Подключён весь репозиторий: отдельный репозиторий плагина
  подписки архивный и не обновляется.
- Зачем проекту: плагин `claude-subscription-directsdk` (версия 0.4.0) — образец подключения Claude по подписке через
  неизменённый официальный Claude Code как одноразовый клиент модели: шлюз допуска, проигрывание истории кадрами,
  окружение дочернего процесса, лимиты плана, устойчивый префикс кеша.
- Что обновлено у нас: конспект `docs/notes/hermes-claude-subscription-directsdk.md`; в конспект `hermes-agent`
  дописан режим OAuth модуля `agent/anthropic_adapter.py` с пометкой «не берём»; строки в `brief/knowledge.md`.
  Остальные плагины репозитория не читались.

### 2026-11-16 — hermes-agent: добавлен на `e96221fa`

- Агент Hermes (Python) — образец для ядра Меркурия. Подключён сабмодулем (shallow) на коммите `e96221fa73d2dac7ba9f3bc5a8b866b3d0fc188e` от 2026-11-16. Репозиторий большой: около 26 тыс. файлов, 302 МБ в рабочей копии.
- Изучается тремя конспектами: ядро, память и скиллы, шлюз и безопасность.

### 2026-11-16 — hermes-plugin-holographic: добавлен на `4a794f8` (`v0.28.0`)

- Провайдер памяти для Hermes: хранилище фактов в SQLite, поиск на HRR. Образец организации памяти Меркурия. Подключён сабмодулем (shallow) на коммите `4a794f86f3f9704b15752d702e425753047c5f9d` от 2026-11-15.
- Выбран репозиторий `itpartypattaya`: одноимённый репозиторий `NousResearch` архивирован и ссылается на него как на поддерживаемый.

### 2026-11-16 — hermes-dreaming: добавлен на `21878d3` (`v3.8.6`)

- Ночная консолидация памяти для Hermes, оформлена как скилл. Подключён сабмодулем (shallow) на коммите `21878d3ec8752283dd7ee69a803c63bb989c1740` от 2026-11-07.
- Выбран репозиторий `itpartypattaya` (тот же автор, что у holographic). Существует другой проект с тем же именем — `asimons81/hermes-dreaming`; выбор владельцем не подтверждён.

### 2026-11-16 — skill-compliance: зарегистрирован на `b5570c2`

- Каталог `.claude/skills/skill-compliance/` был вложенным git-репозиторием; зарегистрирован как сабмодуль на коммите `b5570c2587057e669b14ec5d463537846cd9fcd1` от 2026-07-22. Адрес в `.gitmodules` — SSH (`git@github.com:rekryt/skill-compliance.git`), как в исходном клоне.

### 2026-11-16 — ssh-mcp: добавлен на `e04ebf2` (`2.26.0`)

- MCP-сервер с SSH-доступом к Linux- и Windows-хостам: политики разрешений и аудит (TypeScript). Подключён сабмодулем (shallow) на коммите `e04ebf21dd44f18f00ea2e0794b49d57dfaf3c7f` от 2026-11-12, версия `2.26.0` по `package.json`.
- Изучен: [notes/ssh-mcp.md](../notes/ssh-mcp.md).

### 2026-11-16 — amphp-ssh: добавлен на `9d171ac`

- Асинхронный SSH-клиент для PHP на Amp (PHP ≥ 8.1). Подключён сабмодулем (shallow) на коммите `9d171ac7d64270d740e5002a2f1e40c509dc2596` от 2026-08-06.
- Изучен: [notes/amphp-ssh.md](../notes/amphp-ssh.md).

### 2026-11-16 — amphp-vless: добавлен на `065417f` (`v1.1.1`)

- Асинхронный VLESS-клиент для PHP на Amp (PHP ≥ 8.2). Подключён сабмодулем (shallow) на коммите `065417fbcfc5264c9e08a04374cc24d0e9f3a513` от 2026-08-06, тег `v1.1.1`.
- Изучен: [notes/amphp-vless.md](../notes/amphp-vless.md).

### 2026-11-16 — skill-amphp: добавлен на `5553260`

- Скилл `amphp` для Claude Code подключён сабмодулем в `.claude/skills/skill-amphp/` на коммите `55532604098443e50a03db60eab8d22877f3fdec` от 2026-03-11.

### 2026-11-16 — claude-cookbooks: добавлен на `ce8b262`

- Репозиторий подключён сабмодулем (shallow) на коммите `ce8b2629efe8b24048b127b4c79af1e9cbab93d4` от 2026-11-04 («fix(registry): remove duplicate agent security review entry (#958)»).
- Изучен и законспектирован: [notes/claude-cookbooks.md](../notes/claude-cookbooks.md). Что не прочитано — в разделе 6 конспекта.
