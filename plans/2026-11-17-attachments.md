# Входящие вложения, часть 1: приём — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** вложения из Telegram (голос, аудио, кружок, фото, видео, анимация, документ) больше не теряются: они ложатся в песочницу участника, из документов агент сразу получает текст, а про фото, голос и видео — пометку, что модель для них не назначена, с подсказкой, как назначить.

**Architecture:** пакет `messenger` разбирает вложения в `IncomingMessage::$attachments` и скачивает файл (`Channel::download`). Новый сервис приложения `AttachmentIntake` сохраняет файлы в `inbox/<дата>/`, достаёт текст (текстовые — в приложении, офисные и PDF — скриптом в песочнице) и собирает блок для сообщения; `ChatService` пропускает сообщение без текста, но с вложениями, и отдаёт блок в `RunScheduler` с пометкой «данные извне». Настройки `MEDIA_*` появляются уже здесь — чтобы пометка могла сказать, что назначить; модели зрения, речи и видео подключаются во второй части.

**Tech Stack:** PHP 8.2, AMPHP v3, Telegram Bot API (`getFile`), песочница (Python 3: python-docx, python-pptx, openpyxl; `pdftotext` из poppler-utils).

**Spec:** [BRIEF.md → Входящие вложения](../BRIEF.md#входящие-вложения)

**Части проекта** (каждая — свой план): 1 — приём (этот план); 2 — модели зрения, речи и видео, инструмент `media`, `media_prefs`, правила участников, очистка `inbox/` через 30 дней; 3 — файлы между агентами (`POST /a2a/files` с form-data, WebSocket по настройке, пределы).

## Global Constraints

- Пределы: до 10 вложений и 50 МБ на сообщение; файл бота Telegram — до 20 МБ (`getFile`), больше — пометка «бот Telegram не скачивает файлы больше 20 МБ»; текст в сообщение — до `MEDIA_TEXT_LIMIT` = 20 000 символов, длиннее — обрезается с путём к файлу; извлечение в песочнице — 30 с на файл.
- Модели по умолчанию не заданы: `MEDIA_VISION_MODELS`, `MEDIA_VIDEO_MODELS`, `MEDIA_SPEECH_MODELS` пусты; основная модель картинок не видит (решение владельца, вариант «Б»).
- Всё из файлов — данные извне: в рамке `UntrustedWrapper`, секреты через `SecretMasker`, запуск помечается `runs.outside`. Голоса участника в этой части нет (распознавание — часть 2).
- Без песочницы: офисные, PDF и видео не разбираются — пометка «включите песочницу»; файлы не хранятся.
- Тексты агента — в `packages/i18n` (ru + en), README пакета `messenger` — на двух языках в одном коммите.
- Ссылки в коде: `spec: BRIEF.md#входящие-вложения`; `ref:` на hermes — `gateway/run_inbound_transcription.py`, `tools/vision_tools.py` при @e96221fa.

## Review Focus

- Фото без подписи в личном чате — сообщение доходит до агента (сейчас `ChatService` молча отбрасывает пустой текст) — тест в задаче 5.
- Альбом из трёх фото — три сообщения подряд склеиваются слиянием `RunScheduler`, каждое со своей пометкой — тест в задаче 5.
- Имя файла `../../etc/passwd` или пустое — файл ложится в `inbox/<дата>/_.._etc_passwd` (или `file`), не выходит из `inbox/` — тест в задаче 4.
- Два вложения с одним именем в один день — второе получает номер (`report (2).docx`), первое не перезаписано — тест в задаче 4.
- Документ в нестандартной кодировке (cp1251 .txt) — текст перекодирован или помечен «не UTF-8», запуск не падает — тест в задаче 4.

---

### Task 1: вложения в пакете `messenger`

**Files:**
- Create: `packages/messenger/src/Attachment.php`, `packages/messenger/src/AttachmentKind.php`
- Modify: `packages/messenger/src/IncomingMessage.php`, `packages/messenger/src/Telegram/UpdateParser.php`, `packages/messenger/src/Channel.php`, `packages/messenger/src/Telegram/TelegramApi.php`, `packages/messenger/src/Telegram/TelegramChannel.php`, `packages/messenger/README.md`, `packages/messenger/README.ru.md`
- Modify: `test/Support/FakeChannel.php` (основной репозиторий)
- Test: `packages/messenger/test/Telegram/UpdateParserTest.php`, `packages/messenger/test/Telegram/TelegramChannelTest.php`

**Interfaces:**
- Produces: `enum AttachmentKind: string { Voice='voice'; Audio='audio'; VideoNote='video_note'; Photo='photo'; Video='video'; Animation='animation'; Document='document' }`; `final readonly class Attachment(AttachmentKind $kind, string $fileId, string $fileName, ?string $mimeType, ?int $size, ?int $duration = null, ?int $width = null, ?int $height = null, bool $forwarded = false)`; `IncomingMessage::$attachments` — `list<Attachment>`, последний именованный параметр, по умолчанию `[]`; `Channel::download(string $fileId, int $maxBytes): ?string` — null, если файл больше или не скачался; `FakeChannel::$downloads` — `array<string, string>` (fileId => байты).

- [ ] **Step 1: тесты разбора** — `testAPhotoWithoutCaptionCarriesTheLargestSize` (из `photo` — последний размер: `fileId`, `width`, `height`; имя `photo-<message_id>.jpg`; `text` = `''`), `testVoiceAudioVideoNoteVideoAnimationAndDocument` (вид, `mimeType`, `duration`, имя: у `voice` — `voice-<id>.ogg`, у `video_note` — `video_note-<id>.mp4`, у документа — `file_name`), `testAForwardedFileIsMarked` (`forward_origin` есть — `forwarded` = true), `testAStickerIsSkipped` (`attachments` = `[]`, `text` = `''`).
- [ ] **Step 2:** `vendor/bin/phpunit test/Telegram/UpdateParserTest.php` в `packages/messenger` — FAIL (нет `Attachment`).
- [ ] **Step 3:** `AttachmentKind`, `Attachment`, поле `IncomingMessage::$attachments`, разбор в `UpdateParser::parse` и `parsePost` (у постов канала — тоже).
- [ ] **Step 4: тест скачивания** — `testDownloadAsksForThePathThenTheFile` (`getFile` → `file_path`, затем GET `https://api.telegram.org/file/bot<token>/<file_path>`; возвращает байты), `testAFileOverTheLimitIsNotDownloaded` (`file_size` из `getFile` больше `$maxBytes` — `null` без второго запроса; тело больше `$maxBytes` — `null`).
- [ ] **Step 5:** `TelegramApi::getFile(string $fileId): array`, `TelegramApi::downloadFile(string $path, int $maxBytes): ?string` (тело читается с пределом `$maxBytes + 1`), `TelegramChannel::download`; `FakeChannel::download` отдаёт `$downloads[$fileId]` с учётом предела.
- [ ] **Step 6:** `composer check` в `packages/messenger` — зелёный; README обоих языков — раздел «Вложения»; коммит в пакете `feat: parse and download attachments of incoming messages`, обновить сабмодуль и `packages/README.md` (таблица и журнал).

### Task 2: настройки `MEDIA_*`

**Files:**
- Create: `src/Infrastructure/Config/MediaSettings.php`
- Modify: `src/App/Settings/SettingsCatalog.php`, `.env.example`, `packages/i18n` (тексты `settings.about.media_*`)
- Test: `test/Unit/Infrastructure/Config/MediaSettingsTest.php`

**Interfaces:**
- Produces: `MediaSettings::fromEnv(array $env): self`; свойства `list<string> $vision`, `list<string> $video`, `list<string> $speech`, `array<string, string> $endpoints` (имя => URL), `list<string> $auto` (по умолчанию `['voice','audio','image','text','office']`), `int $audioAutoMinutes` (10), `int $textLimit` (20 000), `int $inboxDays` (30); `MediaSettings::models(string $kind): list<string>` для `vision|video|speech`.

- [ ] **Step 1: тесты** — `testEverythingIsUnsetByDefault` (списки моделей пусты, `auto`, пороги — значения выше), `testModelReferencesAreChecked` (`openrouter:google/gemini-3.8-flash, openai:gpt-4o-transcribe, custom:whisper/large-v3` — принято при `MEDIA_ENDPOINTS=whisper=http://whisper:8000/v1`; `custom:nope/x` без такого адреса и `ftp:x` — `ConfigException`), `testAutoKnowsOnlyTheKinds` (`MEDIA_AUTO=voice,photo` — `ConfigException`: вид `photo` называется `image`).
- [ ] **Step 2:** запустить — FAIL.
- [ ] **Step 3:** `MediaSettings`; восемь ключей в `SettingsCatalog` в новой группе `media` (тип `TEXT`, пороги — `INT`); проверка при `/set` — через `MediaSettings::fromEnv` (как у других групп); `.env.example` — те же ключи пустыми с пояснениями.
- [ ] **Step 4:** тесты — PASS; `composer check` — зелёный; коммит `feat(app): add media settings, unset by default`.

### Task 3: извлечение текста в песочнице

**Files:**
- Create: `bin/extract_text.py`
- Modify: `Dockerfile` (стадия `sandbox`: `poppler-utils`; `COPY bin/extract_text.py /opt/mercurius/extract_text.py`)
- Test: `test/Sandbox/ExtractTextTest.php` (группа `sandbox`)

**Interfaces:**
- Produces: команда `python3 /opt/mercurius/extract_text.py <путь> <предел>` — печатает текст UTF-8 не длиннее предела; код 0 — текст, 2 — вид не поддержан, 1 — файл не читается (сообщение в stderr). Виды по расширению: `docx` (абзацы и таблицы), `pptx` (текст слайдов по порядку, с `--- слайд N ---`), `xlsx` (листы, строки через табуляцию, до предела), `odt` (`content.xml`, текст узлов), `pdf` (`pdftotext -layout`), `rtf` (снятие управляющих слов и групп `{\*…}`).

- [ ] **Step 1: тест** — `testOfficeDocumentsAndPdfGiveTheirText`: тест создаёт в песочнице docx, pptx и xlsx (python-docx и др. в той же песочнице) и PDF (`pdftotext` на сгенерированном файле можно заменить фикстурой `test/Fixtures/hello.pdf`), запускает скрипт через `exec` от имени участника — в stdout есть их строки; `testAnUnknownKindIsCode2`; `testTheLimitCutsTheText` (предел 50 — длина ≤ 50).
- [ ] **Step 2:** `docker compose --profile test run --rm sandbox-test` на сервере — FAIL (нет скрипта).
- [ ] **Step 3:** скрипт и `Dockerfile`; разбор XML — `defusedxml` недоступен, поэтому у `odt` — `xml.etree` с ограничением размера `content.xml` (до 20 МБ распакованного) и без внешних сущностей.
- [ ] **Step 4:** сборка `sandbox` и `sandbox-test` на сервере, тест — PASS; коммит `feat(docker): extract text of documents in the sandbox`.

### Task 4: `AttachmentIntake`

**Files:**
- Create: `src/App/Agent/Media/AttachmentIntake.php`, `src/App/Agent/Media/IntakeResult.php`
- Modify: `packages/i18n` (тексты `media.intake.*`)
- Test: `test/Unit/App/Agent/Media/AttachmentIntakeTest.php`

**Interfaces:**
- Consumes: `Attachment`, `Channel::download` (задача 1); `MediaSettings` (задача 2); команда извлечения (задача 3); `Sandbox::put`, `Sandbox::exec`; `UntrustedWrapper::wrap`, `SecretMasker::mask`.
- Produces: `AttachmentIntake::intake(Principal $principal, ParticipantPolicy $policy, IncomingMessage $message, Cancellation $cancellation): IntakeResult`; `final readonly class IntakeResult(string $text, bool $outside)` — `$text` = блок для сообщения (пусто, если вложений нет), `$outside` = в блоке есть данные извне.

Блок — по строке на вложение, затем содержимое: `📎 <вид> <имя>, <размер>[, <длительность>] → inbox/2026-11-17/<имя>` и одна из пометок:
- текстовый файл (`txt md csv json xml html log` и исходники по расширению, а также MIME `text/*`): содержимое в рамке `UntrustedWrapper::wrap('file:<имя>', …)`; не UTF-8 — попытка `mb_convert_encoding` из `Windows-1251`, иначе «не текст UTF-8»;
- офисный и PDF: вывод `extract_text.py` в той же рамке; код 2 — «вид документа не поддержан»; таймаут или 1 — «текст не извлечён: <причина>»;
- фото, голос, аудио, кружок, видео, анимация: «модель <зрения|речи|видео> не назначена — скажи пользователю и предложи владельцу назначить: `/set MEDIA_<VISION|SPEECH|VIDEO>_MODELS <модель>` (модели с вводом <изображений|звука|видео> из каталога; дешёвые — например, google/gemini-3.8-flash)»; если модель назначена — «модель назначена, обработка появится в следующей версии» (часть 2 заменит);
- нет песочницы — офисные, PDF и видео: «не разобрано: у участника нет песочницы»; файл не сохраняется, путь не пишется;
- больше 20 МБ или сверх 10 вложений / 50 МБ — «не скачано: <причина>».

- [ ] **Step 1: тесты** — `testAPhotoIsKeptAndTheAgentIsToldNoVisionModelIsSet` (файл в `inbox/<дата>/photo-7.jpg` через `FakeSandbox`, в тексте `MEDIA_VISION_MODELS`, `outside` = true), `testATextFileComesInsideTheUntrustedFrame` (содержимое и `<untrusted-data source="file:notes.md">`; секрет `sk-or-v1-…` замаскирован), `testAnOfficeDocumentIsExtractedInTheSandbox` (`FakeSandbox` получает `exec` с `python3 /opt/mercurius/extract_text.py 'inbox/<дата>/report.docx' 20000` от имени участника; его stdout в рамке), `testALongTextIsCutWithThePath` (21 000 символов — 20 000 и путь), `testNoSandboxNoFilesKept` (политика без `sandbox` — `put` не вызывается, у docx пометка про песочницу, у md — текст есть), `testLimitsOfSizeAndCount` (`download` → null — «не скачано»; 11-е вложение — «не скачано: больше 10»), и тесты из Review Focus: `testANameCannotLeaveTheInbox`, `testTheSameNameTheSameDayGetsANumber`, `testCp1251TextIsConverted`.
- [ ] **Step 2:** запустить — FAIL.
- [ ] **Step 3:** реализация; имя файла — тем же правилом, что `A2aServer::files()` (вынести в `Attachment`-независимую функцию `MediaNames::clean(string): string` в `src/App/Agent/Media/`, `A2aServer` использует её); дата — по часовому поясу владельца (`OWNER_TIMEZONE`, как у сводки).
- [ ] **Step 4:** тесты — PASS; `composer check`; коммит `feat(app): take attachments of incoming messages into the sandbox`.

### Task 5: вложения в `ChatService` и `RunScheduler`

**Files:**
- Modify: `src/App/Service/ChatService.php`, `src/App/Agent/RunScheduler.php`, `index.php`
- Test: `test/Unit/App/Service/ChatServiceTest.php`, `test/Unit/App/Agent/RunSchedulerTest.php`

**Interfaces:**
- Consumes: `AttachmentIntake::intake` (задача 4).
- Produces: `RunScheduler::submit(Principal $principal, IncomingMessage $message, int $conversationId, bool $silenceAllowed = false, string $attachments = '', bool $outside = false): void` — `$attachments` дописывается к тексту (через пустую строку), `$outside` = true помечает запуск `markOutside($runId, 'attachments')` при старте или, если запуск уже идёт, сразу.

- [ ] **Step 1: тесты** — `testAPhotoWithoutCaptionReachesTheAgent` (через `AgentStack`: сообщение с `attachments` и пустым текстом — модель вызвана, в её сообщении пометка про `MEDIA_VISION_MODELS`), `testACaptionAndItsPhotoGoTogether` (текст подписи, затем блок), `testAnAlbumOfThreePhotosIsOneRun` (три сообщения в пределах слияния — один запуск, три пометки), `testAttachmentsMarkTheRunOutside` (`runs.outside` содержит `attachments`), `testACommandWithAFileIsStillACommand` (`/help` с документом — команда, вложение не обрабатывается).
- [ ] **Step 2:** запустить — FAIL (пустой текст отбрасывается).
- [ ] **Step 3:** `ChatService::handle` — пустой текст отбрасывается, только если нет и вложений; вложения обрабатываются после проверок участника (гость без допуска — не обрабатываются, ждут решения владельца) и групповых правил (`GroupGate::route` — по подписи; фото в группе без обращения к агенту не обрабатывается); `index.php` — `AttachmentIntake` с `$channel`, `$sandbox`, `MediaSettings`.
- [ ] **Step 4:** тесты — PASS; `composer check` — зелёный; коммит `feat(app): let messages with attachments reach the agent`.

### Task 6: развёртывание и проверка

- [ ] **Step 1:** BRIEF.md → «Входящие вложения»: подраздел «Решено: часть 1» (что сделано, что проверено); ROADMAP — статус; CLAUDE.md — `.env` (ключи `MEDIA_*` в таблице секретов не нужны — это настройки).
- [ ] **Step 2:** развёртывание по CLAUDE.md (резервная копия, `docker compose up -d --build`, все четыре экземпляра; образ песочницы пересобирается); интеграционные и песочничные тесты на сервере — OK.
- [ ] **Step 3:** проверка в Telegram (чат основного бота): фото без подписи — агент отвечает, что модель зрения не назначена, и предлагает `/set MEDIA_VISION_MODELS …`; docx и md — агент пересказывает содержимое; файл лежит в `inbox/<дата>/` песочницы владельца.
- [ ] **Step 4:** коммит `docs(spec): note incoming attachments, part 1`; push.
