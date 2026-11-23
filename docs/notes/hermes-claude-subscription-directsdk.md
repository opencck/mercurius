---
title: "hermes claude-subscription-directsdk — Claude по подписке через официальный Claude Code"
type: repository
source: https://github.com/NousResearch/hermes-official-plugins/tree/main/claude-subscription-directsdk
local: references/hermes-official-plugins/claude-subscription-directsdk/
commit: 269ddcb
version: 0.4.0
reviewed: 2026-11-23
---

# hermes claude-subscription-directsdk — Claude по подписке через официальный Claude Code

Формат ссылок на код в этом конспекте: `<файл>:L<от>-L<до> (имя)` — это сокращение от
`references/hermes-official-plugins/claude-subscription-directsdk/<файл>@269ddcb:L<от>-L<до> (имя)`.
В коде Меркурия ссылка пишется полностью, с префиксом `ref:`. Все номера строк сверены с файлами коммита `269ddcb`.
Слова авторов: «native» — дочерний процесс Claude Code; «relay», «admission», «gate» — шлюз допуска; «host» — Hermes.
Что прочитано не полностью — в разделе 10.

## 1. Кратко

Плагин — провайдер моделей для Hermes Agent (`kind: model-provider`, версия `0.4.0`, `plugin.yaml:L1-L3`): он даёт
агенту модели Claude по подписке Pro/Max, не имея собственных учётных данных. На каждый вызов модели запускается
немодифицированный официальный Claude Code (`claude -p`) в режиме stream-json; он служит только транспортом одного
запроса: встроенные инструменты, скиллы, источники настроек, автосжатие, память и повторы выключены
(`README.md:L151`, `directsdk.py:L1032-L1060`). Инструменты хоста показываются модели через «инертный» MCP-сервер,
который умеет только перечислять их; вызовы исполняет хост, а не Claude Code (`inert_mcp.py:L7-L20`). История
разговора проигрывается в stdin кадрами stream-json: старые пользовательские кадры — с `shouldQuery:false`, последний
кадр запускает единственный запрос (`directsdk.py:L1143-L1162`). Между Claude Code и `api.anthropic.com` стоит
локальный HTTP-шлюз на случайном порту и случайном пути: он пропускает наружу только первый `POST /v1/messages`,
чинит тело запроса ради кеша промпта и сам разбирает поток ответа, поэтому хост получает первый ответ сервера, его
usage и stop reason (`admission.py:L880-L990`). Авторизацией владеет CLI: заголовки проходят через память шлюза и
нигде не сохраняются (`README.md:L56`, `README.md:L159`). Плагин помечен экспериментальным; авторы называют его
«review build», а интерфейсы CLI, на которые он опирается, — зависящими от версии (`README.md:L45-L47`).

## 2. Карта файлов

| Файл | Строк | Что внутри |
|------|------:|-----------|
| `README.md` | 293 | Требования, установка, настройки, владение историей и кешем, замеры расхода подписки, проверки |
| `AGENTS.md` | 99 | Правила для разработчиков: инвариант окна 200K/1M, как добавить модель, сдвиг pin, CI |
| `CHANGELOG.md` | 84 | Версии 0.4.0 и 0.3.1 с причинами изменений и замерами |
| `plugin.yaml` | 94 | Манифест: имя, версия, `optional_env`, `external_dependencies`, схема девяти настроек |
| `__init__.py` | 270 | Профиль провайдера для ядра Hermes: окна моделей, стоимость, `/usage`, классификация ошибок |
| `directsdk.py` | 1357 | Транспорт: перевод запроса, история, запуск CLI, разбор stream-json, сборка ответа |
| `admission.py` | 990 | Шлюз допуска: один запрос наружу, правки тела, gzip, разбор SSE, прокси |
| `directsdk_setup.py` | 796 | Поиск CLI и его версии, вход, окружение дочернего процесса, пробы без запроса к модели, шлюз организации |
| `extra_usage.py` | 212 | Политика платного превышения лимита плана: `allow`, `notify`, `ask`, `stop` |
| `concurrency.py` | 114 | Общий на процесс пул мест: не больше `max_concurrent` процессов CLI одновременно |
| `model_catalog.py` | 126 | Закреплённые модели, окна, псевдонимы, политика thinking, суффикс `[1m]` |
| `inert_mcp.py` | 24 | Инертный MCP-сервер stdio: `initialize`, `tools/list`, отказ на `tools/call` |
| `process_tree.py` | 46 | Своя группа процессов и убийство дерева (`killpg` / `taskkill /T`) |
| `conftest.py` | 80 | Загрузка плагина так, как его ставит Hermes; подмена `Popen` для проб |
| `pyproject.toml` | 15 | Метаданные пакета, настройки pytest (`--import-mode=importlib`) |
| `tests/` | 57 файлов | Модульные тесты без входа и без платных вызовов (раздел 8) |
| `tests/fixtures/lifecycle_cli.py` | 53 | Поддельный CLI для тестов жизненного цикла и дерева процессов |
| `tests/fixtures/progress_cli.py` | 52 | Поддельный CLI для тестов «живости» потока |
| `evals/directsdk_admission.py` | 180 | Настоящий бинарник CLI против синтетического HTTP-собеседника, 13 режимов |
| `evals/directsdk_cache_wire.py` | 240 | Проверка неизменности кешируемого префикса между запросами |
| `evals/directsdk_relay_close.py` | 123 | Замер цены закрытия шлюза |
| `evals/ci.sh` | 19 | Установка закреплённого CLI из npm и запуск двух первых evals в CI |
| `assets/*.png` | — | Картинки для README и каталога |

## 3. Один вызов модели, по шагам

Хост вызывает транспорт в форме OpenAI Chat Completions (`api_mode='chat_completions'`, `__init__.py:L241`);
ответ возвращается в той же форме. Ниже — путь одного вызова.

1. **Вход.** `client.chat.completions.create(**kwargs)` — `directsdk.py:L902`, `L923-L936 (Client.create)`.
   Вне event loop вызов синхронный; из корутины возвращается `_acreate`, который уводит работу в поток
   (`L938-L948`). Запрос регистрируется до подготовки, чтобы отмена достала его во время сборки истории
   (`L950-L958`).
2. **Перевод параметров.** `request_body(kwargs)` — `directsdk.py:L338-L449`: белый список параметров
   (`L339-L344`), отказ на всё неподдерживаемое (`RequestRejected`), сборка «extra body» для CLI: `thinking`,
   `output_config.effort`, `output_config.format`, `max_tokens`, `stop_sequences`, `tools`. Возвращает
   JSON-строку тела, манифест инструментов для MCP и таблицу имён «имя хоста → имя для CLI».
3. **Перевод истории.** `prepare_history(messages, names)` — `directsdk.py:L228-L283`: системные сообщения
   склеиваются в один текст, остальное превращается в кадры `{'type': 'user'|'assistant', 'message': {...}}`;
   в конце — замена чужих идентификаторов вызовов (`L194-L225`).
4. **Ленивый поток.** `_create` — `directsdk.py:L956-L977`: генератор `_run` оборачивается в `Stream`; без
   `stream=True` поток вычитывается до чанка с полем `_response`, затем закрывается.
5. **Подготовка каталога и MCP.** `_run` — `directsdk.py:L988-L1002`: проверка таймаута, временный каталог
   `claude-directsdk-*` на запрос; если инструменты есть — `tools.json` и копия `inert_mcp.py`, иначе пустой
   `{'mcpServers': {}}`.
6. **Окружение и вход.** `directsdk.py:L1003-L1023`: копия окружения, имя пользователя, удаление API-ключей, отказ
   при `ANTHROPIC_BASE_URL` и переключателях Bedrock/Vertex/Foundry, поиск бинарника (`resolve_claude`), выбор
   каталога входа, токен профиля, уборка зависшего `.oauth_refresh.lock`, выбор адреса назначения
   (`resolve_upstream`).
7. **Очередь и лимит плана.** `directsdk.py:L1025-L1028`: ожидание места в пуле (`concurrency.acquire`,
   `concurrency.py:L72-L114`), затем проверка платного превышения (`check_extra_usage`,
   `directsdk_setup.py:L784-L791`) — до запуска CLI и до любого списания.
8. **Флаги окружения.** `directsdk.py:L1030-L1045` и `directsdk_setup.py:L295-L305 (apply_traffic_policy)`,
   `L756-L770 (egress_env)`: полный список — в разделе 5.
9. **Шлюз.** `directsdk.py:L1047-L1051`: создаётся `Admission` с кадром, который хост запрашивает
   (`queried=frames[-1]['message']['content']`), выставляется признак одноразового вызова `one_shot`, адрес шлюза
   кладётся в `ANTHROPIC_BASE_URL` дочернего процесса.
10. **Файлы запроса.** `directsdk.py:L1054-L1058`: `settings.json` с `{'env': {'CLAUDE_CODE_EXTRA_BODY': body}}`,
    `system.md` с системным промптом; `max_tokens` дублируется в `CLAUDE_CODE_MAX_OUTPUT_TOKENS`.
11. **Командная строка и запуск.** `directsdk.py:L1060-L1072`: команда (раздел 4.1), необязательный `--effort`,
    `--session-id` от `conversation_session_id`, запуск через `Request.spawn` (`L468-L473`) в своей группе
    процессов, в общем рабочем каталоге (`_workdir`, `L919-L921`), с каналами stdin/stdout/stderr.
12. **Потоки чтения.** `directsdk.py:L1076-L1107`: поток слива stderr (последние 40 строк), поток чтения stdout,
    который разбирает каждую строку как JSON и кладёт в очередь; при конце потока он добивает группу процессов и
    ждёт процесс. `receive()` (`L1109-L1125`) достаёт событие с опросом 0,2 с, проверяет отмену и срок; срок
    сдвигается при каждом событии.
13. **Проигрывание истории.** `directsdk.py:L1143-L1162`: кадры пишутся в stdin по одному; у каждого
    пользовательского кадра, кроме последнего, ставится `shouldQuery: False`, и после него ждётся событие
    `result` с `num_turns == 0` без `is_error`. После последнего кадра stdin закрывается (`send(None)`).
    За записью следит один сторож `_StdinWatch` (`L703-L740`).
