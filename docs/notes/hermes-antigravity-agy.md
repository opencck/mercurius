---
title: "hermes-plugin-antigravity-agy — Antigravity по подписке: один процесс agy на вызов модели"
type: repository
source: https://github.com/Realtyxxx/hermes-plugin-antigravity-agy
local: references/hermes-plugin-antigravity-agy/
commit: ec07f86
version: 0.1.0
reviewed: 2026-11-26
---

# hermes-plugin-antigravity-agy — Antigravity по подписке: один процесс agy на вызов модели

> **Репозиторий автором не поддерживается** (пометка с 2026-09-28): он заархивирован и доступен только для чтения,
> автор советует вместо него `antigravity-subscription-directsdk` (`README.md:L8-L10`). Все цифры и все наблюдения за
> поведением CLI относятся к `agy` 1.2.10–1.2.11 и Hermes Agent v0.21.4–v0.21.5 на Linux (`docs/design.md:L5`,
> `docs/perf.md:L3`). На свежей версии `agy` каждое из них нужно перепроверить — список в разделе 14.

Формат ссылок на код в этом конспекте: `<файл>:L<от>-L<до> (имя)` — это сокращение от
`references/hermes-plugin-antigravity-agy/<файл>@ec07f86:L<от>-L<до> (имя)`.
В коде Меркурия ссылка пишется полностью, с префиксом `ref:`. Номера строк сверены с файлами коммита `ec07f86`.
Слова автора: «relay agent» — свой агент `hermes-relay` без встроенных инструментов; «inert MCP» — инертный
MCP-сервер; «spare», «pre-warmed» — заранее запущенный процесс `agy`, ещё не получивший запроса; «host» — Hermes.
Что не удалось подтвердить по коду — в разделе 16.

## 1. Кратко

Плагин — провайдер моделей для Hermes Agent (`kind: model-provider`, версия `0.1.0`, `plugin.yaml:L1-L3`): модели
подписки Google Antigravity через официальный, уже вошедший в аккаунт CLI `agy`. Главное правило: **`agy` — только
модель, все инструменты исполняет хост** (`docs/design.md:L9`). На каждый запрос chat-completions запускается один
процесс `agy` в режиме печати, который получает ровно одно сообщение (`docs/design.md:L11`, `transport.py:L1-L14`).

Конструкция из четырёх частей:

1. **Свой агент** в личном временном каталоге: `.agents/agents/hermes-relay/agent.md` с
   `excludeDefaultComponents: true` — у модели нет встроенных инструментов `agy` и его стандартного промпта
   (`transport.py:L248-L257`).
2. **Инертный MCP-сервер** `inert_mcp.py`: перечисляет инструменты хоста и никогда не отвечает на вызов
   (`inert_mcp.py:L1-L5`).
3. **История одним текстом:** системный промпт хоста и каталог инструментов — тело агента; вся история сворачивается
   в одно пользовательское сообщение stream-json в stdin (`transport.py:L134-L186`, `L540-L544`).
4. **Перехват вызова в потоке:** первый шаг `call_mcp_tool` к серверу `hermes` завершает прогон — группа процессов
   убивается, вызов возвращается хосту как `tool_calls`; любой другой шаг инструмента — ошибка
   (`transport.py:L908-L928`).

Плагин не читает, не копирует и не связывает ссылками файлы входа `agy`: вход остаётся внутри CLI
(`README.md:L16`, `agy_setup.py:L3-L6`). Работает только на Linux и macOS: процесс ведётся как группа процессов POSIX
(`README.md:L33`, `plugin.yaml:L11`).

**Главный недостаток, из-за которого автор бросил плагин:** `agy` молча обрезает одно пользовательское сообщение
примерно после 65–69 тыс. токенов, оставляя начало. История, свёрнутая в одно сообщение, в длинной беседе теряет
самые новые ходы, и модель может зациклиться (`README.md:L10`). В `docs/design.md:L160` то же названо непроверенным —
предупреждение в README новее.

## 2. Карта файлов

| Файл | Строк | Что внутри |
|------|------:|-----------|
| `README.md`, `README.zh-CN.md` | 170, 167 | Предупреждение о снятии с поддержки, требования, настройки, сравнение с `antigravity-subscription-directsdk`, ограничения |
| `docs/design.md` | 161 | Устройство, замеры за каждым решением, известные ограничения |
| `docs/perf.md` | 95 | Замеры скорости, принятые и отвергнутые приёмы, кеш промпта |
| `docs/install.md` | 155 | Готовый промпт для установки агентом (англ. и кит.) |
| `plugin.yaml` | 22 | Манифест: имя, версия, `optional_env`, `external_dependencies` |
| `__init__.py` | 84 | Профиль провайдера для ядра Hermes (`auth_type='external_process'`, `api_mode='chat_completions'`) |
| `transport.py` | 1000 | Путь запроса: агент, свёртка истории, разбор NDJSON, перехват вызова, пул прогрева, ошибки |
| `inert_mcp.py` | 44 | Инертный MCP-сервер stdio |
| `agy_setup.py` | 83 | Пробы без расхода квоты: `agy models`, `agy -p=/quota` |
| `model_catalog.py` | 75 | Запасной список моделей, псевдонимы, окна контекста по семействам |
| `scripts/audit_trace.py` | 46 | Проверка трасс: все ли шаги инструментов — вызовы хоста |
| `scripts/bench.py` | 270 | Сквозные замеры на настоящих `hermes chat` и `agy` (тратит квоту) |
| `tests/fake_agy.py` | 195 | Поддельный `agy`: сценарии ответа, вызова, нарушения, ошибок |
| `tests/test_transport.py` | 305 | Запрос, агент, вызовы, нарушения, ошибки, свёртка истории |
| `tests/test_perf_prewarm.py` | 324 | Пул прогрева, фоновое завершение, уборка записей бесед |
| `tests/test_inert_mcp.py`, `test_setup_and_profile.py`, `test_model_switch.py` | 39, 78, 104 | MCP-сервер, пробы и профиль, команда `/model` |

Зависимостей нет: только стандартная библиотека Python и ядро Hermes (`pyproject.toml:L8-L9`).

## 3. Конструкция (`docs/design.md` §1–4)

### 3.1. Один вызов модели, по шагам

`Client._create` → `Client._run` (`transport.py:L810-L985`):

1. Проверка `model`, `n == 1`; сбор манифеста инструментов и свёртка истории (`L811-L817`).
2. Поиск бинарника (`resolve_agy`), иначе `AgyMissing` (`L844-L846`).
3. Ключ формы запроса и попытка взять прогретый процесс; нет — запуск нового (`L847-L858`).
4. Запись единственного кадра в stdin и закрытие stdin (`L862`, `L540-L544`).
5. Если есть инструменты — запуск запасных процессов той же формы (`L863-L866`).
6. Чтение событий из очереди; таймаут — на тишину, а не на весь запрос: срок сдвигается после каждого события
   (`L869-L884`, по умолчанию 600 с — `L764`, `L772`).
7. `init` — проверка агента; `step_update` — текст или инструмент; `result` — конец (`L891-L931`).
8. Сборка ответа в форме OpenAI: `finish_reason` — `tool_calls` или `stop`, usage (`L959-L967`).
9. Уборка процесса: при обычном ответе — в фоне с ожиданием до 15 с, иначе — немедленное убийство (`L970-L981`).

### 3.2. Свой агент

`agy` поддерживает агентов в `.agents/agents/<имя>/agent.md` внутри рабочего каталога: YAML-заголовок и тело Markdown,
которое становится системным промптом; агент выбирается флагом `--agent <имя>` (`docs/design.md:L54`). Плагин пишет
(`transport.py:L248-L257 (agent_markdown)`):

```yaml
---
name: hermes-relay
description: "Hermes Agent relay: model only, Hermes executes every tool"
excludeDefaultComponents: true
inheritMcp: false
subagent: false
mainAgent: true
mcpServers:
  - name: hermes
    command: "/usr/bin/python3"
    args: ["<каталог плагина>/inert_mcp.py", "/tmp/agy-hermes-XXXX/tools.json", "/tmp/agy-hermes-XXXX/captured_calls.jsonl"]
---
<тело: системный промпт хоста + протокол и каталог инструментов>
```

