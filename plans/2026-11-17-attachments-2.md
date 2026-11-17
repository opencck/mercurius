# Входящие вложения, часть 2: модели и обработка — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** назначенные модели зрения, речи и видео работают: фото сразу получает короткое описание, голос — текст, по запросу агент задаёт вопрос к картинке или видео и распознаёт речь; модели выбираются по порядку списка и принципам владельца и участника.

**Architecture:** клиент медиамоделей `MediaModels` (OpenAI-совместимый HTTP: `/chat/completions` с частями `image_url`/`video_url`, `/audio/transcriptions` с загрузкой файла) для OpenRouter, OpenAI и своих адресов, через HTTP-клиент моделей (VLESS), с записью расхода в `llm_calls`. `MediaPrefs` (таблица `media_prefs`, миграция 45) хранит правила участника; `MediaChoice` выбирает модель. `AttachmentIntake` обрабатывает сразу по `MEDIA_AUTO` и правилам участника; инструменты `media` и `media_prefs`; ежедневная чистка `inbox/`.

**Tech Stack:** PHP 8.2, AMPHP v3, OpenAI-совместимые API; ffmpeg в песочнице (кадры и звук видео).

**Spec:** [BRIEF.md → Входящие вложения](../BRIEF.md#входящие-вложения)

## Global Constraints

- Модели вида по умолчанию не заданы; модель вне списка вида не вызывается.
- Порядок списка — порядок предпочтения; первая не справилась (ошибка, пустой ответ) — следующая.
- Собственный голос участника (не пересланный `voice`, `video_note`) — его слова, без рамки; остальное — в рамке недоверенных данных, `runs.outside`.
- Модели медиа получают только файл и вопрос: без истории и памяти.
- Аудио длиннее `MEDIA_AUDIO_AUTO_MINUTES` сразу не распознаётся; видео сразу — только если `video` в `MEDIA_AUTO`.
- Расход: `llm_calls` с назначением `media-vision`, `media-speech`, `media-video`, беседой и запуском (если есть).

## Review Focus

- Модель отвечает ошибкой 4xx (не поддерживает картинки) — берётся следующая модель списка, а агент получает причину, если не справилась ни одна — тест в задаче 2.
- Ключ провайдера модели не задан (`openai:` без `OPENAI_API_KEY`) — пометка «нет ключа», без вызова — тест в задаче 2.
- Голосовое длиной 40 минут — сразу не распознаётся, пометка «по запросу» — тест в задаче 4.
- Участник выключил вид («голосовые не расшифровывай») — файл сохранён, модель не вызвана — тест в задаче 4.
- Пересланное голосовое — текст речи в рамке недоверенных данных, не как слова участника — тест в задаче 4.

---

### Task 1: принципы владельца и правила участника

- Settings `MEDIA_VISION_RULES`, `MEDIA_SPEECH_RULES`, `MEDIA_VIDEO_RULES` (текст до 1000 символов) в `MediaSettings::rules(string $kind): string` и каталоге настроек.
- `MediaPrefs` (`src/App/Agent/Media/MediaPrefs.php`, репозиторий `MediaPrefRepository` + Postgres + InMemory, миграция 45 `media_prefs(principal_id, kind, mode, model, rules)`): `get(int $principal, string $kind): MediaPref` (по умолчанию — из `MEDIA_AUTO`), `set(...)`.
- Инструмент `media_prefs` (`show`, `set` с `kind` ∈ `voice|audio|image|video|text|office`, `mode` ∈ `auto|demand|off`, `model`, `rules`) — своему участнику, без карточки; модель — только из списка вида.
- Тесты: настройки, контракт репозитория (in-memory и PostgreSQL), инструмент.

### Task 2: клиент медиамоделей

- `MediaModels` (`src/Infrastructure/Media/MediaModels.php`): `look(string $ref, list<array{bytes: string, mime: string}> $images, string $prompt, MediaCallContext $ctx): MediaAnswer`, `transcribe(string $ref, string $bytes, string $fileName, MediaCallContext $ctx): MediaAnswer`, `video(string $ref, string $bytes, string $mime, string $prompt, MediaCallContext $ctx): MediaAnswer`; `MediaAnswer(?string $text, ?string $error, string $model)`.
- Провайдер по приставке: `openrouter:` и голый — `https://openrouter.ai/api/v1` с `OPENROUTER_API_KEY`; `openai:` — `https://api.openai.com/v1` с `OPENAI_API_KEY`; `custom:<имя>/<модель>` — адрес из `MEDIA_ENDPOINTS`, ключ — секрет `media-endpoint-<имя>` (может не быть). Нет ключа — `MediaAnswer` с ошибкой, без запроса.
- Речь: `multipart/form-data` (`file`, `model`), ответ `text`. Зрение и видео: `chat/completions`, `max_tokens` 800, части `image_url`/`video_url` с `data:` URL.
- Расход — `LlmCallRecord` (вход/выход из `usage`, стоимость — `usage.cost` у OpenRouter).
- `MediaChoice::candidates(string $kind, ?string $preferred, ?string $asked): list<string>` — запрошенная, иначе предпочтительная участника, затем список по порядку, без повторов, только из списка.
- Тесты на `FakeHttpClient`: тело запросов, перебор при ошибке, нет ключа, учёт расхода.

### Task 3: ffmpeg в песочнице

- `Dockerfile` (стадия `sandbox`): `ffmpeg`. Скрипт `bin/video_frames.sh <путь> <каталог> <кадров>`: до 8 кадров равномерно (`-vf fps=...`) в JPEG 768 px и звук в `audio.m4a`; тест песочницы.

### Task 4: обработка при получении

- `AttachmentIntake` получает `MediaModels`, `MediaPrefs`, `MediaChoice`: для фото (режим `auto`) — короткое описание (до ~80 слов, текст на картинке дословно); для голоса и аудио (короче порога) — текст речи; видео — сразу только при `auto`. Правило участника `off` — файл хранится, модели нет; `demand` — пометка «по запросу через media».
- Собственный голос (не пересланный `voice`/`video_note`) — «🎤 <текст>» без рамки и без `outside`; прочее — в рамке.
- В строке файла — принципы владельца и участника для вида (если заданы), чтобы агент выбирал модель для уточнений.
- Тесты из Review Focus.

### Task 5: инструмент `media`

- `media` (`look` path+question+model?, `transcribe` path+model?, `video` path+question+model?, `extract` path+offset?, `models`): файл из песочницы вызывающего; `video` без модели видео — кадры и звук через `bin/video_frames.sh`, кадры — модели зрения, звук — модели речи; `models` — списки моделей, принципы владельца и участника. Опасность — чтение; результат — недоверенные данные.

### Task 6: чистка `inbox/` и развёртывание

- Раз в сутки: для участников с песочницей — `find inbox -mindepth 1 -maxdepth 1 -type d -mtime +N -exec rm -rf {} +` от их имени (`N` = `MEDIA_INBOX_DAYS`).
- BRIEF (решено: часть 2), ROADMAP; развёртывание на четырёх; проверка в Telegram с назначенной моделью зрения.