14. **Запрос CLI.** Claude Code поднимает MCP-сервер, получает список инструментов и шлёт `POST` на
    `<ANTHROPIC_BASE_URL>/v1/messages`. Шлюз (`admission.py:L880-L990 (Handler.do_POST)`) допускает первый запрос,
    правит тело, пересылает его и одновременно отдаёт поток CLI и разбирает его сам (`Capture`, `L566-L637`).
15. **Цикл событий.** `directsdk.py:L1169-L1210`: `assistant` (или его ошибка), `result`, `rate_limit_event`,
    `stream_event`. Из `stream_event` хосту идут чанки: текст (`text_delta`), рассуждение (`thinking_delta`),
    заголовки и аргументы вызовов инструментов (`_ToolStream`, `L554-L593`), пустой чанк «жив» для событий без
    текста; `ping` чанка не даёт.
16. **Конец процесса.** `directsdk.py:L1211-L1223`: если ответ чистый (`_fast_eligible`, `L488-L497`), процесс и
    шлюз передаются фоновому потоку `_hand_off` (`L596-L622`), иначе ждётся выход процесса.
17. **Чей ответ главный.** `directsdk.py:L1226-L1241`: если шлюз был использован, итоговое сообщение берётся из
    его захвата (`admission.capture.message`), а не из событий CLI; при статусе не 200 или неполном захвате —
    `ClaudeAPIError` с настоящим HTTP-статусом первой попытки.
18. **Ошибки CLI и полнота.** `directsdk.py:L1242-L1253`: ошибка, на которую CLI ответил сам, превращается в
    `ClaudeCodeLoggedOut` или `ClaudeAPIError` со статусом из `NATIVE_ERROR_STATUS`; требуется ровно один
    `result`, хотя бы одно сообщение и `message_stop`.
19. **Сборка ответа.** `directsdk.py:L1254-L1329`: вызовы инструментов с обратной заменой имён, незавершённые
    вызовы, граница `error_max_turns`, usage, текст, рассуждение, «носитель» исходных сообщений для следующего
    проигрывания, отказ модели, причина завершения, последний чанк с `_response`.
20. **Уборка.** `directsdk.py:L1330-L1353`: остановка сторожа, отмена запроса (убивает дерево), закрытие шлюза,
    ожидание процесса, закрытие каналов, освобождение места в пуле, снятие регистрации.

## 4. Таблица приёмов

### 4.1. Командная строка

Одна строка кода собирает команду — `directsdk.py:L1060 (Client._run)`:

`<claude> -p --model <M> --input-format stream-json --output-format stream-json --verbose`
`--include-partial-messages --tools '' --system-prompt-file <tmp>/system.md --settings <tmp>/settings.json`
`--setting-sources '' --strict-mcp-config --disable-slash-commands --max-turns 1 --permission-mode dontAsk`
`--no-session-persistence --mcp-config <JSON>`, затем `--effort <уровень>` (`L1063-L1065`) и
`--session-id <uuid>` (`L1071`).

| Флаг | Зачем (по коду и README) |
|------|--------------------------|
| `-p` | Неинтерактивный режим; один запрос и выход |
| `--model <M>` | Модель после `native_model()`: псевдоним и суффикс `[1m]` (`model_catalog.py:L72-L86`) |
| `--input-format stream-json` | История и запрос подаются кадрами JSON в stdin |
| `--output-format stream-json`, `--verbose` | События построчно в stdout: `assistant`, `result`, `stream_event`, `rate_limit_event` |
| `--include-partial-messages` | События `stream_event` с дельтами текста, рассуждения и аргументов |
| `--tools ''` | Встроенные инструменты CLI выключены (`README.md:L151`) |
| `--system-prompt-file` | Системный промпт хоста из файла — в обход лимита длины аргумента (`README.md:L151`) |
| `--settings <файл>` | Файл настроек, через который в процесс попадает `CLAUDE_CODE_EXTRA_BODY` (`directsdk.py:L1052-L1054`) |
| `--setting-sources ''` | Не читать пользовательские и проектные настройки |
| `--strict-mcp-config`, `--mcp-config <JSON>` | Только MCP-серверы из переданного JSON |
| `--disable-slash-commands` | Без слэш-команд и скиллов CLI |
| `--max-turns 1` | Один логический шаг; HTTP-границу даёт шлюз (`README.md:L169`) |
| `--permission-mode dontAsk` | Исполнение MCP-инструментов запрещено без вопроса (`README.md:L151`) |
| `--no-session-persistence` | Сессия не пишется на диск |
| `--effort <уровень>` | Иначе CLI добавляет своё сообщение об уровне, которое перекрывает `output_config.effort` (`directsdk.py:L1061-L1065`) |
| `--session-id <uuid>` | Одна сессия CLI на разговор (`directsdk.py:L1066-L1071`) |

Команды проб без запроса к модели: `auth status` (`directsdk_setup.py:L437`); рукопожатие `initialize` с
`--model sonnet` и без `--max-turns`, `--settings`, `--system-prompt-file` (`L490-L492`); `get_usage` без `--model`
(`L604-L606`).

### 4.2. Приёмы