| Поле | Что делает | Источник |
|------|-----------|----------|
| `excludeDefaultComponents: true` | Убирает стандартные разделы промпта `agy` и встроенные инструменты | `docs/design.md:L56` |
| `inheritMcp: false` | Глобальные MCP-серверы пользователя не загружаются | `docs/design.md:L57` |
| `mainAgent: true` | **Обязательно:** без него `--agent` агента не находит | `docs/design.md:L58` |
| `mcpServers` | **Список объектов** (`- name`, `command`, `args`). Словарь или список путей — описание не разбирается | `docs/design.md:L59` |
| `subagent: false` | В коде есть, в документации не объяснено | `transport.py:L251` |

`command` и `args` пишутся как строки JSON: это допустимые скаляры YAML, пути экранировать не нужно
(`transport.py:L253-L255`). Без инструментов раздел `mcpServers` не пишется вовсе (`transport.py:L252`,
`tests/test_transport.py:L103-L108`). Все разделы первого уровня (H1) из тела доходят до модели
(`docs/design.md:L100`).

Замер на коротком промпте (`docs/design.md:L63-L66`):

| Агент | Инструменты у модели | Входных токенов |
|-------|---------------------|----------------:|
| по умолчанию | 17 (`view_file`, `run_command`, `write_to_file`, …) | 24 749 |
| свой, `excludeDefaultComponents: true` | `call_mcp_tool`, `list_resources`, `read_resource`, `manage_task` | ~1 000–1 230 |

### 3.3. Четыре «фундаментальных» инструмента

`call_mcp_tool`, `list_resources`, `read_resource`, `manage_task` из заголовка убрать нельзя: `tools: [call_mcp_tool]`
падает с `tool "call_mcp_tool" not found in registry`. Они только перечисляют и убивают фоновые задачи или читают
ресурсы MCP; файлы не пишут и команды не запускают. Любое обращение к трём последним плагин всё равно считает
нарушением (`docs/design.md:L68`, `L155`). Системный промпт прямо запрещает модели их вызывать
(`transport.py:L240`).

### 3.4. Молчаливый откат на агента по умолчанию

Находка по безопасности (`docs/design.md:L72`): если `agent.md` не разобрался, `agy` только пишет в журнал

```
W0925 19:56:54.968630       1 session.go:94] Agent "hermes-relay" not found, falling back to default
```

(вид строки — `tests/fake_agy.py:L101-L102`), событие `init` при этом **по-прежнему сообщает `agent: hermes-relay`**,
а прогон идёт с агентом по умолчанию — в испытаниях тот сам выполнил встроенный `view_file`.

Как ловится:

- флаг `--log-file` указывает на личный журнал прогона `<каталог>/agy.log` (`transport.py:L491-L493`);
- маркер — подстрока `not found, falling back to default` (`transport.py:L54 (FALLBACK_MARKER)`);
- `AgyProcess.fell_back()` ищет её в журнале (`transport.py:L550-L554`);
- проверка дважды: на событии `init` (вместе с проверкой, что `init.agent` — `hermes-relay` или отсутствует) и после
  конца потока; при срабатывании — убийство процесса и `AgyAgentNotLoaded` (`transport.py:L892-L897`, `L934-L935`).

### 3.5. Каталог инструментов — в системном промпте

`agy` **не кладёт схемы инструментов MCP в промпт**: модель на вопрос об инструментах сервера `hermes` без вызова
отвечает «неизвестно», а два подключённых инструмента добавляют около 15 токенов (`docs/design.md:L79`). Поэтому
каталог (имя, описание, JSON Schema) и правила вызова пишет в тело агента сам плагин — раздел 6.

### 3.6. Вызов инструмента в потоке

Модель зовёт инструмент функцией `call_mcp_tool`; в stream-json приходит `step_update` с `step_type: tool`,
`state: ACTIVE` и полями `ServerName`, `ToolName`, `Arguments` в `tool_info.parameters`. На этом событии плагин
запоминает вызов и убивает группу процессов (`docs/design.md:L80`, `transport.py:L908-L922`). Инертный сервер вызов
записывает и не отвечает, поэтому `agy` ждёт и не может получить результат (`docs/design.md:L81`).

**Параллельные вызовы.** Когда модель просит два инструмента в одном ответе, `agy` запускает их по одному, и в потоке
виден только первый шаг `tool`. Поэтому на один запрос возвращается не больше одного `tool_call`; промпт просит один
вызов на ответ (`docs/design.md:L82`, `transport.py:L239`, `L914-L915`).

### 3.7. Свежий прогон на каждый запрос

Продолжение беседы `--conversation` не используется: хост каждый раз шлёт полный список сообщений и сам сжимает
историю, продолженная беседа `agy` разошлась бы с ней. stdin `agy` принимает только пользовательские события с
текстом, поэтому свёртка в один текст — единственный вариант (`docs/design.md:L96-L98`). Замер: с 1, 3 и 6 прошлыми
кругами инструментов в свёрнутой истории модель делала настоящие вызовы с верными аргументами
(`gemini-3.8-flash-low` 9/9, `claude-sonnet-4-6` 3/3) и ни разу не написала вызов текстом (`docs/design.md:L98`).
Промпт идёт через stdin, а не `-p=<промпт>`, чтобы не упереться в предел 128 КиБ на один аргумент
(`docs/design.md:L102`).

### 3.8. Usage и расход токенов

| Поле хоста | Откуда |
|-----------|--------|
| `prompt_tokens` | `input_tokens + cache_read_tokens` |
| `completion_tokens` | `output_tokens` |
| `prompt_tokens_details.cached_tokens` | `cache_read_tokens` |
| `completion_tokens_details.reasoning_tokens` | `thinking_tokens` |
| `native_usage` | исходный словарь `agy` |

(`transport.py:L987-L994 (_usage)`, `docs/design.md:L129`). Запрос с вызовом инструмента убит до события `result`,
поэтому его usage — сумма usage завершённых шагов `agent_response` (`transport.py:L906-L907`, `L953-L958`).

Расход (`docs/design.md:L106-L112`): агент по умолчанию — 24 749 входных токенов; свой агент без инструментов —
987–1 230; первый запрос Hermes с полным каталогом — около 14 300. Основная цена — промпт и схемы хоста, не `agy`.

### 3.9. Прогрев и фоновое завершение

`agy` заканчивает загрузку и выдаёт `init` **до** чтения stdin; холодный старт — около 5,5 с, в основном сетевые
обращения. Пока идёт запрос с инструментами, для следующего запроса той же формы запускаются запасные процессы — до 2
на форму и 4 всего; неиспользованные убиваются через `ANTIGRAVITY_AGY_PREWARM_TTL` (300 с) и при выходе интерпретатора
(`docs/design.md:L138`). **Инвариант:** запасной процесс ничего не получил в stdin — у него нет состояния беседы, он
не мог видеть результат инструмента; после передачи он получает ровно один промпт, и первый вызов инструмента так же
убивает его группу (`docs/design.md:L139`). Итоговый ответ отдаётся хосту сразу, ожидание выхода `agy` — в фоновом
потоке (`docs/design.md:L140`). Устройство — раздел 10, цифры — раздел 4.

**Отвергнуто** (`docs/design.md:L142`, `docs/perf.md:L60-L65`): долгоживущий `agy` на несколько ходов или
`--conversation` (второй ход 2,1 с — не быстрее запасного, зато результаты инструментов попадали бы в `agy`, а история
расходилась бы); каталог инструментов в начале промпта ради кеша (0/12 попаданий); задержка убийства после вызова
(0/6 при 0 с и при 2 с); флагов ускорения старта в `agy --help` и `agy changelog` нет.

### 3.10. Известные ограничения (§4)

| Ограничение | Источник |
|-------------|----------|
| Один вызов инструмента на запрос; первый запрос процесса хоста и запрос после смены системного промпта — холодный старт | `docs/design.md:L153` |
| Только текст: картинки заменяются на `[image omitted: …]` | `docs/design.md:L154`, `transport.py:L56`, `L118-L119` |
| Три фундаментальных инструмента убрать нельзя; их вызов — отказ запроса | `docs/design.md:L155` |
| Прогоны с промптом оставляют записи бесед в `~/.gemini/antigravity-cli/`, если не `ANTIGRAVITY_AGY_CLEANUP=all` | `docs/design.md:L156` |
| Запасной процесс — около 416 МБ RSS; запасные проверяются только `alive()`: если сессия протухла, запрос падает, а не уходит в холодный старт | `docs/design.md:L157` |
| `excludeDefaultComponents` оставляет хуки после вызова: хуки из глобальной настройки `agy` пользователя могут сработать | `docs/design.md:L158` |
| `reasoning_effort`, `temperature`, `max_tokens`, `response_format` игнорируются; усилие — часть имени модели | `docs/design.md:L159` |
| Окна контекста — оценка; поведение на очень длинном сообщении не проверено (позже найдено обрезание — раздел 1) | `docs/design.md:L160`, `README.md:L10` |
| Тексты ошибок квоты и выхода из аккаунта проверены только на поддельном `agy` | `docs/design.md:L161` |

## 4. Замеры (`docs/perf.md`)

Linux, Hermes v0.21.4, `agy` 1.2.11, `gemini-3.8-flash-low`, по 3 прогона каждой задачи (`docs/perf.md:L3`).

| Что измерено | Значение | Источник |
|--------------|----------|----------|
| Холодный старт `agy` (до `init`) | ~5–6 с на запрос, в основном сеть (`loadCodeAssist`, `fetchAvailableModels`); флага пропустить нет | `docs/perf.md:L22` |
| Ответ после прихода промпта | ~2,7 с | `docs/perf.md:L23` |
| Память простаивающего запасного процесса (вся группа) | ~416 МБ RSS | `docs/perf.md:L44` |
| Старт Hermes до первого запроса (вне плагина) | ~11 с | `docs/perf.md:L21` |
| Задача с двумя инструментами, всего | 44,5 → 30,2 с (−32 %) | `docs/perf.md:L9` |
| Задержка на запрос в задаче с инструментами | 10,3 → 5,5 с (−47 %) | `docs/perf.md:L10` |
| Ожидание старта `agy` в задаче с инструментами | 5,7 → 2,1 с (−63 %) | `docs/perf.md:L11` |
| Один вопрос-ответ, всего | 25,8 → 22,1 с (−14 %) | `docs/perf.md:L12` |
| Один вопрос-ответ, задержка запроса | 13,6 → 9,4 с (−31 %) | `docs/perf.md:L13` |
| Входных токенов на запрос: JSON каталога без отступов | 16 413 → 14 628 (задача с инструментами), 16 092 → 14 307 (вопрос-ответ); −11 % | `docs/perf.md:L14`, `L52` |
| Размер промпта хоста | системный ~17,5 тыс. символов + ~23 инструмента ~40 тыс. символов (JSON с отступами) | `docs/perf.md:L25` |
| Ответ до выхода процесса: от последнего вывода `agy` до ответа у хоста | ~3 с → ~0,1 с | `docs/perf.md:L48` |
| Ожидание выхода `agy` после `result` | до 15 с в фоне, затем убийство; сам выход — 1–3 с | `docs/perf.md:L24`, `L48` |
| Запасной процесс при убитом хосте | видит закрытие stdin и выходит за ~1 с | `docs/perf.md:L42` |
| Нарушений в трассах | 0 до и после | `docs/perf.md:L15` |

Глубина прогрева (`docs/perf.md:L33-L39`):

| Настройка | Задача всего | Запрос 2: старт / задержка | Запрос 3: старт / задержка |
|-----------|-------------:|---------------------------|---------------------------|
| Без прогрева | 44,5 с | 5,3 / 8,8 с | 6,6 / 12,9 с |
| Глубина 1 + фоновое завершение | 34,0 с | 0 / 3,3 с | 1,5–1,8 / 4,2–7,9 с |
| Глубина 2 (по умолчанию) + фоновое завершение | 30,4 с | 0 / 2,5–3,3 с | 0 / 2,6–3,1 с |

При глубине 1 запасной для третьего запроса стартует только во время короткого второго и не успевает на ~1,7 с;
при глубине 2 оба грузятся во время долгого холодного первого (`docs/perf.md:L39`).

Кеш промпта в настоящей работе — 139 запросов Hermes, в основном одна длинная сессия на `gemini-3.8-flash-high`,
около 82 тыс. входных токенов на запрос (`docs/perf.md:L71-L75`, `docs/design.md:L114`):

| Запросы | Число | С попаданием | Доля токенов из кеша |
|---------|------:|-------------:|---------------------:|
| Все | 139 | 130 | 88,9 % |
| Первый запрос хода | 9 | 2 | 45,8 % |
| Последующие в цикле инструментов | 130 | 128 | 90,3 % |

Что влияет на кеш (`docs/perf.md:L79-L83`):

- путь рабочего каталога в промпт не входит: постоянный и новый каталог попадают одинаково;
- после запроса, завершившегося нормально, следующий с тем же системным промптом попал 18/18;
- после запроса, убитого на вызове инструмента: от 0/2 (немедленное убийство) до 4/4 (отложенное) при холодном
  следующем и 2/4 при прогретом следующем, независимо от паузы 0,3 с или 6 с;
- синтетические циклы (три запроса по ~15 тыс. токенов с паузой 2–5 с) в кеш не попали ни разу. Объяснение автора
  **не проверено**: серверу нужно несколько секунд, прежде чем новую запись можно использовать.

Свежий процесс на запрос и убийство на вызове инструмента кешу в настоящей работе не мешают: история только
дополняется, системный промпт и старая история идут кешируемым началом (`docs/design.md:L114`).

## 5. Командная строка `agy`

Основной запуск — `transport.py:L492-L493`:

```
<agy> --input-format stream-json --output-format stream-json --model <slug>
      --agent hermes-relay --log-file <каталог>/agy.log --disable-slash-commands
```

| Флаг | Зачем | Источник |
|------|-------|----------|
| `--input-format stream-json` | Промпт приходит кадром JSON в stdin, а не аргументом `-p=<промпт>` с пределом 128 КиБ | `docs/design.md:L102` |
| `--output-format stream-json` | Вывод — поток NDJSON: `init`, `step_update`, `result` | `transport.py:L506-L523` |
| `--model <slug>` | Имя модели `agy` после `native_model()`: псевдоним или семейство без усилия переводятся в полное имя. Голое семейство `agy` отвергает: `--model gemini-3.8-flash requires --effort` | `transport.py:L847`, `model_catalog.py:L43-L56`, `docs/design.md:L122` |
| `--agent hermes-relay` | Выбор своего агента из `.agents/agents/` рабочего каталога | `transport.py:L51`, `docs/design.md:L54` |
| `--log-file <путь>` | Личный журнал прогона — единственное место, где виден откат на агента по умолчанию | `docs/design.md:L74` |
| `--disable-slash-commands` | В коде и документации не объяснён; проверяется только наличие (`tests/test_transport.py:L68`). По смыслу — чтобы текст, начинающийся с `/`, не исполнился как команда CLI (ср. `-p=/quota`) | `transport.py:L493` |

Процесс запускается в своей сессии (`start_new_session=True`) — `agy`, его language server и дочерний MCP умирают
вместе; рабочий каталог — личный `workspace`; stderr — в файл `stderr.txt`; окружение передаётся целиком
(`transport.py:L496-L499`, `L843`). Убийство — `os.killpg(pid, SIGKILL)` (`transport.py:L262-L268`).

Упомянутые, но не используемые возможности CLI: `-p=<промпт>` (режим печати с промптом в аргументе), `--conversation`
(продолжение беседы), `--effort` (усилие отдельно от имени модели; встречается только в тексте ошибки),
`agy --help`, `agy changelog`, `agy --version` (`docs/design.md:L96`, `L102`, `L122`; `docs/perf.md:L64`;
`plugin.yaml:L21`).

Пробы — раздел 11: `agy models` и `agy -p=/quota --output-format json`.

Личный каталог прогона `agy-hermes-*` (`transport.py:L476-L494`):

```
<каталог>/
  workspace/.agents/agents/hermes-relay/agent.md   — агент; workspace — cwd процесса
  tools.json                                       — манифест для MCP (только при инструментах)
  captured_calls.jsonl                             — куда MCP пишет вызовы
  agy.log                                          — журнал agy (--log-file)
  stderr.txt                                       — stderr процесса
```

Каталог удаляется при уборке процесса (`transport.py:L604`).

## 6. Протокол stream-json

Примеры ниже взяты из поддельного `agy` в тестах (`tests/fake_agy.py`) и из разбора в `transport.py`. Трасс настоящего
`agy` в репозитории нет — см. раздел 16.

### 6.1. Ввод

Один кадр, одна строка, затем stdin закрывается — после него `agy` завершается сам (`transport.py:L540-L544`,
`tests/test_transport.py:L69-L70`):

```json
{"event":"user","message":{"role":"user","content":"hi"}}
```