| Приём | Где | Зачем |
|-------|-----|-------|
| Системный промпт файлом | `directsdk.py:L1055`, `L1060` | Лимит ОС на длину аргумента; проверено 176K промпта (`README.md:L285`) |
| Тело запроса через файл настроек | `directsdk.py:L1052-L1054`: `settings.json` → `env.CLAUDE_CODE_EXTRA_BODY` | Схемы инструментов и поля генерации не помещаются в переменную окружения; проверено 182K схем (`README.md:L285`) |
| Сброс унаследованных `CLAUDE_CODE_EXTRA_BODY`, `CLAUDE_CODE_EFFORT_LEVEL` | `directsdk.py:L1029-L1031` | Чужой уровень перекрыл бы `--effort` |
| Инертный MCP-сервер | `inert_mcp.py:L7-L20 (main)`; подключение `directsdk.py:L998-L1002` | Модель видит инструменты хоста; `tools/call` всегда отвечает `isError` |
| Без инструментов — без MCP | `directsdk.py:L996-L999` | Минус ~0,6 с старта интерпретатора на вызов |
| Текст помощника читается при импорте | `directsdk.py:L49-L51 (INERT_MCP)`, `L1001` | Копию плагина во временном каталоге может удалить чистильщик |
| Префикс имён `mcp__hermes__` | `directsdk.py:L123 (PREFIX)`, `L446` | Имя MCP-инструмента для модели: `mcp__<сервер>__<имя>`; сервер назван `hermes` (`L1002`) |
| Проверка имён | `directsdk.py:L431-L432` | Только `[A-Za-z0-9_-]{1,64}`, без повторов |
| Псевдоним для имён длиннее 50 | `directsdk.py:L435-L439`: `name[:41] + '_' + sha256(name)[:8]` | CLI получает имя не длиннее 50 символов; столкновение псевдонимов — отказ |
| Обратная замена имён | `directsdk.py:L513-L524 (offered_tool_name)`, `L980`, `L1259` | Возвращает имя хоста; чинит пропавший префикс или сегмент сервера; чужое имя отдаёт как есть |
| Имена в проигрываемой истории | `directsdk.py:L260-L265` | Вызов из списка инструментов получает префикс и псевдоним, вызов вне списка — имя как назвала модель |
| Нормализация схем инструментов | `directsdk.py:L286-L301 (normalize_input_schema)` | Убирает верхние `oneOf`/`allOf`/`anyOf` и null-ветви, дописывает `type: object`, `properties: {}` |
| Одинаковая схема в манифесте и теле | `directsdk.py:L443-L446` | MCP-манифест (`inputSchema`) и `tools[].input_schema` обязаны совпадать |
| Чистка схемы структурного вывода | `directsdk.py:L304-L335 (native_format_schema)`, `L396-L403` | Ключи, на которые сервер отвечает 400, убираются только с провода |
| Отказ на неподдерживаемое | `directsdk.py:L339-L365`, `L427-L434` | `n>1`, `tool_choice`, `parallel_tool_calls=False`, strict-схемы, лишние заголовки и поля |
| `temperature`, `top_p` отбрасываются | `directsdk.py:L414-L419` | Модели подписки отклоняют параметры сэмплинга |
| Кадры stream-json | `directsdk.py:L279`, `L1143-L1147` | Формат строки stdin: `{'type': роль, 'message': {'role', 'content'}}` |
| `shouldQuery:false` и подтверждение | `directsdk.py:L1145-L1161` | Исторические пользовательские кадры не вызывают запрос; ждётся `result` с `num_turns == 0` |
| Слияние подряд идущих user | `directsdk.py:L276-L277` | Результаты инструментов и следующий текст — один кадр |
| Результат инструмента как user | `directsdk.py:L266-L271` | `tool_result` с `tool_use_id`, `is_error` только если задан |
| История кончается на user | `directsdk.py:L280-L281` | Префилл ассистента не поддержан |
| Чужие идентификаторы вызовов | `directsdk.py:L194-L225 (_normalize_history_tool_ids)` | Не `[a-zA-Z0-9_-]+` → `toolu_hermes_<sha256[:32]>`, пары вызов–результат сохраняются |
| Носитель исходных сообщений | `directsdk.py:L122 (CARRIER)`, `L1293-L1294`, чтение `L240-L256` | Подписанное рассуждение проигрывается дословно, пока видимая проекция не менялась |
| `usage` убирается из проигрываемых кадров | `directsdk.py:L250-L254` | CLI считает свой порог «Prompt is too long» по usage последнего сообщения |
| Изображения | `directsdk.py:L165-L176 (content_blocks)` | Только base64; удалённый URL заменяется текстовой подсказкой |
| Сторож записи в stdin | `directsdk.py:L703-L740 (_StdinWatch)`, `L1127-L1142` | Один поток на запрос вместо таймера на кадр; зависшая запись убивает процесс |
| Случайный порт и путь шлюза | `admission.py:L734-L740 (Admission.__init__)` | `127.0.0.1:0`, путь `/admit/<token_urlsafe(32)>` |
| Наружу только первый POST | `admission.py:L887-L897 (Handler.do_POST)` | CLI повторяет запросы вопреки `--max-turns 1` и `CLAUDE_CODE_MAX_RETRIES=0` (`README.md:L41`) |
| Повтор после 401 | `admission.py:L857-L878 (_allows_auth_retry)` | CLI обновляет вход и шлёт запрос заново; пропускается один раз |
| Локальные ответы | `admission.py:L884-L886`, `L889-L896`, `L677-L693` | 404, 400 `HERMES_MODEL_ADMISSION_CONSUMED`, 501 на разогревочный GET/HEAD |
| Строка даты | `admission.py:L183-L187 (_DATE_LINE)`, `L190-L257 (stable_request_context)` | CLI выносит дату в хвостовое system-сообщение; шлюз возвращает её в сообщение окружения |
| Напоминания CLI | `admission.py:L258-L270`; первый ход — `L145-L180 (_frame_first)` | Текст, добавленный перед кадром хоста, удаляется |
| Восстановление кадра результатов | `admission.py:L445-L563 (restore_queried_turn)` | Кадр с `tool_result` приводится к виду хоста; при сомнении не трогается |
| Закрепление точки кеша | `admission.py:L338-L398 (pin_message_breakpoint)` | `cache_control` переносится на последний блок, который повторится в следующем запросе |
| Дополнительная точка для длинного цикла | `admission.py:L283-L335 (_keep_turn_mark)` | Поиск кеша смотрит назад на 20 блоков |
| Пределы кеша | `admission.py:L26-L29`: `LOOKBACK = 20`, `MAX_BREAKPOINTS = 4`; `L25 (UNCACHEABLE)` | Счёт точек — по всему телу (`_breakpoints`, `L132-L142`); метка не ставится на `thinking` |
| Снятие меток у одноразового вызова | `admission.py:L401-L423 (drop_message_breakpoints)`, `L903-L904`; признак `directsdk.py:L1048-L1050` | Сводки и заголовки никто не перечитает; запись в кеш стоила бы 2x |
| `eager_input_streaming` | `admission.py:L426-L442 (stream_tool_input)`, `L914-L915` | За шлюзом CLI не включает потоковые аргументы; без них долгий вызов выглядит зависшим |
| Отключение `eager` после сбоя | `directsdk.py:L500-L510`, `L1070`, `L1269-L1271` | Разговор, где пришёл невалидный JSON аргументов, дальше просит сервер буферизовать |
| gzip больших тел | `admission.py:L37-L51 (_gzip_eligible)`, `L924-L929` | Тело от 4096 байт, только прямой `api.anthropic.com` |
| Отказ от gzip | `admission.py:L54-L83 (_rejection_kind)`, `L932-L955` | При 400/403/415 по причине кодирования — одна пересылка без сжатия и защёлка на процесс |
| Одна сессия CLI на разговор | `directsdk.py:L184-L191 (conversation_session_id)`, `L1066-L1071` | UUID из sha256 от системного промпта и первого кадра |
| Один рабочий каталог | `directsdk.py:L834-L855 (shared_workdir)`, `L862-L885 (process_workdir)` | CLI пишет cwd в блок окружения каждого запроса |
| Проверка приватности каталога | `directsdk.py:L797-L831 (_private_dir, _tighten, _create_private)` | Свой, без прав для других, не симлинк |
| Имя пользователя в окружении | `directsdk.py:L640-L665 (_with_login_identity)` | Без `USER` залогиненный CLI отвечает `loggedIn: false` |
| Windows-переменные | `directsdk.py:L625-L637 (_with_windows_essentials)` | Без `SystemRoot` дочерний процесс не откроет сокет |
| Удаление API-ключей | `directsdk_setup.py:L153-L162 (drop_api_credentials)`; `directsdk.py:L1004-L1006` | Иначе CLI вошёл бы ключом и списал API-расход |
| Отказ при чужом бэкенде | `directsdk.py:L1007-L1010` | `ANTHROPIC_BASE_URL`, Bedrock/Vertex/Foundry |
| Имя вызывающего | `directsdk_setup.py:L141-L147 (CALLER_IDENTITY)`, `L302` | `CLAUDE_CODE_ENTRYPOINT=hermes-agent`; замер — раздел 7 |
| Без артефактов | `directsdk_setup.py:L145-L147` | Вне `sdk-*` CLI добавляет строку scratchpad в каждый запрос |
| Тихий трафик | `directsdk_setup.py:L128-L140`, `L295-L305 (apply_traffic_policy)` | Телеметрия, отчёты, автообновление, заголовок сессии |
| Без напоминания о токенах | `directsdk.py:L1040-L1041` | Напоминание ломало кешируемый префикс |
| Без памяти и CLAUDE.md | `directsdk.py:L1042-L1045` | `--setting-sources ''` их не выключает |
| Без поиска инструментов, повторов и сжатия | `directsdk.py:L1032` | Хост владеет повторами и сжатием |
| Прокси и CA для CLI | `directsdk_setup.py:L756-L770 (egress_env)`, `L730-L738` | Собственные вызовы CLI идут мимо шлюза на `api.anthropic.com` |
| Прокси для шлюза | `admission.py:L640-L674 (upstream_proxy)`, `L832-L855 (_connect_upstream)` | Шлюз сам открывает соединение; только HTTP CONNECT |
| Шлюз организации | `directsdk_setup.py:L715-L753 (resolve_upstream)`, `L675-L712` | Свой адрес, прокси, CA; неверная настройка — отказ, не обход |
| Каталог моделей | `model_catalog.py:L2-L14 (CONTEXT_WINDOWS)`, `L33-L39 (ALIASES)` | 11 закреплённых моделей; 1M у всех, кроме Haiku 4.5 |
| Суффикс `[1m]` | `model_catalog.py:L72-L86 (native_model)` | За шлюзом CLI даёт 200K, пока явно не выбран `[1m]` (`README.md:L260`) |
| Окно по умолчанию 200K | `__init__.py:L134-L140 (get_model_context_length)` | Незакреплённой модели 1M не обещается (`AGENTS.md:L9-L24`) |
| Короткие имена и префикс поставщика | `model_catalog.py:L45-L69 (_route_base)` | `sonnet-5.5`, `anthropic/claude-…` приводятся к закреплённому имени |
| Модели по допуску | `model_catalog.py:L15-L19 (ACCESS_GATED)`, `L106-L108` | Mythos показывается, только если его даёт сам аккаунт |
| Thinking: включение | `directsdk.py:L388-L393` | `{'type': 'adaptive', 'display': 'summarized'}`; без `display` рассуждение приходит пустым |
| Thinking: выключение | `directsdk.py:L377-L386`; `model_catalog.py:L20-L32`, `L89-L92` | `{'type': 'disabled'}` и `context_management: {'edits': []}`; где нельзя — `effort: low` |
| Модели без adaptive | `model_catalog.py:L40-L42`, `L95-L99` | Haiku 4.5 отвечает 400 на блок adaptive |
| Effort | `directsdk.py:L373-L376`, `L394-L395`, `L1063-L1065` | Уровни `none`, `low`, `medium`, `high`, `xhigh`, `max`; уходит и в тело, и в `--effort` |
| Только рассуждение без текста | `directsdk.py:L1287-L1292` | `reasoning_content` не отдаётся, чтобы хост не принял его за ответ |
| Лимит одновременных процессов | `concurrency.py:L72-L114 (acquire)`, `L52-L58 (_take)`; настройка `directsdk_setup.py:L192-L208` | По умолчанию 4; очередь FIFO; место держится до выхода процесса |
| Общий пул на процесс | `concurrency.py:L17-L22` | Объект пула лежит в `sys.modules` под фиксированным именем |
| Сигнал «жив» в ожидании | `concurrency.py:L107-L109`, `directsdk.py:L1025-L1026` | Раз в секунду пустой чанк, иначе хост сочтёт вызов зависшим |
| `rate_limit_event` | `directsdk.py:L668-L692 (_rate_limit_summary)`, `L1185-L1191` | Окно, `isUsingOverage`, время сброса, заполнение окон |
| `get_usage` | `directsdk_setup.py:L594-L630 (_ask_usage)`, `L571-L591 (fetch_usage)` | Лимиты плана спрашиваются у CLI без запроса к модели |
| Политика превышения | `extra_usage.py:L161-L205 (admit)`, `L82-L102 (observe)`, `L124-L142 (observe_usage)` | `allow`, `notify`, `ask`, `stop`; неизвестное значение = `stop` (`L55-L62`) |
| Процент резерва | `extra_usage.py:L112-L121 (reserve_percent)`, `L85-L92` | Остановка до первого платного запроса |
| Время сброса окна из 429 | `admission.py:L86-L98 (rejected_window_reset)`, `L959`; `directsdk.py:L75-L77` | Хост узнаёт, когда окно откроется |
| Таблица статусов ошибок | `directsdk.py:L112-L119 (NATIVE_ERROR_STATUS)`, `L1251` | Хост выбирает повтор или запасной провайдер по `status_code` |
| Формулировки сбоев транспорта | `directsdk.py:L80-L105 (transport_phrase)`, `L1235-L1238` | Классификатор хоста читает текст ошибки |
| Идентификатор запроса | `directsdk.py:L60-L62 (_request_id)`; `admission.py:L957` | В ошибку попадает только `req_[A-Za-z0-9_-]{1,124}` |
| Отказ модели | `directsdk.py:L1261`, `L1295-L1310`, `L1320` | `stop_reason: refusal` → `finish_reason: content_filter`, причина из `stop_details` |
| Оборванные аргументы при отказе | `directsdk.py:L527-L551 (_json_suffix)`, `L582-L593 (close_refused)` | Уже отправленный фрагмент JSON дописывается до разбираемого |
| Незавершённый вызов | `admission.py:L615-L628`, `L634-L637`; `directsdk.py:L1263-L1271` | Вызов с неразобранными аргументами не исполняется: `finish_reason: length` |
| Граница `error_max_turns` | `directsdk.py:L1272-L1277` | Ответ с вызовами инструментов завершается этим подтипом и кодом выхода 1 — это норма |
| Отмена | `directsdk.py:L460-L466 (Request.cancel)`, `L904-L909`; `admission.py:L777-L792 (abort)` | Закрыть сокеты шлюза и убить дерево процессов |
| Убийство дерева | `process_tree.py:L32-L46 (kill_process_tree)`, `L7-L11`, `L14-L29 (unreaped)` | `killpg(SIGKILL)` или `taskkill /F /T`; сигнал только пока лидер не убран |
| `Client.close()` ничего не делает | `directsdk.py:L911-L917` | Закрытие общего клиента убивало параллельные вызовы |
| Быстрый возврат | `directsdk.py:L488-L497 (_fast_eligible)`, `L1182-L1184`, `L1211-L1217` | Ответ отдаётся на событии `result`, не дожидаясь выхода процесса |
| Фоновая уборка | `directsdk.py:L476-L485`, `L596-L622 (_hand_off)` | 10 с на выход, затем убийство; шлюз и место в пуле освобождаются после выхода |
| stderr в ошибках | `directsdk.py:L695-L700 (_stderr_tail)`, `L1076-L1090` | Первые 400 символов stderr попадают в текст ошибки |
| Вход | `directsdk_setup.py:L46-L57`, `L424-L453 (setup_status)` | `claude auth login`; `CLAUDE_CODE_OAUTH_TOKEN` из `claude setup-token`; каталог входа |
| Выбор каталога входа | `directsdk_setup.py:L211-L218 (apply_config_dir)` | Настройка → `CLAUDE_SUBSCRIPTION_DIRECTSDK_CONFIG_DIR` → `CLAUDE_CONFIG_DIR` |
| Токен профиля | `directsdk_setup.py:L257-L292 (apply_profile_credential)` | Профиль не работает на чужом входе; без своего входа — отказ |
| Платный вход не считается входом | `directsdk_setup.py:L49-L53 (_METERED_AUTH)`, `L445-L451` | `auth status` говорит `loggedIn: true` и для API-ключа, и для облака |
| Уборка замка обновления | `directsdk_setup.py:L323-L347 (reap_orphaned_refresh_lock)` | Пустой `.oauth_refresh.lock` старше 60 с удаляется |
| Список моделей аккаунта | `directsdk_setup.py:L462-L568 (discover_models)` | Рукопожатие `initialize` без пользовательского хода |
| Поиск CLI | `directsdk_setup.py:L60-L70`, `L101-L120 (_resolve)` | `PATH`, затем обычные каталоги установки |
| Минимальная версия CLI | `directsdk_setup.py:L73-L98`, `L107-L117` | `_MIN_CLAUDE_VERSION = (2, 1, 280)` |
| TTL кеша промпта | `__init__.py:L122-L128`, `L184-L188`; `directsdk.py:L1035-L1038` | Дочернему агенту — `CLAUDE_CODE_PROMPT_CACHE_TTL=5m` |
| Пробы владеют своим процессом | `directsdk_setup.py:L350-L413 (_probe)` | Процесс убивается и дожидается до удаления каталога |