`content` — строка; принимаются только пользовательские события с текстом (`docs/design.md:L98`, `L154`). Если `agy`
уже вышел (например, отверг `--model` до чтения stdin), запись упрётся в закрытый канал; `send()` это глотает, чтобы
наружу ушла собственная ошибка `agy`, а не `BrokenPipeError`, который хост стал бы повторять
(`transport.py:L545-L548`, `tests/test_transport.py:L287-L295`).

### 6.2. Вывод

Строки NDJSON; пустые пропускаются; строка не-JSON превращается во внутреннее событие `_noise` и ни на что не влияет
(`transport.py:L508-L520`, `tests/test_transport.py:L191-L193`). Конец потока — выход процесса
(`transport.py:L526-L528`).

**`init`** — приходит до чтения stdin (`tests/fake_agy.py:L118-L121`):

```json
{"event":"init","conversation_id":"<uuid>","init":{"model":"gemini-3.8-flash-low","cwd":"/tmp/agy-hermes-x/workspace",
 "agent":"hermes-relay","tools":["call_mcp_tool","run_command","view_file"],"permission_mode":"always-proceed"}}
```

Плагин берёт из него `conversation_id` (для уборки записей — `transport.py:L521-L522`) и `init.agent` (проверка —
`L893-L894`). Значению `agent` верить нельзя (раздел 3.4).

**`step_update`** — общий вид (`tests/fake_agy.py:L23-L25`):

```json
{"event":"step_update","step_update":{"conversation_id":"…","step_index":1,"state":"ACTIVE","step_type":"agent_response","text_delta":"Hello"}}
```

| `step_type` | `state` | Поля | Что делает плагин |
|-------------|---------|------|-------------------|
| `user_input` | `DONE` | — | игнорирует (`tests/fake_agy.py:L139`) |
| `agent_response` | `ACTIVE` | `text_delta` | отдаёт чанк текста сразу (`transport.py:L901-L905`) |
| `agent_response` | `DONE` | `text_delta` (может быть), `usage` | запоминает usage шага (`transport.py:L906-L907`) |
| `tool` | `ACTIVE` | `tool_name`, `tool_info.name`, `tool_info.parameters` | вызов хоста или нарушение (`transport.py:L908-L928`) |
| `tool` | `DONE` / `ERROR` | — | плагин до них не доходит; аудит считает нарушением (`scripts/audit_trace.py:L3-L4`, `L38`) |
| `error_message` | `DONE` | — | игнорирует; ошибка берётся из `result` и stderr (`tests/fake_agy.py:L181`) |

Шаг `agent_response` с usage (`tests/fake_agy.py:L28-L30`, `L151`):

```json
{"event":"step_update","step_update":{"conversation_id":"…","step_index":1,"state":"DONE","step_type":"agent_response",
 "text_delta":"\n","usage":{"input_tokens":1000,"output_tokens":5,"thinking_tokens":3,"cache_read_tokens":200,"total_tokens":1005}}}
```

Вызов инструмента хоста (`tests/fake_agy.py:L41-L42`, `L156`):

```json
{"event":"step_update","step_update":{"conversation_id":"…","step_index":2,"state":"ACTIVE","step_type":"tool",
 "tool_name":"call_mcp_tool","tool_info":{"name":"call_mcp_tool",
 "parameters":{"Arguments":{"command":"ls -la"},"ServerName":"hermes","ToolName":"terminal"}}}}
```

`Arguments` бывает объектом или строкой JSON (`tests/fake_agy.py:L162`); строка разбирается, неразбираемая или
не-объект заворачивается в `{"input": …}` (`transport.py:L125-L131`, `L916-L920`). Имя инструмента берётся из
`tool_name`, иначе из `tool_info.name` (`transport.py:L910`). Встроенный инструмент выглядит так же, но с другими
параметрами: `tool_name: run_command`, `parameters: {"CommandLine": "…"}` (`tests/fake_agy.py:L167`).

**`result`** — конец обычного ответа (`tests/fake_agy.py:L33-L38`):

```json
{"event":"result","result":{"conversation_id":"…","status":"SUCCESS","response":"Hello world\n","duration_seconds":0.1,
 "num_turns":1,"usage":{"input_tokens":1000,"output_tokens":5,"thinking_tokens":3,"cache_read_tokens":200,"total_tokens":1005}}}
```

Ошибка:

```json
{"event":"result","result":{"conversation_id":"…","status":"ERROR","response":"","duration_seconds":0.1,"num_turns":1,
 "usage":{…},"error":"You have exhausted your capacity on this model"}}
```

| Поле `result` | Как используется |
|---------------|------------------|
| `status` | `SUCCESS` — ответ; иное — ошибка: плагин ждёт выхода процесса до 15 с, чтобы stderr дописался, и разбирает ошибку (`transport.py:L941-L944`) |
| `response` | Полный текст. Если он длиннее накопленных дельт и начинается с них — остаток отдаётся ещё одним чанком; итоговый текст — всегда `response` (`transport.py:L945-L949`) |
| `usage` | usage всего прогона; нет — сумма по шагам (`transport.py:L950-L958`) |
| `error` | Текст ошибки для `classify_error` (`transport.py:L943`) |
| `duration_seconds`, `num_turns`, `conversation_id` | не используются |

Рядом с `result` при ошибке `agy` печатает в stderr строку (`tests/fake_agy.py:L182-L184`):

```
AGY_ERROR: {"short_error":"RESOURCE_EXHAUSTED: You have exhausted your capacity on this model","status":"RESOURCE_EXHAUSTED","error_code":429,"code_kind":"http","retryable":false}
```

Из неё берутся `short_error`, `status`, `error_code` через пробел; если после префикса не JSON — строка как есть
(`transport.py:L368-L377 (_agy_error_line)`).

### 6.3. Трасса

При `ANTIGRAVITY_AGY_TRACE_DIR` каждый прогон пишется в `<дата-время>-<8 hex>.ndjson`: сырые строки `agy` с добавленным
в начало `_ts` (секунды от начала запроса; у строк прогретого процесса, пришедших до запроса, — отрицательное) и
служебные строки `_request` (`wall`, `model`, `tools`, `system_chars`, `prompt_chars`, `system_sha`), `_spawn` (`warm`,
`warm_age`), `_done` (`finish`) (`transport.py:L380-L410`, `L530-L535`, `L850-L856`, `L968-L969`). Трассы содержат
текст беседы (`README.md:L124`).

## 7. Подготовка запроса

### 7.1. `prepare_conversation` — история одним текстом

`transport.py:L134-L186`. Возвращает пару «системный текст, текст промпта».

- `system` и `developer` **до первого хода** склеиваются через пустую строку в системный текст — он пойдёт в тело
  агента (`L140-L144`, `L162`). Такое же сообщение **после** начала ходов остаётся в истории как
  `<message role="system">` (`L141-L142`, `tests/test_transport.py:L267-L272`).
- Содержимое сообщения сводится к тексту: блоки `text`, `input_text`, `output_text` — их текст; `image_url`, `image`,
  `input_image` — заглушка `[image omitted: the antigravity-agy provider forwards text only]`; прочие блоки — их JSON
  (`L104-L122 (render_content)`).
- У `assistant` собираются вызовы: `id`, `name`, `arguments` (строка JSON разбирается); запоминается соответствие
  «идентификатор вызова → имя» (`L147-L153`).
- У `tool` имя берётся из сообщения, иначе по идентификатору вызова (`L154-L156`).
- Другая роль — `ValueError` (`L157-L158`). История должна кончаться на `user` или `tool`; предзаполнение ответа
  ассистента не поддерживается (`L159-L160`).
- **Ровно один ход** — отправляется как есть, без обёртки (`L161-L162`, `tests/test_transport.py:L251-L252`).

Иначе — такой текст (`L163-L186`, `tests/test_transport.py:L232-L246`):

```
<conversation>
<message role="user">
Read notes.txt
</message>
<message role="assistant">
<необязательный текст ассистента>
<tool_call id="call_1" name="read_file">{"path": "notes.txt"}</tool_call>
</message>
<message role="tool" tool_call_id="call_1" name="read_file">
the secret word is ORCHID
</message>
</conversation>

The conversation above is the complete history so far and you are the assistant in it. Continue from its end: respond to the latest user message, or use the latest tool results. To use a tool, make a real call_mcp_tool function call; never write <tool_call> markup as text.
```

Текст сообщений и значения атрибутов вставляются **без экранирования** (`L165-L180`): результат инструмента с
`</message>` или `<message role="system">` внутри неотличим от разметки.

### 7.2. `normalize_schema` и `tool_manifest`

`agy` отвергает инструменты MCP, у которых входная схема — не схема объекта (`transport.py:L189-L190`). Поэтому
(`L191-L204`): не-словарь → `{}`; глубокая копия; попытка убрать nullable-объединения средством ядра Hermes
(`tools.schema_sanitizer.strip_nullable_unions`, при любой ошибке пропускается); **с верхнего уровня** удаляются
`oneOf`, `allOf`, `anyOf`; `type` принудительно `object`; `properties` — словарь, иначе `{}`. Пример:
`{'anyOf': [{'type': 'object'}]}` → `{'type': 'object', 'properties': {}}` (`tests/test_transport.py:L280-L284`).

`tool_manifest(tools, tool_choice)` — `L207-L222`: при `tool_choice == 'none'` инструментов нет вовсе; принимается
только `type: function`; имена — непустые уникальные строки, иначе `ValueError`; элемент манифеста —
`{name, description, inputSchema}`. Другие значения `tool_choice` не учитываются.

### 7.3. `system_prompt` — тело агента

`transport.py:L225-L245`. Сначала системный текст хоста, затем при наличии инструментов:

````
# Tool protocol
You run as the model inside Hermes Agent. Hermes, not you, executes tools. The only way to use a tool is the
`call_mcp_tool` function with `ServerName` set to "hermes", `ToolName` set to one of the tool names listed below,
and `Arguments` set to a JSON object matching that tool's parameter schema.
- Make at most one tool call per response. After calling, stop: the result arrives in the next turn.
- Never call list_resources, read_resource, manage_task or any other function, and never write tool calls as text.
- When no tool is needed, answer directly.

# Available tools

### terminal
Run a shell command.

Parameters (JSON Schema):
```json
{"type":"object","properties":{"command":{"type":"string"}},"required":["command"]}
```
````

Схемы — компактный JSON без отступов (`separators=(',', ':')`), `ensure_ascii=False` (`L233`). Без инструментов —
`# Tool protocol` и фраза `No tools are available in this conversation. Do not call any function; answer directly.`
(`L244`). Порядок проверяется тестом: протокол, затем инструменты в порядке манифеста
(`tests/test_transport.py:L94-L98`).

## 8. Инертный MCP-сервер

`inert_mcp.py:L14-L40`; запуск: `inert_mcp.py <manifest.json> <capture.jsonl>` (`L7`), транспорт stdio, по строке JSON
на сообщение. Импортирует только `json`, `pathlib`, `sys` — это проверяется тестом
(`tests/test_inert_mcp.py:L36-L39`).

| Запрос | Ответ | Строки |
|--------|-------|--------|
| `initialize` | `{"protocolVersion": <версия из запроса или "2024-11-05">, "capabilities": {"tools": {}}, "serverInfo": {"name": "hermes", "version": "1"}}` | `L30-L33` |
| `tools/list` | `{"tools": <манифест из файла>}` | `L34-L35` |
| `tools/call` | **Нет ответа.** В файл захвата дописывается `{"name": …, "arguments": …}` | `L23-L27` |
| `ping`, `server/discover` | `{}` | `L36-L37` |
| уведомление без `id` (например `notifications/initialized`) | игнорируется | `L28-L29` |
| любой другой метод | ошибка `-32601`, `Method not found: <метод>` | `L38-L39` |
| строка не-JSON | игнорируется | `L18-L21` |

Ответ — `{"jsonrpc": "2.0", "id": <id>, …}` (`L40`). Файл захвата `captured_calls.jsonl` транспорт **не читает**: вызов
берётся из потока stream-json (`transport.py:L908-L920`), файл остаётся записью для отладки и удаляется вместе с
каталогом.

## 9. Нарушения и ошибки

### 9.1. Что считается нарушением

Шаг `tool` принимается, только если одновременно: имя — `call_mcp_tool`, `ServerName == "hermes"`, `ToolName` входит
в набор имён, предложенных хостом в этом запросе (`transport.py:L913`). Иначе — убийство группы процессов и
`AgyNativeToolViolation` с текстом, что именно пытался сделать `agy` (`transport.py:L923-L928`).

| Нарушение | Сценарий в тестах | Исключение |
|-----------|-------------------|-----------|
| Любой шаг инструмента, кроме `call_mcp_tool` (встроенные, включая три фундаментальных) | `native_tool` — `run_command` | `AgyNativeToolViolation` |
| `call_mcp_tool` к серверу, отличному от `hermes` | `foreign_mcp` — `codegraph/explore` | `AgyNativeToolViolation` |
| `call_mcp_tool` с именем, которого хост не предлагал | `unknown_hermes_tool` — `hermes/not_offered` | `AgyNativeToolViolation` |
| `init.agent` не `hermes-relay` или в журнале есть маркер отката | `fallback` | `AgyAgentNotLoaded` |

(`docs/design.md:L86-L92`, `tests/fake_agy.py:L165-L179`, `tests/test_transport.py:L141-L161`.) Убийство происходит по
приходу события `ACTIVE`, то есть инструмент уже мог начать работу; автор называет это «защитой в глубину»: всё, что
пишет файлы и запускает команды, уже убрано `excludeDefaultComponents` (`docs/design.md:L92`). Запрос при нарушении
падает целиком, повторов нет.

### 9.2. `classify_error`

`transport.py:L357-L365`. Текст приводится к нижнему регистру, признаки ищутся подстрокой, по порядку:

| Признак в тексте | Класс | Сообщение |
|------------------|-------|-----------|
| `resource_exhausted`, `quota`, `rate limit`, `429`, `limit reached`, `out of credits` | `AgyQuotaExhausted` | до 500 символов текста + совет `agy -p=/quota`; лимиты — окна 5 часов и неделя |
| `not logged`, `not signed`, `sign in`, `log in`, `login`, `unauthenticated`, `authentication` | `AgyLoggedOut` | подсказка о входе + до 500 символов текста |
| всё остальное | `AgyError` | `agy request failed:` + до 800 символов (при неверной модели — список доступных от самого `agy`) |

Откуда берётся текст (`transport.py:L936-L944`):

- события `result` не было — строка `AGY_ERROR:` из stderr, иначе последние 500 символов stderr, иначе `exit code <код>`;
- `result.status != SUCCESS` — `result.error` и строка `AGY_ERROR:` через пробел, иначе `status <значение>`.

Остальные ошибки:

| Ситуация | Исключение | Строки |
|----------|-----------|--------|
| Бинарник не найден | `AgyMissing` с подсказкой об установке | `transport.py:L845-L846` |
| Нет вывода `timeout` секунд | `TimeoutError`, группа процессов убита | `transport.py:L875-L876`, `L977-L981` |
| Отмена запроса или закрытый клиент | `AgyError('agy request cancelled')` / `'agy client is closed'` | `transport.py:L872-L873`, `L824-L825` |
| Сбой чтения потока | `AgyError('agy stream failed: …')` | `transport.py:L881-L882` |

Иерархия: все классы — потомки `AgyError(RuntimeError)` (`transport.py:L59-L80`). Признаки широкие: `429` или `login`
в любом тексте ошибки дадут соответствующий класс. Ошибки квоты и выхода из аккаунта проверены только на поддельном
`agy` (`docs/design.md:L161`).

## 10. Процессы: `AgyProcess`, `WarmPool`, `reap`

### 10.1. `AgyProcess` — один прогон

`transport.py:L469-L607`. Конструктор (`L474-L504`) создаёт каталог, пишет агента и манифест, запускает `agy` и поток
чтения stdout. Процесс можно создать **до** появления запроса: пока `send()` не записал сообщение, `agy` только
грузится — входит, читает агента, поднимает MCP, выдаёт `init` — и к модели не обращается (`L470-L472`).

| Метод | Что делает | Строки |
|-------|-----------|--------|
| `_read` | Поток: строки stdout → события в очередь; в конце ждёт выхода процесса и кладёт `None` | `L506-L528` |
| `attach_trace` | Отдаёт трассе строки, пришедшие до запроса, с их временем прихода | `L530-L535` |
| `alive` | `poll() is None` | `L537-L538` |
| `send` | Помечает `used`, пишет кадр, закрывает stdin | `L540-L548` |
| `fell_back` | Ищет маркер отката в журнале | `L550-L554` |
| `kill` / `wait` | `SIGKILL` группе / ожидание с таймаутом | `L556-L564` |
| `stderr_text` | Читает файл stderr с начала | `L566-L572` |
| `dispose(grace)` | Уборка, один раз: ждёт самостоятельного выхода до `grace` секунд шагами по 0,1 с (прерывается флагом `urgent`), убивает группу, ждёт до 5 с, дожидается потока чтения, закрывает каналы, трассу и stderr, удаляет каталог, при нужном режиме — убирает запись беседы | `L574-L607` |