### 4.3. Пояснения к отдельным приёмам

**Проигрывание истории.** Кадр ассистента восстанавливается из носителя только когда текущая проекция сообщения
(текст и вызовы) равна проекции, записанной при ответе (`directsdk.py:L246-L256`); иначе кадр собирается заново из
видимого текста и вызовов, без подписанного рассуждения (`L257-L265`). Носитель должен быть один и версии 1
(`L243-L244`). Системные сообщения допустимы только до истории (`L232-L238`).

**Рабочий каталог.** В коде нет каталога на разговор: каталог один на пользователя ОС
(`<tempdir>/claude-directsdk-cwd-<uid>`, `directsdk.py:L844`), а если общий небезопасен или нет `getuid` — один на
процесс (`L883`). На разговор приходится только идентификатор сессии. Каталог на запрос (`L994`) хранит лишь
файлы запроса, CLI в нём не работает (`L992-L993`).

**Правки тела в шлюзе.** Порядок применения — `admission.py:L903-L915`: снятие меток у одноразового вызова →
восстановление кадра результатов → строка даты и напоминания → закрепление точки кеша → `eager_input_streaming`.
Каждая функция возвращает тело без изменений, если оно не разбирается или имеет неожиданную форму
(`L272-L273`, `L397-L398`, `L422-L423`, `L441-L442`). Восстановление кадра при несовпадении бросает
`QueriedTurnMismatch`, который шлюз ловит и запоминает в `unrestored` (`L905-L911`); хост видит это в
`usage.native_admission.unrestored` (`directsdk.py:L1318-L1319`).

**Закрепление точки кеша.** Повторяющейся частью считается всё до последнего сообщения ассистента включительно и
начальные блоки нового пользовательского сообщения, равные кадру хоста (`admission.py:L374-L385`). Если первый
изменённый блок — `tool_result`, префикс нового сообщения не берётся (`L383-L385`). Метки после этой части снимаются
и одна ставится на её последний кешируемый блок (`L386-L392`). Дополнительная метка ставится, если расстояние
до начала открытого хода больше `LOOKBACK` и всего меток меньше `MAX_BREAKPOINTS`; на пределе переносится
«свободная» метка CLI (`L320-L335`). Новая метка копирует `cache_control` следующей за ней (`L329`).

**Политика превышения.** Состояние хранится в памяти процесса по ключу «каталог входа + хеш токена»
(`extra_usage.py:L46-L52`). Ответ без времени сброса действует 15 минут (`L29-L30`, `L75-L79`). В `ask` и `stop`
проба, которая не смогла ответить, означает отказ (`L181-L186`); окно, разрешённое
`extra_usage_allowed_until`, проверку пропускает (`L172-L174`). Отказ — `ClaudeExtraUsageBlocked` со статусом 429
(`L37-L43`); хост не уходит на запасного провайдера (`__init__.py:L206-L223`).

**Ошибки и статусы.**

| Ошибка | Статус | Где |
|--------|-------:|-----|
| `ClaudeCodeMissing` — CLI не найден | 503 | `directsdk.py:L55-L57`, `L1012-L1014` |
| `ClaudeCodeLoggedOut` — нет входа | 401 | `directsdk_setup.py:L246-L248`, `L291`; `directsdk.py:L1244-L1250` |
| `ClaudeAPIError` — отказ сервера | статус первой попытки, если ≥ 400 | `directsdk.py:L1227-L1239` |
| `ClaudeAPIError` — оборванный ответ 200, сбой шлюза | нет | `directsdk.py:L1232-L1233` |
| `ClaudeAPIError` — код CLI `rate_limit` | 429 | `directsdk.py:L114` |
| … `billing_error` | 402 | `directsdk.py:L115` |
| … `authentication_failed` | 401 | `directsdk.py:L116` |
| … `overloaded` | 529 | `directsdk.py:L117` |
| … `server_error` | 503 | `directsdk.py:L118` |
| `ClaudeExtraUsageBlocked` | 429 | `extra_usage.py:L37-L43` |
| `RequestRejected` (`ValueError`) | нет | `directsdk.py:L108-L109`; без повтора, запасной провайдер разрешён (`__init__.py:L232-L233`) |
| `ClaudeUpstreamConfigError` | нет | `directsdk_setup.py:L653-L654`; без повтора и без запасного (`__init__.py:L219-L221`) |
| `RuntimeError('Claude request cancelled')` | нет | `directsdk.py:L1113`, `L1225`; хост считает таймаутом (`__init__.py:L217-L218`) |
| `TimeoutError('Claude request timed out')` | нет | `directsdk.py:L1116`, `L1140`; `concurrency.py:L102-L103` |
| `RuntimeError` — сбой протокола CLI | нет | `directsdk.py:L1123`, `L1157`, `L1160`, `L1253`, `L1277`, `L1280`, `L1286` |

Ответ 400 с текстом «Third-party apps now draw from your extra usage» хост показывает как остановку по лимиту плана
(`__init__.py:L201-L229`).

**Причина завершения** (`directsdk.py:L1320`): отказ → `content_filter`; неразобранный вызов → `length`; есть
вызовы → `tool_calls`; `stop_reason` из `max_tokens` или `model_context_window_exceeded` → `length`; иначе `stop`.

**Usage** (`directsdk.py:L1311-L1319`): `prompt_tokens` = `input_tokens` + `cache_read_input_tokens` +
`cache_creation_input_tokens`; исходный объект лежит в `native_usage`; `native_cost` — `total_cost_usd` и
`modelUsage` из события `result`; `native_admission` — `upstream_requests`, `blocked_requests`, `request_id`,
при наличии `rate_limit` и `unrestored`.

**Минимальные версии CLI.**

| Что | Версия | Где сказано |
|-----|--------|-------------|
| Нижняя граница плагина | 2.1.280 | `directsdk_setup.py:L73-L76` |
| Opus 5.5 (`opus`) | 2.1.280 | `README.md:L13` |
| Sonnet 5.5 (`sonnet`) | 2.1.284 | `README.md:L13` |
| Haiku 5.5 (`haiku`) | 2.1.293 | `README.md:L13` |
| Fable 5.2 (`fable`) | 2.1.321 | `README.md:L13`, `CHANGELOG.md:L51` |
| Mythos 5.1 — проверен | 2.1.316 | `README.md:L13` |
| `CLAUDE_CODE_PROMPT_CACHE_TTL` | 2.1.242+ | `CHANGELOG.md:L24` |
| Квалифицированная версия | 2.1.263 | `README.md:L47` |
| Версия в CI | 2.1.292 | `evals/ci.sh:L6` |

## 5. Переменные окружения и флаги

### 5.1. Что плагин читает как настройку