### 10.2. Уборка записей бесед

Каждый запуск `agy` создаёт запись беседы в каталоге данных (`~/.gemini/antigravity-cli`, меняется
`ANTIGRAVITY_AGY_DATA_DIR`). Режим `ANTIGRAVITY_AGY_CLEANUP` (`transport.py:L450-L458`): `unused` (по умолчанию) —
только записи запасных процессов, не получивших промпт; `all` — все записи прогонов плагина; `off` — ничего.
Условие — `L605-L607`.

Файлы ищутся только по `conversation_id` из события `init` этого же процесса; идентификатор обязан быть UUID
(`L425`, `L610-L613`). Пути (`L615-L617`):

```
<data>/conversations/<id>.db, .db-shm, .db-wal, .pb
<data>/brain/<id>/
<data>/presence/<id>.lock
<data>/annotations/<id>.pbtxt
```

Общие хранилища (базы сводок) не трогаются. Файлы не удаляются, а переносятся в корзину командой `trash-put`; нет
`trash-put` в `PATH` — уборки нет (`L621-L632`).

### 10.3. `WarmPool` — пул прогрева

`transport.py:L652-L739`, один на процесс хоста (`POOL`, `L742`). Хранит для каждой формы запроса очередь пар
«процесс, срок годности», старые впереди (`L661`).

- **Форма запроса** — `pool_key`: SHA-256 (первые 16 hex) от JSON с командой `agy`, именем модели, системным промптом,
  манифестом инструментов и отсортированным окружением (`L428-L434`). Смена любого из них — холодный старт
  (`tests/test_perf_prewarm.py:L116`).
- **Глубина** — `prewarm_depth`: `ANTIGRAVITY_AGY_PREWARM`, по умолчанию 2, предел 4; `0`, `false`, `no`, `off`, пусто
  — выключено (`L437-L447`). Всего в пуле не больше `POOL_MAX = 4` (`L424`).
- **Срок** — `prewarm_ttl`: `ANTIGRAVITY_AGY_PREWARM_TTL`, по умолчанию 300 с (`L461-L466`).
- `take(key)` — самый давний живой и непросроченный процесс формы; мёртвые и просроченные по пути убиваются и
  убираются (`L671-L687`).
- `prewarm(key, spec, ttl, depth)` — доводит число процессов формы до `depth`; сбой запуска молча прекращает прогрев
  (следующий запрос просто стартует холодно); при превышении `POOL_MAX` вытесняется процесс с ближайшим сроком,
  сначала из чужих форм; на каждый процесс ставится таймер `expire` (`L689-L718`).
- `expire(key, proc)` — по таймеру убирает процесс, если он ещё в пуле (`L720-L731`).
- `drain()` — убивает и убирает всё (`L733-L739`).

Прогрев запускается только если глубина не ноль и у запроса есть инструменты — вспомогательные запросы (например
заголовок беседы) его не вызывают (`transport.py:L863-L866`, `docs/perf.md:L41`). Запуск идёт **после** отправки
промпта текущему процессу, в том же потоке (`L862-L866`).

### 10.4. `reap` и выход

- `reap(proc, grace)` — уборка в фоновом потоке `agy-reaper`; ожидающие уборки лежат в `_REAPING`
  (`transport.py:L635-L649`).
- Обычный ответ: процесс отвязывается от запроса и уходит в `reap(proc, grace=15)` **до** выдачи последнего чанка —
  stdin закрыт, повлиять на него уже нельзя (`L421`, `L970-L976`).
- Вызов инструмента, ошибка, отмена, таймаут: `proc.kill()` и `reap(proc, grace=0)` (`L977-L981`).
- Выход интерпретатора (`atexit`): `POOL.drain()`, флаг `urgent` всем ожидающим уборкам, ожидание их потоков до 10 с —
  ни один `agy` не переживает хост, каталоги удалены (`L745-L755`).
- Отмена: `Request.cancel()` убивает группу привязанного процесса; `Stream.close()` и `Client.close()` ведут к ней же
  (`L271-L322`, `L776-L791`).

## 11. Пробы: `agy_setup.py`

Обе пробы не тратят квоту и не трогают файлы входа; всё неожиданное даёт `None`/`False`, чтобы вызывающий взял
запасной каталог (`agy_setup.py:L3-L6`). Запуск — в пустом временном каталоге, чтобы настройки рабочего каталога не
влияли; stdin — `/dev/null` (`L28-L32`).

**`agy models`** — `discover_models`, таймаут 40 с (`L65-L83`). Вывод в stdout — строки `имя<TAB>подпись`; в stderr —
служебное `Fetching available models...` (`tests/fake_agy.py:L56-L60`):

```
gemini-3.8-flash-low	Gemini 3.8 Flash (Low)
gemini-3.1-pro-high	Gemini 3.1 Pro (High)
claude-sonnet-4-6	Claude Sonnet 4.6 (Thinking)
```

Берутся строки с табуляцией, непустым именем без пробелов; результат — `[{id, label, note}]`; ненулевой код возврата
или пустой список — `None`. Список не фильтруется (`docs/design.md:L120`).

**`agy -p=/quota --output-format json`** — `quota`, таймаут 30 с (`L35-L50`). Режим печати отвечает на `/quota`
локально, без хода агента. Разбирается последняя строка stdout как JSON (`tests/fake_agy.py:L61-L68`):

```json
{"conversation_id":"","status":"SUCCESS","response":"Gemini Models\tWeekly Limit Remaining\t89%\t2026-09-30T03:11:23Z\nGemini Models\tFive Hour Limit Remaining\t99%\t2026-09-25T16:51:09Z\n"}
```

`response` — строки с полями через табуляцию: группа моделей, название окна, остаток в процентах, время сброса
(`L36`, `L49`). Квота считается по группам моделей (Gemini; Claude и GPT) с окнами 5 часов и неделя
(`README.md:L91`). Без входа (`tests/fake_agy.py:L62-L64`), код возврата 1:

```json
{"status":"ERROR","error":"You are not logged into Antigravity."}
```

`setup_status` (`L53-L62`) возвращает `{available, logged_in, plan, detail, login_command}`: при успехе `detail` —
сводка `группа окно: остаток` через `; `; вход считается выполненным, если проба квоты удалась.

Поиск бинарника — `_resolve` (`L20-L25`): `ANTIGRAVITY_AGY_COMMAND` или `agy` в `PATH`; абсолютный путь должен быть
исполняемым. Модели — `model_catalog.py`: запасной список (`L7-L22`), псевдонимы `flash`, `pro`, `sonnet`, `opus`
(`L26-L31`), окна по префиксу: `gemini-` 1 млн, `claude-` 200 тыс., `gpt-oss-` 128 тыс., прочее 128 тыс. (`L35-L40`),
голое семейство → `-medium`, иначе `-high`, иначе `-low` (`L45-L56`).

## 12. Проверки

### 12.1. `scripts/audit_trace.py`

`scripts/audit_trace.py:L14-L42`; запуск: `audit_trace.py <каталог трасс>`. По каждому файлу `*.ndjson` печатает
строку: агент из `init`, чем кончился прогон (`status` из `result` или `killed-at-tool-call`), был ли процесс
прогретым, `input_tokens` и `cache_read_tokens`; затем каждый шаг `tool` с пометкой `ok` или `VIOLATION`.

Шаг считается правильным, только если `tool_name == 'call_mcp_tool'`, `ServerName == 'hermes'` и `state == 'ACTIVE'`
(`L38`). Значит, нарушение — любой чужой инструмент **и** вызов хоста, дошедший до `DONE` или `ERROR`: это означало
бы, что `agy` получил ответ, которого инертный сервер не даёт (`L3-L4`). В конце — `violations: <число>`, код
возврата 1 при любом нарушении (`L41-L42`).

Чего проверка **не делает**: не сверяет `ToolName` со списком предложенных инструментов и не проверяет агента — поле
`agent` только печатается, а откат по журналу в трассу не попадает.