| Переменная | По умолчанию | Смысл | Где читается |
|------------|--------------|-------|--------------|
| `CLAUDE_SUBSCRIPTION_DIRECTSDK_COMMAND` | `claude` | Путь к бинарнику CLI | `directsdk.py:L898`; `directsdk_setup.py:L102` |
| `CLAUDE_SUBSCRIPTION_DIRECTSDK_CONFIG_DIR` | нет | Каталог входа CLI; уступает настройке `claude_config_dir` | `directsdk_setup.py:L213-L217`, `L224` |
| `CLAUDE_CONFIG_DIR` | `~/.claude` | Каталог входа, если нет двух предыдущих | `directsdk_setup.py:L215`, `L327-L333` |
| `CLAUDE_CODE_OAUTH_TOKEN` | нет | Токен входа; CLI предпочитает его каталогу | `directsdk_setup.py:L221-L223`, `L270-L280` |
| `ANTHROPIC_CUSTOM_HEADERS` | нет | Заголовки для шлюза организации | `directsdk_setup.py:L223`, `L271-L276` |
| `CLAUDE_SUBSCRIPTION_DIRECTSDK_PROXY_AUTH` | нет | `user:password` прокси из `upstream_proxy` | `directsdk_setup.py:L645`, `L699-L702` |
| `https_proxy`, `HTTPS_PROXY`, `http_proxy`, `HTTP_PROXY` | нет | Прокси шлюза, если `upstream_proxy` не задан | `admission.py:L648-L674` |
| `NO_PROXY` | нет | Исключения прокси; через запятую или пробел | `admission.py:L651-L654` |
| `SSL_CERT_FILE`, `SSL_CERT_DIR`, `REQUESTS_CA_BUNDLE`, `CURL_CA_BUNDLE`, `NODE_EXTRA_CA_CERTS` | нет | Наличие любой выключает gzip | `admission.py:L39`, `L49` |
| `CLAUDE_CODE_GZIP_REQUEST_BODIES` | включено | `0`/`false`/`no`/`off` выключает gzip | `admission.py:L50` |
| `CLAUDE_CODE_PROMPT_CACHE_TTL` | нет | Значение пользователя не перезаписывается | `directsdk.py:L1037-L1038` |
| `DISABLE_TELEMETRY`, `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC` | нет | Выставленные пользователем не снимаются в пробах | `directsdk_setup.py:L316-L319`, `L596-L599` |
| `PATH`, `HOME`, `USERPROFILE` | — | Поиск CLI и каталога входа | `directsdk_setup.py:L66-L70`, `L104`, `L332` |
| `USER`, `USERNAME` | — | Имя пользователя для проверки входа | `directsdk.py:L649-L665` |
| `ANTHROPIC_BASE_URL` | нет | В окружении хоста — отказ; в явном `Client(env=…)` — адрес назначения | `directsdk.py:L1007`, `L1022` |
| `CLAUDE_CODE_USE_BEDROCK`, `CLAUDE_CODE_USE_VERTEX`, `CLAUDE_CODE_USE_FOUNDRY` | нет | Включённые — отказ | `directsdk.py:L1008-L1010` |
| `HERMES_AGENT_REPO` | `~/.hermes/hermes-agent` | Только тесты: где ядро Hermes | `conftest.py:L17` |
| `CLAUDE_CODE_VERSION` | `2.1.292` | Только CI: версия CLI для evals | `evals/ci.sh:L6` |

Настройки плагина лежат не в окружении, а в конфигурации профиля (`plugins.entries.<id>.settings`,
`directsdk_setup.py:L165-L181 (_setting)`) и читаются на каждый запуск:

| Настройка | По умолчанию | Смысл | Где описана и читается |
|-----------|--------------|-------|------------------------|
| `claude_config_dir` | пусто | Каталог входа профиля | `plugin.yaml:L23-L27`; `directsdk_setup.py:L214`, `L277` |
| `claude_code_telemetry` | `false` | Разрешить телеметрию CLI | `plugin.yaml:L28-L35`; `directsdk_setup.py:L184-L189` |
| `max_concurrent` | `4` | Процессов CLI одновременно | `plugin.yaml:L36-L43`; `directsdk_setup.py:L192-L208` |
| `extra_usage` | `notify` | Что делать при платном превышении | `plugin.yaml:L44-L54`; `extra_usage.py:L55-L62` |
| `extra_usage_allowed_until` | пусто | Время ISO 8601 для режима `ask` | `plugin.yaml:L55-L61`; `extra_usage.py:L157-L158` |
| `extra_usage_reserve_percent` | пусто (=100) | Ранняя остановка | `plugin.yaml:L62-L69`; `extra_usage.py:L112-L121` |
| `upstream_url` | пусто | Адрес шлюза организации | `plugin.yaml:L70-L79`; `directsdk_setup.py:L675-L685` |
| `upstream_proxy` | пусто | HTTP CONNECT прокси | `plugin.yaml:L80-L87`; `directsdk_setup.py:L688-L703` |
| `upstream_ca_file` | пусто | Дополнительный CA | `plugin.yaml:L88-L94`; `directsdk_setup.py:L706-L712` |

Параметры вызова, которые добавляет профиль: `hermes_auxiliary` и `hermes_cache_ttl`
(`__init__.py:L177-L189`, приём — `directsdk.py:L341`). Таймаут: по умолчанию 180 с (`directsdk.py:L892`, `L900`),
либо `timeout` вызова или его поле `read` (`L988-L991`).

### 5.2. Что плагин делает с окружением дочернего процесса

Основа — копия окружения хоста или явный `env` клиента (`directsdk.py:L1003`). Окружение самого хоста не меняется.

**Ставится.**

| Переменная | Значение | Где | Зачем |
|------------|----------|-----|-------|
| `ANTHROPIC_BASE_URL` | адрес шлюза | `directsdk.py:L1051` | Запросы Messages идут через шлюз |
| `ENABLE_TOOL_SEARCH` | `false` | `directsdk.py:L1032` | Без отложенного поиска инструментов |
| `CLAUDE_CODE_MAX_RETRIES` | `0` | `directsdk.py:L1032` | Повторами владеет хост |
| `DISABLE_AUTO_COMPACT`, `DISABLE_COMPACT` | `1` | `directsdk.py:L1032` | Сжатием владеет хост |
| `CLAUDE_CODE_TOTAL_TOKENS_REMINDER` | `off` | `directsdk.py:L1041` | Напоминание ломает кеш |
| `CLAUDE_CODE_DISABLE_AUTO_MEMORY`, `CLAUDE_CODE_DISABLE_CLAUDE_MDS` | `1` | `directsdk.py:L1045` | Память и CLAUDE.md не попадают в запрос |
| `CLAUDE_CODE_MAX_OUTPUT_TOKENS` | `max_tokens` | `directsdk.py:L1056-L1058` | Предел вывода |
| `CLAUDE_CODE_PROMPT_CACHE_TTL` | `5m`, если не задана | `directsdk.py:L1037-L1038` | Дочерний агент не платит за часовой кеш |
| `DISABLE_AUTOUPDATER`, `DISABLE_FEEDBACK_COMMAND`, `CLAUDE_CODE_DISABLE_TERMINAL_TITLE` | `1` | `directsdk_setup.py:L140`, `L302` | Всегда; запрос заголовка сессии состязался бы за единственный допуск |
| `CLAUDE_CODE_ENTRYPOINT` | `hermes-agent` | `directsdk_setup.py:L147`, `L302` | Имя вызывающего; перезаписывается всегда |
| `CLAUDE_CODE_DISABLE_ARTIFACT` | `1` | `directsdk_setup.py:L147`, `L302` | Без инструмента артефактов |
| `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC`, `DISABLE_TELEMETRY`, `DISABLE_ERROR_REPORTING` | `1` | `directsdk_setup.py:L129`, `L303-L304` | Если `claude_code_telemetry` выключена |
| `CLAUDE_CONFIG_DIR` | выбранный каталог | `directsdk_setup.py:L216-L217`, `L287` | Вход профиля |
| `CLAUDE_CODE_OAUTH_TOKEN`, `ANTHROPIC_CUSTOM_HEADERS` | секреты профиля | `directsdk_setup.py:L271-L274` | Вход и заголовки профиля |
| `USER`, `LOGNAME` (Windows: `USERNAME`, `USER`) | имя пользователя | `directsdk.py:L649-L665` | Проверка входа CLI |
| `SYSTEMROOT`, `SYSTEMDRIVE`, `COMSPEC`, `PATHEXT`, `TEMP`, `TMP`, `USERPROFILE`, `APPDATA`, `LOCALAPPDATA`, `PROGRAMDATA` | из хоста | `directsdk.py:L627-L637` | Только Windows |
| `HTTPS_PROXY`, `https_proxy` | прокси с учётными данными | `directsdk_setup.py:L731-L736`, `L764` | Только при `upstream_proxy` |
| `NODE_EXTRA_CA_CERTS` | путь к CA | `directsdk_setup.py:L737-L738` | Только при `upstream_ca_file` |
| `NO_PROXY`, `no_proxy` | `127.0.0.1,localhost` | `directsdk_setup.py:L765-L769` | Шлюз остаётся прямым |
| `CLAUDE_CODE_EXTRA_BODY` | тело запроса | `directsdk.py:L1054` | Через `settings.json`, не через окружение процесса |

**Удаляется.**

| Переменная | Где | Условие |
|------------|-----|---------|
| `ANTHROPIC_API_KEY`, `ANTHROPIC_AUTH_TOKEN`, `ANTHROPIC_FOUNDRY_API_KEY` | `directsdk_setup.py:L156-L162`; `directsdk.py:L1004-L1006` | Окружение хоста; явный `env` сохраняет |
| `CLAUDE_CODE_EXTRA_BODY`, `CLAUDE_CODE_EFFORT_LEVEL` | `directsdk.py:L1030-L1031` | Всегда |
| `CLAUDE_SUBSCRIPTION_DIRECTSDK_CONFIG_DIR` | `directsdk_setup.py:L213` | Всегда (превращается в `CLAUDE_CONFIG_DIR`) |
| `CLAUDE_CODE_OAUTH_TOKEN` | `directsdk_setup.py:L278-L280` | Задан `claude_config_dir`, а своего токена у профиля нет |
| `CLAUDE_CODE_OAUTH_TOKEN`, `ANTHROPIC_CUSTOM_HEADERS` | `directsdk_setup.py:L275-L276` | Профиль обслуживается чужим процессом и своих значений нет |
| `CLAUDE_CONFIG_DIR` | `directsdk_setup.py:L288-L289` | Такой профиль со своим токеном, но без своего каталога |
| унаследованные `NO_PROXY`, `no_proxy` | `directsdk_setup.py:L766-L767` | При `upstream_proxy` |
| `DISABLE_TELEMETRY`, `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC` | `directsdk_setup.py:L316-L319` | Только проба списка моделей, если не выставлены пользователем |
| `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC` | `directsdk_setup.py:L596-L599` | Только проба `get_usage`, если не выставлена пользователем |