### 12.2. Тесты и замеры

- Тесты ходят в поддельный `agy` и квоту не тратят; ядро Hermes импортируется из клона (`HERMES_AGENT_REPO`)
  (`tests/conftest.py:L3-L5`). CI: Ubuntu и macOS, Python 3.14, `hermes plugins validate` и `pytest`
  (`.github/workflows/tests.yml:L11-L37`).
- Сценарии поддельного `agy` (`FAKE_AGY_SCENARIO`): `text`, `noise`, `fallback`, `tool`, `tool_text`, `native_tool`,
  `foreign_mcp`, `unknown_hermes_tool`, `quota`, `logged_out`, `bad_model`, `crash`, `hang`, `slow_exit`
  (`tests/fake_agy.py:L74-L189`).
- Что закреплено тестами прогрева: запасной ничего не получает до передачи (`tests/test_perf_prewarm.py:L87`); вызов
  на прогретом процессе перехватывается и процесс убит (`L101`); пул ограничен (`L137`); срок истекает (`L147`);
  мёртвый запасной не используется (`L168`); ответ приходит раньше завершения `agy` (`L181`); убираются только свои
  записи бесед (`L206`, `L227`); выход дожидается фоновых уборок (`L312`).
- `scripts/bench.py` — настоящие `hermes chat` в отдельных `HERMES_HOME`; из трассы считает старт (время `init`),
  время до первого вывода, задержку (`_done`), токены; запускает аудит и ищет в `/proc` процессы `agy`, пережившие
  хост (`scripts/bench.py:L67-L124`, `docs/perf.md:L95`).

## 13. Настройки

Все — необязательные переменные окружения процесса хоста (`README.md:L95-L104`, `plugin.yaml:L12-L18`).

| Переменная | По умолчанию | Назначение | Строки |
|-----------|--------------|-----------|--------|
| `ANTIGRAVITY_AGY_COMMAND` | `agy` из `PATH` | Исполняемый файл | `agy_setup.py:L14`, `L20-L25` |
| `ANTIGRAVITY_AGY_PREWARM` | `2` | Запасных процессов на форму; `0` выключает; не больше 4 всего | `transport.py:L416`, `L437-L447` |
| `ANTIGRAVITY_AGY_PREWARM_TTL` | `300` | Секунд простоя запасного | `transport.py:L417`, `L461-L466` |
| `ANTIGRAVITY_AGY_CLEANUP` | `unused` | Какие записи бесед уходят в корзину | `transport.py:L418`, `L450-L458` |
| `ANTIGRAVITY_AGY_DATA_DIR` | `~/.gemini/antigravity-cli` | Каталог данных `agy` — только для поиска своих записей | `transport.py:L419`, `L623` |
| `ANTIGRAVITY_AGY_TRACE_DIR` | нет | Каталог сырых трасс NDJSON | `transport.py:L55`, `L850-L852` |

Процессу хоста нужно всё окружение, которое нужно самому `agy`, например переменные прокси (`README.md:L106`,
`docs/design.md:L149`).

## 14. Что это значит для Меркурия

### Годится как образец

Для сервиса «один процесс `agy` на вызов модели, инструменты исполняет приложение»:

- **Свой агент как выключатель встроенных инструментов** (раздел 3.2): `agent.md` с `excludeDefaultComponents`,
  `inheritMcp: false`, `mainAgent: true`, `mcpServers` списком объектов; личный каталог на прогон. Побочный выигрыш —
  промпт `agy` около 1 тыс. токенов вместо 24,7 тыс.
- **Проверка отката по журналу** (раздел 3.4): `--log-file` в личный файл и поиск маркера на `init` и в конце. Полю
  `init.agent` не верить.
- **Отказ по умолчанию** (раздел 9.1): принимается только `call_mcp_tool` → наш сервер → имя из списка этого запроса;
  остальное — убийство группы процессов и ошибка запроса.
- **Инертный MCP** (раздел 8): перечисляет, не отвечает на вызов, ничего не импортирует. Тот же приём, что в
  [claude-subscription-directsdk](hermes-claude-subscription-directsdk.md).
- **Каталог инструментов в теле агента** компактным JSON и нормализация схем до объекта (разделы 7.2–7.3).
- **Группа процессов** на прогон и убийство дерева; таймаут на тишину; уборка каталога (разделы 5, 10.1).
- **Ответ до выхода процесса** (раздел 10.4): после `result` отдать ответ, ждать завершения в фоне.
- **Прогрев** (раздел 10.3) — как необязательный второй этап: держится на том, что `init` приходит до чтения stdin, и
  стоит ~416 МБ на запасной процесс; ключ формы включает системный промпт и список инструментов.
- **Пробы без квоты** (раздел 11): `agy models` для каталога моделей и `agy -p=/quota` для входа и остатка — готовая
  основа для `doctor` и темы `self_info`.
- **Разбор ошибок** из `result.error`, строки `AGY_ERROR:` и хвоста stderr; молчаливая запись в закрытый stdin
  (разделы 6.1, 9.2).
- **Аудит трасс** (раздел 12.1) и поддельный CLI со сценариями — образец тестов без входа и без платных вызовов.

### От чего отступаем или что решаем иначе

- **Свёртка всей истории в одно сообщение — под вопросом как основа.** `agy` обрезает одно сообщение после ~65–69 тыс.
  токенов и оставляет начало (`README.md:L10`); из-за этого автор бросил плагин. Варианты: держать историю вызова
  ниже предела сжатием на стороне приложения и сообщать сервисом окно меньше предела; либо постоянный процесс с
  отправкой только новых сообщений, как в `references/hermes-antigravity-subscription` — но тогда результаты
  инструментов попадают в `agy`, а история ведётся в двух местах (раздел 3.9, «отвергнуто»). Это решение владельца.
- **Экранирование в свёртке.** Образец вставляет результаты инструментов в разметку `<message>` как есть
  (раздел 7.1). У Меркурия результат инструмента — недоверенные данные: нужна разметка, которую нельзя подделать
  содержимым.
- **Окружение.** Образец отдаёт `agy` всё окружение хоста (`transport.py:L843`); сервису нужно передавать только
  нужное.
- **Один вызов инструмента на запрос** — свойство `agy`, а не выбор: каждый параллельный вызов превращается в
  отдельный запрос и отдельный процесс.
- **Игнорируемые параметры:** усилие — часть имени модели; `temperature`, `max_tokens`, `response_format`,
  `tool_choice` (кроме `none`) не действуют. Картинки не передаются.
- **Корзина `trash-put`** в контейнере не нужна: каталог данных `agy` — том сервиса, записи бесед можно удалять самим.
- **PHP и AMPHP вместо потоков Python,** сервис в своём контейнере — как принято для модулей; POSIX-группы процессов в
  Linux-контейнере доступны.

### Устарело вместе с `agy` 1.2 — проверить на свежей версии

1. Флаги: `--input-format stream-json`, `--output-format stream-json`, `--agent`, `--log-file`,
   `--disable-slash-commands`, `-p=`; появился ли флаг ускорения старта.
2. Поля `agent.md`: `excludeDefaultComponents`, `inheritMcp`, `mainAgent`, `mcpServers` списком объектов; каталог
   `.agents/agents/<имя>/`.
3. Набор неубираемых инструментов (четыре) и ошибка на `tools: [...]`.
4. Откат на агента по умолчанию: происходит ли ещё, точный текст в журнале, что пишет `init.agent`.
5. Попадают ли схемы инструментов MCP в промпт.
6. Имена и поля событий: `init`, `step_update` (`step_type`, `state`, `tool_info.parameters` с `ServerName`,
   `ToolName`, `Arguments`), `result`, поля usage; строка `AGY_ERROR:`.
7. `init` до чтения stdin (основа прогрева) и время холодного старта; память процесса.
8. Последовательная выдача параллельных вызовов (виден только первый шаг `tool`).
9. Предел длины одного пользовательского сообщения и что происходит за ним.
10. Тексты ошибок квоты и выхода из аккаунта — в образце они известны только по поддельному `agy`.
11. Формат `agy models` и `agy -p=/quota --output-format json`; имена моделей и семейств; требование `--effort`.
12. Раскладка каталога данных `~/.gemini/antigravity-cli` и создание записи беседы при каждом запуске.
13. Хуки пользователя при `excludeDefaultComponents`.
14. Поведение кеша промпта при свежем процессе на запрос и при убийстве на вызове инструмента.

## 15. Проверено на нашей стороне