**Вызывает отказ до запуска** (`RequestRejected`, только для окружения хоста): `ANTHROPIC_BASE_URL`;
`CLAUDE_CODE_USE_BEDROCK`, `CLAUDE_CODE_USE_VERTEX`, `CLAUDE_CODE_USE_FOUNDRY` со значением не из
`''`, `0`, `false`, `no`, `off` — `directsdk.py:L1007-L1010`. Профиль без собственного входа —
`ClaudeCodeLoggedOut` (`directsdk_setup.py:L290-L291`); нет доступа к секретам профиля — `UnscopedSecretError`
(`L282-L283`).

## 6. Шлюз допуска подробно

**Создание** (`admission.py:L703-L758 (Admission.__init__)`). Адрес назначения обязан быть `https://`, либо `http://`
для loopback, без логина, запроса и фрагмента (`L707-L715`). Слушатель — `HTTPServer` на `('127.0.0.1', 0)` с
`server.timeout = 0` (`L735-L739`); адрес для CLI — `http://127.0.0.1:<порт>/admit/<32 случайных байта в
base64url>` (`L734`, `L740`). Сервер однопоточный: цикл `_serve` (`L760-L766`) ждёт на селекторе слушателя и
пары сокетов для пробуждения и вызывает `handle_request()` по одному соединению. Привязка не делает обратного
DNS-запроса (`L695-L700 (_Server.server_bind)`).

**Состояние** (`admission.py:L719-L733`): `used`, `denied`, `auth_retry_used`, `cancelled`, `eager`, `status`,
`request_id`, `reset_at`, `failure`, `unrestored`, `error_body`, `capture`, множество открытых сокетов.

**Автомат** (`admission.py:L880-L897`):

| Состояние | Событие | Действие |
|-----------|---------|----------|
| любое | метод не POST | 501 от стандартного обработчика; обрыв соединения клиентом не печатает трассу (`L677-L693`) |
| любое | POST не на `<prefix>/v1/messages` или с заголовком `Origin` | 404 (`L884-L886`) |
| `cancelled` | POST | `denied += 1`, ответ 400 (`L888-L896`) |
| новое (`used == False`) | первый POST | `used = True`, запрос уходит наружу (`L897`) |
| `used`, первая попытка — 401 с `error.type == 'authentication_error'`, повтор не тратился | POST | `auth_retry_used = True`, `error_body` очищается, запрос уходит наружу (`L857-L878`) |
| `used`, иначе | POST | `denied += 1`, ответ 400 с телом `{"type":"error","error":{"type":"invalid_request_error","message":"HERMES_MODEL_ADMISSION_CONSUMED"}}` (`L889-L896`) |

Второй ответ 401, любой другой статус и любая другая форма тела повтора не дают (`L861-L865`).

**Что пересылается.** Путь наружу — путь адреса назначения + `/v1/messages` + строка запроса от CLI (`L923`).
Тело — после правок раздела 4.3. Из заголовков CLI убираются `host`, `connection`, `content-length`,
`transfer-encoding`, `proxy-authorization`, `proxy-connection`, `accept-encoding`; добавляется
`Accept-Encoding: identity` (`L920-L922`). Остальные заголовки, включая авторизацию и заголовки идентичности CLI,
идут как есть. Журнал запросов выключен (`L829-L830 (log_message)`); после отправки заголовки и тело удаляются из
памяти (`L956`).

**gzip.** Условия (`L46-L51`): хост `api.anthropic.com`, порт по умолчанию или 443, без прокси, в окружении нет
переменных CA, сжатие не выключено, тело ≥ 4096 байт, защёлка не стоит, CLI сам не поставил `Content-Encoding`.
К телу дописывается перевод строки и от 0 до 256 случайных пробелов и табуляций, сжатие уровня 3 с `mtime=0`
(`L927-L928`). При ответе 400, 403 или 415 читается до 65536 байт; если причина — кодирование или «невалидный JSON»
на стороне сервера, либо ответ не от Anthropic (нет `request-id` вида `req_…` и нет `cf-ray`), защёлка
ставится на весь процесс и запрос один раз уходит без сжатия (`L54-L83`, `L932-L955`).

**Ответ.** Статус, `request-id` (или `x-request-id`) и время сброса окна записываются до передачи тела
(`L957-L959`). Заголовки ответа пересылаются, кроме `connection`, `transfer-encoding`, `server`, `date`; добавляется
`Connection: close` (`L960-L965`). Тело читается кусками до 65536 байт и сразу пишется CLI (`L969-L982`). При
статусе 200 каждый кусок идёт в `Capture`; при другом — в `error_body`, не больше 65536 байт (`L977-L980`).

**Захват** (`admission.py:L566-L637 (Capture)`). Разбор SSE: кадры по пустой строке, строки `data:` (`L577-L583`).
`message_start` даёт основу сообщения (`L597-L598`); блоки и дельты `text_delta`, `thinking_delta`,
`signature_delta`, `input_json_delta`, `citations_delta` собираются в `content` (`L600-L613`); на
`content_block_stop` аргументы вызова разбираются как JSON, пустые дают `{}`, неразобранные запоминаются
(`L615-L628`); `message_delta` обновляет поля сообщения (в том числе `stop_reason`, `stop_details`) и `usage`
(`L630-L632`); на `message_stop` неразобранные вызовы удаляются из сообщения, `complete` истинно при наличии
`stop_reason` и отсутствии незакрытых аргументов (`L634-L637`).

**Сбой.** Исключения `OSError`, `HTTPException`, `ValueError`, `KeyError`, `IndexError`, `TypeError` не
пробрасываются: имя класса пишется в `failure` (`L983-L984`), соединение закрывается (`L985-L990`). Транспорт
потом сообщает «Incomplete upstream response» (`directsdk.py:L1227-L1239`).

**Таймауты.** Сокет клиента и соединение наружу — таймаут запроса (`admission.py:L813`, `L837-L842`, `L901`).
После заголовков ответа тишина дольше `min(timeout, 180)` секунд даёт `UpstreamIdle` (`L30-L34`, `L966-L974`).
На сокете наружу включён keepalive: 15 с простоя, интервал 5 с, 3 пробы (`L35-L36`, `L105-L115`).

**Отмена и закрытие.** `abort()` ставит `cancelled` и делает `shutdown` всех сокетов; на Windows сокет ещё и
закрывается, иначе заблокированное чтение не просыпается (`L122-L125`, `L777-L792`). `close()` — это `abort()`,
байт в пару пробуждения, ожидание потока, закрытие слушателя (`L794-L803`). Соединение, принятое после отмены,
сразу закрывается (`L807-L820`).

**Использование вне хода.** Тот же шлюз ставится перед пробой `get_usage` — он остаётся неиспользованным
(`directsdk_setup.py:L600-L603`); перед рукопожатием `initialize` — только при шлюзе организации или
переопределении бэкенда, иначе CLI говорит с Anthropic напрямую (`L476-L485`). Если проба списка моделей всё же
отправила запрос через шлюз, результат отбрасывается (`L500`, `L506-L507`).

## 7. Замеры из README, CHANGELOG, AGENTS

В `AGENTS.md` собственных замеров нет; оттуда взято только правило окна 200K (`AGENTS.md:L11-L12`).

| Что измерено | Значение | Где сказано |
|--------------|----------|-------------|
| Доля чтения из кеша до правки (Sonnet 5, второй запрос) | 6 989 из 190 840 входных токенов = 3,66 %; 183 849 токенов переписаны в часовой кеш | `README.md:L163` |
| Первый запрос той же задачи | 187 049 входных токенов | `README.md:L163` |
| Проверка одинаковыми запросами | прошла на 179K токенов, границу проигрывания не затронула | `README.md:L163` |
| Доля чтения из кеша после отключения напоминания | 97,99 % в последующих запросах; 85,45 % с холодным стартом | `README.md:L165` |
| Та же задача: объём | 7 вызовов, 10 исполнений инструментов, контекст 187K → 212K, $1,1030034 по прайсу | `README.md:L165` |
| Интегрированная проверка | 7 вызовов = 7 запросов наружу, 12 исполнений инструментов; контекст 138 498 → 165 697; чтение из кеша 97,13 %; $0,8793728 | `README.md:L43` |
| Расход 5-часового окна по имени вызывающего (Max, Opus 5.5, CLI 2.1.316, 31.10.2026) | `sdk-cli`: +6 % и +6 % = 12 %; `hermes-agent`: +4 % и +3 % = 7 % за $2,19; 4 запроса по ~43K токенов, $1,09 на замер | `README.md:L217-L222` |
| Итог по имени | примерно в 1,7 раза меньше окна; `sdk-cli` тянет окно примерно в 2 раза быстрее | `README.md:L217`, `L224`; `CHANGELOG.md:L66` |
| Расход по режимам (Pro, Sonnet 5 1M, 09.09.2026, ~500K токенов на замер) | `claude -p`: 8,9 и 8,8 пункта на $; плагин: 8,9; интерактивный TUI: 5,4 и 4,9 | `README.md:L228-L234` |
| TUI относительно `-p` | ~0,58x; ≈ $6,5 против ≈ $11 на окно | `README.md:L236` |
| Объём токенов Hermes против Claude Code | ~0,6x на тех же задачах | `README.md:L236` |
| Повтор замера (Max, Opus 5.5, CLI 2.1.299, 14–15.10.2026) | плагин $2,70 на шаг 0,01; `claude -p` $2,87 и $2,44; TUI ~$6,65 (≈ 2,3 раза медленнее) | `README.md:L238` |
| Память на процесс CLI | около 250 МБ | `README.md:L111`; `CHANGELOG.md:L8`; `plugin.yaml:L41` |
| Старт помощника MCP | ~0,6 с на вызов | `README.md:L69-L71`; `CHANGELOG.md:L57`; `directsdk.py:L996-L997` |
| Телеметрия CLI | +0,25 с на запуск | `README.md:L103`; `CHANGELOG.md:L16` |
| Быстрый возврат | ~0,6 с на запрос | `CHANGELOG.md:L78` |
| `claude auth status` | ~0,3 с | `README.md:L206` |
| Проба `get_usage` | 0,5–0,9 с | `README.md:L124` |
| Список моделей при выключенном получении флагов (2.1.319) | 13 строк → 5 | `README.md:L103`; `CHANGELOG.md:L16`; `directsdk_setup.py:L130-L131` |
| Потоки на проигрывание истории | 602 запуска на 300 раундов до правки | `CHANGELOG.md:L15`; `directsdk.py:L707` |
| CPU плагина на вызов | −45 % на 100 и 300 раундах; 0,42 → 0,23 с на 300 раундах | `CHANGELOG.md:L15` |
| Закрытие шлюза после простоя | 22,5–22,8 мс → 0,29–0,45 мс (медиана) | `CHANGELOG.md:L31-L32` |
| Закрытие шлюза сразу после старта | 50,2–50,3 мс → 0,03 мс | `CHANGELOG.md:L32` |
| Шаг цикла при `max_concurrent` 1 | быстрее на 17–36 мс | `CHANGELOG.md:L32-L33` |
| Обратный DNS при привязке | 35 с ожидания на одном хосте macOS | `CHANGELOG.md:L25-L27`; `admission.py:L696-L698` |
| Тишина при вызове без потоковых аргументов | 89,8 с без чанка (Opus 5.5) | `CHANGELOG.md:L69` |
| Текст после результата инструмента | все дельты одним чтением, ~6 с | `README.md:L185` |
| Рассуждение при невозможном выключении | сотни–~1K токенов, 2x задержки | `directsdk.py:L383-L384` |
| Цена записи в кеш | час — 2x входной цены, 5 минут — 1,25x | `README.md:L213`; `CHANGELOG.md:L24` |
| Большой контекст (CLI 2.1.258) | 902 783 входных токена приняты, один запрос, $3,611238 | `README.md:L264` |
| Проверка Opus 5 и 4.8 | окно 1 000 000, один запрос, $0,001485 каждый | `README.md:L264` |
| Сжатие силами хоста | окно 200K, порог 0,75; сжатие после 154 038 токенов; затем 144 568; 11 запросов; $1,221155 | `README.md:L268` |
| Размер запроса через файлы | 182K схем инструментов и 176K системного промпта | `README.md:L285` |
| Окно поиска кеша и число точек | 20 блоков; 4 точки; CLI 2.1.285 ставит 3 | `README.md:L157`; `admission.py:L26-L29` |
| Таймаут тишины CLI | 180 с; за шлюзом CLI расширяет свой до 300 с | `admission.py:L30-L34`; `README.md:L187` |
| Таймаут запроса Hermes | 1800 с по умолчанию (Hermes 0.21.5) | `README.md:L193` |
| Ожидание в очереди до предупреждения | 2 с | `README.md:L111`; `concurrency.py:L24` |
| Слепая пауза хоста без времени сброса | 60 с → 4 ч | `CHANGELOG.md:L61` |

Авторы оговаривают: суммы — эквивалент по прайсу, а не счёт подписки (`README.md:L58`, `L270`); вес имени
вызывающего Anthropic не документирует (`README.md:L224`); замер TUI на плане Max опирается примерно на один шаг
счётчика (`README.md:L238`).

## 8. Тесты и проверки

**Запуск.** `python -m pytest -q` с `HERMES_AGENT_REPO` на клон hermes-agent (`AGENTS.md:L79-L83`,
`README.md:L274-L279`): тесты импортируют ядро Hermes (`conftest.py:L17-L22`). CI гоняет набор на Ubuntu, macOS и
Windows (`AGENTS.md:L85`). В `tests/` 57 файлов, 341 функция `test_`.

**Как обходятся без входа и платных вызовов.**

- Клиент принимает явные `command` и `env` (`directsdk.py:L892-L899`), поэтому вместо CLI подставляется скрипт
  Python, а вместо Anthropic — локальный `ThreadingHTTPServer`, адрес которого передан в `ANTHROPIC_BASE_URL`
  явного окружения (`tests/test_directsdk_admission.py:L18-L35`, `L57-L60`).
- Поддельный CLI в тестах шлюза читает кадры, отвечает `result` с `num_turns: 0` на `shouldQuery: false`, делает
  два POST на `/v1/messages` и печатает события (`tests/test_directsdk_admission.py:L18-L35`); второй POST должен
  получить отказ, а собеседник — увидеть один запрос (`L63`).
- `tests/fixtures/lifecycle_cli.py` (53 строки): записывает PID и группу, умеет режимы запускающего процесса,
  который выходит раньше потомка (`launcher_exit`, `launcher_live`), не читающего stdin (`no_read`), отвечает на
  `--version` и `auth status` (`L10-L53`).
- `tests/fixtures/progress_cli.py` (52 строки): режимы `silent`, `tool_args`, `pings`, текст — для проверки
  «живости» (`L30-L52`).
- Фикстура `profile` ставит плагин во временный `HERMES_HOME` и указывает несуществующий бинарник, чтобы
  установленный CLI не просочился в тесты (`conftest.py:L28-L49`); `popen_from_run` подменяет `Popen` для проб
  (`L52-L80`).

**Что покрыто** (по именам тестов; полностью прочитаны только указанные в разделе 10).

| Группа файлов | Что проверяет |
|---------------|---------------|
| `test_directsdk_admission.py` (21) | Один запрос наружу, usage первого ответа, повтор после 401, отказ модели, только рассуждение, отмена закрывает сокет, оборванный поток и идентификатор запроса, тишина сервера, ping |
| `test_admission_breakpoint.py`, `test_admission_two_marks.py`, `test_admission_turn_start.py` | Перенос точки кеша, две метки CLI, дополнительная метка после длинного цикла |
| `test_admission_first_turn.py`, `test_admission_stable_context.py` | Первый ход без контекста CLI; строка даты и напоминания |
| `test_admission_restoration.py` (21) | Восстановление кадра результатов: эквивалентные формы, `is_error`, вложенные блоки, метки |
| `test_admission_gzip.py` (15) | Условия сжатия, набивка, пересылка без сжатия, защёлка, отмена между попытками |
| `test_admission_one_shot.py`, `test_admission_tool_streaming.py` | Снятие меток у одноразового вызова; `eager_input_streaming` |
| `test_admission_proxy.py`, `test_directsdk_gateway.py` | Прокси из окружения, `NO_PROXY`, шлюз организации, CA, отказ при плохой настройке |
| `test_admission_bind.py`, `test_admission_close.py`, `test_admission_broken_pipe.py` | Привязка без DNS, мгновенное закрытие, обрыв разогревочного запроса |
| `test_directsdk.py`, `test_directsdk_replay.py`, `test_directsdk_replay_ack.py`, `test_directsdk_foreign_tool_ids.py` | Полный цикл запроса, проигрывание истории, подтверждения, чужие идентификаторы |
| `test_directsdk_tool_names.py`, `test_directsdk_tool_stream.py`, `test_directsdk_structured_output.py`, `test_directsdk_headers.py` | Псевдонимы имён, поток вызовов, чистка схем, заголовки |
| `test_directsdk_models.py`, `test_vendor_prefixed_route.py`, `test_directsdk_reasoning_off.py`, `test_directsdk_vision.py` | Каталог и окна, argv, выключение рассуждения, изображения |
| `test_directsdk_fast_return.py`, `test_lifecycle_regressions.py` (19), `test_client_close_parallel.py`, `test_native_progress_liveness.py` | Быстрый возврат, отмена, сторож stdin, дерево процессов, «живость» |
| `test_directsdk_concurrency.py`, `test_concurrency_deadline.py` | Очередь, отмена в ожидании, место после падения процесса |
| `test_directsdk_extra_usage.py`, `test_extra_usage_reserve.py`, `test_directsdk_rate_limit_event.py`, `test_directsdk_rate_limit_reset.py`, `test_directsdk_account_usage.py` | Политика превышения, резерв, событие лимита, время сброса, `/usage` |
| `test_directsdk_setup.py` (16), `test_cli_version_resolution.py`, `test_directsdk_discovery.py`, `test_directsdk_probe_lifecycle.py` | Поиск CLI, версия, вход, список моделей, пробы |
| `test_directsdk_config_dir.py`, `test_directsdk_profile_credential.py`, `test_directsdk_inherited_api_key.py`, `test_directsdk_identity.py` | Каталог входа, токен профиля, API-ключи, имя пользователя |
| `test_directsdk_telemetry.py`, `test_directsdk_native_memory.py`, `test_directsdk_cache_ttl.py`, `test_directsdk_session_id.py`, `test_directsdk_workspace.py`, `test_directsdk_mcp_skip.py` | Флаги окружения, память CLI, TTL, сессия, рабочий каталог, пропуск MCP |
| `test_directsdk_errors.py`, `test_transport_phrase.py` | Статусы ошибок и формулировки |
| `test_frozen_copy_removed.py`, `test_sysmodules_eviction.py`, `test_host_streaming.py` | Особенности загрузки плагина в Hermes |

**Evals** — настоящий бинарник CLI против синтетического собеседника, без учётных данных и без запросов к модели
(`evals/directsdk_admission.py:L1-L4`, `evals/ci.sh:L2-L3`).

- `directsdk_admission.py`: 13 режимов — `final`, `tools`, `max`, `tool_max`, `context`, `thinking`, `refusal`,
  `error`, `window`, `disconnect`, `cancel`, `unknown_tool`, `bad_tool` (`L102`). Окружение — пустой домашний
  каталог, фиктивный `ANTHROPIC_API_KEY`, адрес собеседника (`L110-L112`). Проверяется ровно один запрос наружу
  на вызов (`L163`), usage из первого ответа (`L136`), время сброса только при отклонённом окне (`L128-L131`),
  отмена быстрее 3 с с закрытием соединения (`L160-L162`), неизменность бинарника по sha256 (`L100`, `L172`).