| Вопрос | Ответ | Версия `agy` | Дата |
|--------|-------|--------------|------|
| Откуда берётся бинарь, можно ли закрепить версию | Установщик `antigravity.google/cli/install.sh` читает манифест `https://antigravity-cli-auto-updater-974169037036.us-central1.run.app/manifests/linux_amd64.json` (`version`, `url`, `sha512`); архив лежит по адресу с версией: `https://storage.googleapis.com/antigravity-public/antigravity-cli/1.5.18-6386224290725888/linux-x64/cli_linux_x64.tar.gz`. Сумма sha512 архива совпала с манифестом | 1.5.18 | 2026-11-26 |
| Что в архиве, что нужно бинарю | Один файл `antigravity` (ELF x86-64, 268 МБ; архив 103 МБ), динамически связан только с glibc (`libc`, `libm`, `libpthread`, `libdl`, `librt`, `libresolv`). На `php:8.2-cli-bookworm` запускается, `--version` → `1.5.18`. Есть сборка под musl (`linux_amd64_musl`) и arm64 | 1.5.18 | 2026-11-26 |
| Флаги | `--input-format` (text, stream-json), `--output-format` (text, json, stream-json), `--print`/`-p`/`--prompt`, `--print-timeout`, `--model`, `--effort` (low, medium, high, xhigh, max), `--agent`, `--log-file`, `--disable-slash-commands` («Disable slash command and skill expansion in print mode»), `--settings` (JSON настроек на одну сессию, не сохраняется), `--mode` (accept-edits, auto, plan), `--sandbox`, `--json-schema`, `--conversation`/`--resume`/`-r`, `--continue`/`-c`, `--add-dir`, `--project`, `--new-project`, `--remote-agent`, `--remote-control`, `--dangerously-skip-permissions` | 1.5.18 | 2026-11-26 |
| Подкоманды | `acp` (сервер ACP по stdio — в том же бинаре), `agent`/`agents`, `models`, `mcp` (add, remove, list, enable, disable), `plugin`, `changelog`, `install`, `update`, `remote-control`, `mic-serve`, `help`. Отдельных подкоманд входа и выхода нет | 1.5.18 | 2026-11-26 |
| Что без входа | `agy models` → «Error: Please sign in to view available models. Launch the CLI without arguments to sign in.», код выхода 0. `agy` без аргументов и без терминала → «CLI error: bubbletea: error opening TTY … /dev/tty», код 0: интерактивный режим без TTY не работает. `agy agents` без входа ничего не печатает | 1.5.18 | 2026-11-26 |
| Вход без терминала | Режим печати (`agy -p … --output-format json`) без входа сам печатает: «Authentication required. Please visit the URL to log in: https://accounts.google.com/o/oauth2/auth?…redirect_uri=https://antigravity.google/oauth-callback…» (PKCE S256; области `cloud-platform`, `userinfo.email`, `userinfo.profile`, `cclog`, `experimentsandconfigs`, `aicode`, `openid`), затем «Waiting for authentication (timeout 60s)… Or, paste the authorization code here and press Enter:». Код читается из stdin без PTY; при закрытом stdin — «Error: authentication interrupted.» Признаки SSH не нужны. Ждёт всего 60 секунд. Принимается ли код и куда пишется вход — не проверено (нужен вход владельца) | 1.5.18 | 2026-11-26 |
| Ответ режима печати без входа | stdout: `{"conversation_id":"","status":"ERROR","response":"","error":"authentication failed or timed out","duration_seconds":0,"num_turns":0,"usage":{"input_tokens":0,"output_tokens":0,"thinking_tokens":0,"cache_read_tokens":0,"total_tokens":0},"finished_reason":"error"}` — в объекте есть поле `finished_reason`; код выхода 0 | 1.5.18 | 2026-11-26 |
| stream-json без входа | Событий `init` нет. stdout — одна строка `{"event":"result","result":{…"status":"ERROR","error":"authentication failed or timed out",…"finished_reason":"error"}}`; stderr — «Error: authentication required. Run 'agy' to log in, then retry.» и «error: authentication failed or timed out»; код выхода 1. Ссылку входа в этом режиме не печатает | 1.5.18 | 2026-11-26 |
| Журнал `--log-file` без входа | Строки вида `I1126 20:59:16.853715 1 resolver.go:85] Model ID gemini-3.8-flash-low not in local config, defaulting to CCPA`; «Starting language server process», «Language server listening on random port at … for HTTPS (gRPC)» и «… for HTTP» (loopback); «error getting token source: You are not logged into Antigravity.» | 1.5.18 | 2026-11-26 |
| Команда квоты | Без входа и `agy -p /usage`, и `agy -p=/quota` упираются во вход: какая из них верна, не установлено. Плагин subscription использует `/usage` | 1.5.18 | 2026-11-26 |
| ACP: `initialize` без входа | Отвечает: `protocolVersion: 1`, `agentInfo: Antigravity ACP Server 1.5.18`, `promptCapabilities: {image: true, audio: false, embeddedContext: true}`, сессии (`resume`, `close`, `load`, `list`), `auth.logout`. `authMethods`: `oauth-personal` (Log in with Google), `oauth-business` (Gemini Enterprise), **`gemini-api-key`** (ключ Gemini Developer API), **`agent-platform`** (Vertex с учётными данными по умолчанию). Флаг `agy acp --login` — «Log in with Google interactively, then exit (ACP terminal auth)»; `--process-per-session`; адрес API по умолчанию — `https://daily-cloudcode-pa.googleapis.com` | 1.5.18 | 2026-11-26 |
| Что `agy` создаёт в HOME | `~/.gemini/config/` (`config.json`, `mcp_config.json`, `projects/default-cli-project.json`), `~/.gemini/antigravity-cli/` (`cli.log`, `log/cli-<дата>.log`, `log/acp-server-….log`, `conversations/`, `conversation_summaries.db`, `installation_id`, `jetski_state.pbtxt`, `cache/`, `brain/`, `builtin/skills/…`, `crashes/`) | 1.5.18 | 2026-11-26 |
| Хосты (по строкам вывода и справке) | `accounts.google.com` (вход), `antigravity.google` (возврат после входа), `daily-cloudcode-pa.googleapis.com` (API). Полный список — только перехватом CONNECT при настоящем входе | 1.5.18 | 2026-11-26 |

Не проверено без входа владельца: приём кода входа и файл входа; события успешного хода и вызова инструмента; свой
агент (грузится ли, маркер отката); `agy models` и уровни усилия; формат квоты; `AGY_CLI_MODEL_API_MAX_RETRIES`; предел
одного сообщения; `@путь`; память процесса при ходе модели.

## 16. Что не подтверждено по коду

- **Вид событий настоящего `agy`.** Примеры JSON в разделе 6 — из поддельного CLI (`tests/fake_agy.py`) и из того,
  какие поля читает `transport.py`. Трасс настоящего `agy` в репозитории нет; полей у настоящих событий может быть
  больше. Поля `init.tools` и `init.permission_mode` есть только в поддельном CLI, плагин их не читает.
- **Состояния шага `tool`, кроме `ACTIVE`.** `DONE` и `ERROR` названы только в описании `audit_trace.py`; примера
  нет.
- **Назначение `--disable-slash-commands` и поля `subagent: false`** в репозитории не объяснено.
- **Все замеры и наблюдения за CLI** (17 инструментов и 24 749 токенов агента по умолчанию, отказ на `tools: [...]`,
  отсутствие схем MCP в промпте, последовательная выдача вызовов, предел 128 КиБ на аргумент, доля кеша, 416 МБ,
  обрезание на 65–69 тыс. токенов) — утверждения документации автора; кодом и тестами они не воспроизводятся.
- **Где `agy` хранит вход** и что нужно перенести в контейнер, чтобы CLI был вошедшим, — в репозитории нет: плагин
  к файлам входа не обращается, известен только каталог данных `~/.gemini/antigravity-cli`.
- **Работа за прокси** — только фраза о том, что окружение `agy` должно быть у процесса хоста; своего кода для сети
  в плагине нет.
- **Не прочитаны целиком:** `scripts/bench.py` (кроме разбора трассы), `tests/test_perf_prewarm.py`,
  `tests/test_model_switch.py` (только имена тестов), `docs/install.md`, `README.zh-CN.md`. Раздел 3.10
  `docs/design.md` (команда `/model` и шлюз Hermes) к Меркурию не относится и в конспект не вошёл.