- `directsdk_cache_wire.py`: неизменность кешируемого префикса между запросами и метка после цикла длиннее окна
  поиска; расписание — `[True, True, False, False] + [True] * 14 + [False, True]` (`L1-L9`, `L30-L32`).
- `directsdk_relay_close.py`: цена закрытия шлюза (`L1-L15`).
- `ci.sh`: ставит `@anthropic-ai/claude-code@$CLAUDE_CODE_VERSION` из npm во временный каталог и запускает два
  первых eval (`L6-L19`).

Авторы подчёркивают, что ответы в этих проверках — синтетические, а не свидетельство работы платной модели
(`README.md:L283-L285`).

## 9. Ограничения и риски

**Названо авторами.**

- Сборка для обзора, не заявка на полную совместимость и готовность к эксплуатации (`README.md:L45`).
- Подтверждения проигрывания и поведение extra body зависят от версии CLI; это не публичная гарантия SDK
  (`README.md:L47`). CLI 2.1.263 пытается сделать лишние запросы вопреки `--max-turns 1` (`README.md:L41`).
- Запрос на проводе не побайтно равен контексту хоста: остальные добавки CLI остаются (`README.md:L155`).
- Синтетические тесты подтверждают неизменность префикса, а не попадания в кеш у поставщика (`README.md:L157`).
- Не поддержаны: префилл ассистента, strict-режим функций, принудительный выбор инструмента,
  `parallel_tool_calls=False`, `n>1`, режим JSON без схемы, произвольные заголовки и поля, удалённые изображения,
  подписанная история между моделями (`README.md:L187`).
- Текст после результата инструмента приходит поздно и разом; задержка на стороне сервера (`README.md:L185`).
- Зависший вызов без вывода держит ход весь таймаут чтения, пока не настроен отдельный таймаут (`README.md:L193`).
- Запрос, пересекающий лимит плана, уже оплачен; политика действует со следующего (`README.md:L124`).
- Вес имени вызывающего не документирован и может измениться (`README.md:L224`).
- Стоимость по прайсу — не счёт подписки; сверки с превышением нет (`README.md:L270`, `L287`).
- Fable 5.1 на проверочном аккаунте требовал кредиты и платно не проверялся (`README.md:L165`, `L264`).
- Не закрыто: более широкие преобразования истории, другие версии и платформы CLI (`README.md:L287`).
- Настройка, пересылающая токен подписки на сторонний хост, — изменение политики, а не функция
  (`AGENTS.md:L97-L99`).

**Наблюдения.**

- Наблюдение: поведение держится на недокументированных в прочитанных файлах рычагах CLI —
  `CLAUDE_CODE_EXTRA_BODY`, `CLAUDE_CODE_ENTRYPOINT`, `CLAUDE_CODE_TOTAL_TOKENS_REMINDER`,
  `CLAUDE_CODE_DISABLE_CLAUDE_MDS`, поле `shouldQuery`, управляющие запросы `initialize` и `get_usage`. В коде много
  привязок к конкретным версиям (`admission.py:L183-L187`, `L239-L243`; `README.md:L157`). Обновление CLI может
  сломать любой из них; авторы закрывают это закреплённой версией в CI (`README.md:L281`).
- Наблюдение: смена `CLAUDE_CODE_ENTRYPOINT` ради меньшего расхода окна — решение с неясными последствиями для
  условий подписки; в прочитанных файлах этот вопрос не обсуждается.
- Наблюдение: на каждый вызов модели — новый процесс (~250 МБ) и новый шлюз; история проигрывается заново, с
  ожиданием подтверждения на каждый исторический пользовательский кадр (`directsdk.py:L1148-L1161`).
- Наблюдение: шлюз однопоточный (`admission.py:L760-L766`), поэтому повторный запрос CLI получает отказ только
  после того, как обработчик первого запроса завершился.
- Наблюдение: тайна адреса шлюза — случайный путь на loopback; шифрования между CLI и шлюзом нет, токен подписки
  идёт по `http://127.0.0.1` открыто (`admission.py:L734-L740`).
- Наблюдение: идентификатор сессии выводится из системного промпта и первого кадра
  (`directsdk.py:L184-L191`); два разговора с одинаковым началом получают один идентификатор. При
  `--no-session-persistence` последствия в коде не видны.
- Наблюдение: рабочий каталог общий для всех разговоров пользователя ОС, а не отдельный на разговор
  (`directsdk.py:L834-L855`).
- Наблюдение: помощник MCP — скрипт Python, запускаемый интерпретатором хоста (`sys.executable`,
  `directsdk.py:L1002`).
- Наблюдение: транспорт зависит от функций ядра Hermes, которых нет в плагине:
  `tools.schema_sanitizer.strip_nullable_unions` (`directsdk.py:L294-L295`),
  `agent.reasoning_effort.clamp_effort` (`L373-L374`), `hermes_cli.config.load_config_readonly`
  (`directsdk_setup.py:L173`), `agent.secret_scope` (`L232`).
- Наблюдение: состояние политики превышения, защёлка gzip и список разговоров с отключённым `eager` живут в
  памяти процесса и пропадают при перезапуске (`extra_usage.py:L32-L34`; `admission.py:L38`;
  `directsdk.py:L503-L510`).
- Наблюдение: ответ хосту отдаётся в форме OpenAI Chat Completions; сообщение в формате Anthropic Messages лежит в
  `Capture.message` и в носителе (`admission.py:L597-L637`; `directsdk.py:L1293`).
- Наблюдение: `temperature` и `top_p` проверяются и молча отбрасываются (`directsdk.py:L414-L419`).
- Наблюдение: общий каталог недоступен без `os.getuid` (Windows) — там каталог один на процесс
  (`directsdk.py:L842-L843`).

## 10. Что не прочитано

- **Тесты.** Полностью прочитаны `conftest.py`, оба файла `tests/fixtures/` и первые 145 строк
  `tests/test_directsdk_admission.py`. По остальным 56 файлам `tests/` просмотрены только имена тестов (у файлов с
  большим числом тестов — первые шесть имён, кроме `test_directsdk_admission.py`, `test_lifecycle_regressions.py`
  и `test_admission_gzip.py`, где имена просмотрены все). Тела тестов не читались, поэтому раздел 8 описывает
  покрытие по именам.
- **Evals.** `evals/directsdk_admission.py` прочитан целиком, но длинные строки были обрезаны на 230 символах;
  `evals/directsdk_cache_wire.py` — первые 70 строк из 240; `evals/directsdk_relay_close.py` — первые 45 строк из
  123.
- **Не открывались:** `LICENSE`, `assets/*.png`, `../.github/workflows/tests.yml` (просмотрены только строки с
  матрицей ОС и вызовом `evals/ci.sh`), корневые `README.md` и `CONTRIBUTING.md` репозитория плагинов.
- **Ядро Hermes.** Функции, которые плагин импортирует из hermes-agent (раздел 9), и встречная часть в ядре
  (профили внешних процессов, классификатор ошибок, `prompt_cache_scope`) не читались.
- **Внешние ссылки.** Документация Anthropic и Claude Code, а также issues, на которые ссылаются README и
  CHANGELOG, не открывались; утверждения о поведении CLI и сервера приведены со слов авторов.
- **Коммит.** Значение `269ddcb` взято из задания; `git submodule status` не запускался.
- Назначение флагов CLI в разделе 4.1, для которых не указана ссылка на README или код, описано по их именам.

## 11. Что берёт Меркурий и от чего отступает

Решения владельца 2026-11-23; требования — в спецификации Меркурия, здесь только отношение к образцу.

**Берём:**

- способ целиком: неизменённый официальный Claude Code как одноразовый клиент одного вызова модели; цикл агента,
  инструменты, подтверждения и сжатие остаются у хоста (раздел 3);
- шлюз допуска «один вызов хоста — один запрос к API» и захват настоящего ответа из шлюза (раздел 6);
- инертный MCP, псевдонимы длинных имён инструментов и обратное отображение, нормализацию схем (раздел 4.2);
- проигрывание истории кадрами stream-json (раздел 3);
- окружение дочернего процесса: выброс ключей API, отказ при унаследованных адресах и облачных переключателях,
  выключатели CLI (раздел 5.2);
- своё имя вызывающего вместо `sdk-cli` (раздел 7 — замер расхода окна);
- предел одновременных процессов с очередью по порядку прихода, лимиты плана и политику доплаты `allow` / `notify` /
  `stop` с порогом в процентах;
- ошибки со статусами HTTP, обработку отказа модели, отмену с завершением дерева процессов, быстрый возврат;
- починку запроса в шлюзе ради кеша промпта — отдельным этапом, вместе с тестами `test_admission_*`;
- проверку против настоящего CLI на подставном API, без входа и без платных вызовов (раздел 8).

**Отступаем:**

- **Отдельный сервис, а не плагин в процессе агента.** У Меркурия тяжёлое и необязательное живёт модулем в своём
  контейнере; сервис отвечает приложению тем же Messages API, что и Anthropic, — в приложении новый клиент не нужен.
- **PHP и AMPHP вместо Python с потоками.** Шлюз — асинхронный HTTP-сервер, а не однопоточный `HTTPServer`: отказ на
  повторный запрос CLI не ждёт конца первого. Инертный MCP — скрипт на PHP.
- **Сеть — один маршрут на оба плеча,** через VLESS, HTTP CONNECT или SOCKS5; у образца — только HTTP-прокси и свой
  корневой сертификат (`egress_env`). Для самого CLI сервис поднимает на loopback CONNECT-прокси на том же маршруте.
- **Режима доплаты `ask` нет:** его роль играет переход на другую модель из списка той же категории.
- **Вход ведёт сервис по команде владельца в чате,** а токен `claude setup-token` приходит от приложения с запросом и
  в сервисе не хранится; у образца вход делает человек в терминале.
- **Рабочий каталог.** У образца он один на пользователя ОС, а не на беседу (раздел 4.3); на беседу приходится
  только идентификатор сессии. В контейнере с одним пользователем это один каталог на сервис.
- **Не берём** прямые запросы с токеном подписки под видом Claude Code — так делает ядро hermes-agent
  ([конспект](hermes-agent.md), режим OAuth адаптера Anthropic).
