---
title: "kimi-code — официальный клиент Kimi Code: как он ходит в API подписки и платформы"
type: repository
source: https://github.com/MoonshotAI/kimi-code
local: references/kimi-code/
commit: a6ac07d
reviewed: 2026-11-28
---

# kimi-code — официальный клиент Kimi Code

Формат ссылок: `<файл>:L<от>-L<до> (имя)` — сокращение от `references/kimi-code/<файл>@a6ac07d:L<от>-L<до>`; в коде
Меркурия ссылка пишется полностью, с префиксом `ref:`. Репозиторий — монорепозиторий на TypeScript (MIT): CLI
`kimi`, настольное приложение, расширение VS Code, сервер ACP. Он большой (~7 тыс. файлов); **Меркурию нужны два
каталога** — `packages/core/src/llm/builtin/` (протоколы и провайдер `kimi`) и `packages/oauth/src/` (вход, список
моделей, квота). Остальное не читалось.

## 1. Зачем Меркурию

Источник фактов об API подписки Kimi Code, которых нет в документации или которые в ней расходятся: запрос квоты,
разбор списка моделей, коды исчерпания, адреса регионов, форма запроса мышления. Провайдеры Меркурия `moonshot:`
(платформа) и `kimi:` (подписка) — [Kimi: ключ API и подписка](../../brief/models.md#kimi-ключ-api-и-подписка).

## 2. Адреса и регионы

| Что | Где | Суть |
|---|---|---|
| Платформа по умолчанию | `packages/core/src/llm/builtin/provider/kimi/protocol.ts:L30` | `https://api.moonshot.ai/v1` |
| Подписка, два региона | `packages/oauth/src/region.ts:L29-L46` (`KIMI_REGION_PROFILES`) | `mainland-cn` — `https://api.kimi.com/coding/v1`, вход `auth.kimi.com`; `global` — `https://api.kimi.ai/coding/v1`, вход `auth.kimi.ai` |
| Предустановки | `packages/app-core/src/lib/providerPresets.ts:L101-L104` | те же два адреса подписки под разными именами |
| Переопределение | `packages/oauth/src/managed-usage.ts:L17-L19` (`kimiCodeBaseUrl`) | `KIMI_CODE_BASE_URL` |

Ключи и адреса не взаимозаменяемы: ключ подписки на адресе платформы и наоборот — 401 (справочник ошибок,
[kimi-api.md](kimi-api.md), раздел 5).

## 3. Список моделей: `GET {адрес}/models`

Оба адреса отдают модели **с признаками** — каталог Меркурия строится из ответа, без таблиц в коде.

- **Подписка** — `packages/oauth/src/managed-kimi-code.ts:L394-L431 (toModelInfo)`, `L433-L472 (parseModelReasoning)`,
  `L503-L538 (fetchManagedKimiCodeModels)`. Запись: `id`, `display_name`, `limit.context` (окно; без него запись —
  ошибка), `modalities.input` (`image`, `video`), `reasoning.type` (`only` — думает всегда, `both`, `none`),
  `reasoning.effort.valid` и `reasoning.effort.default`, `supports_tool_use` (по умолчанию да), `tools.dynamic_load`,
  `protocol`. Ответ 401/402/403 на список — ошибка доступа (`L521-L529`).
- **Платформа** — `packages/oauth/src/open-platform.ts:L47-L74 (toModelInfo)`, `L107-L134 (fetchOpenPlatformModels)`.
  Запись: `id`, `context_length`, `supports_reasoning`, `supports_image_in`, `supports_video_in`, `supports_tool_use`,
  `supports_thinking_type` (`only`/`both`/`no`), `think_efforts` (`support`, `valid_efforts`, `default_effort`),
  `display_name`. Цен в списке нет.

## 4. Запрос модели (Chat Completions)

`packages/core/src/llm/builtin/provider/kimi/protocol.ts:L141-L175 (kimiOpenAIProtocol)`:

- **Мышление** — `L77-L88 (kimiOpenAIThinkingParams)`: клиент шлёт `thinking: {type: "enabled", effort, keep}` либо
  `{type: "disabled"}`. **Расходится с документацией:** справка Kimi Code (модели) и документация платформы для K3 и
  K3.1 описывают верхнее поле `reasoning_effort` и запрещают `thinking` у K3. Меркурий идёт по документации
  (`reasoning_effort`); форма клиента — запасная, если проверка вживую покажет отказ.
- **Потолок ответа** — `L150`: `max_completion_tokens`; `L68-L75 (fillKimiMaxOutputTokens)` — остаток окна.
- **Инструменты** — `L51-L66 (convertKimiTool)`: имя на `$` — встроенный инструмент (`builtin_function`), иначе
  `function` со схемой через `normalizeKimiToolSchema`.
- **Ход ассистента с вызовами** — `L90-L121 (applyKimiMessageExtras)`: пустой текст (без учёта рассуждения)
  убирается из сообщения целиком.
- **Идентификатор запроса** — заголовок ответа `x-trace-id` (`L142`).
- **Возврат рассуждения** — `packages/core/src/llm/builtin/protocol/openai/lower.ts:L112-L212 (lowerMessage)`:
  рассуждение хода уходит обратно полем `reasoning_content`; при `keep: "all"` (`preserveThinking`) поле ставится
  у каждого хода ассистента, даже пустое (`L204-L210`).
- **Разбор ответа** — `packages/core/src/llm/builtin/protocol/openai/decode.ts:L77-L83` (`reasoning_content`,
  `reasoning_details`, `reasoning`), `L188-L200 (parseUsage)`: чтение из кеша — `usage.cached_tokens` либо
  `usage.prompt_tokens_details.cached_tokens`, `prompt_tokens` включает кеш. В потоке usage ищется и в
  `choices[0].usage` (`L318`).

## 5. Схемы инструментов

`packages/core/src/llm/builtin/provider/kimi/schema.ts:L97-L99 (normalizeKimiToolSchema)`: раскрыть локальные `$ref`
(`L3-L14 derefJsonSchema`, `L129-L170`), затем каждому узлу без `type` (кроме `$ref`, `anyOf`, `oneOf`, `allOf`,
`if`/`then`/`else`, `not`) вывести тип по `enum`/`const` или по ключам структуры (`L263-L331`); у узла с `type`,
противоречащим `enum`, тип исправляется (`L277-L296`). Смешанные типы в `enum` — ошибка. Сверено с hermes
`agent/moonshot_schema.py`: hermes добавляет `required` у объектов, убирает ветви `null` в `anyOf`, `nullable` и
`null`/`""` из перечислений — в Меркурии взято объединение обоих.

## 6. Ошибки

`packages/core/src/llm/builtin/provider/kimi/errors.ts:L29-L56 (classifyKimiQuotaError)`: 429 с кодом или типом
`exceeded_current_quota_error` (ищется на трёх уровнях вложенности) — **исчерпанная квота, не повторять**; время —
из `Retry-After`. Прочие 429 — обычная частота. Полный список текстов подписки — справочник ошибок Kimi Code
([kimi-api.md](kimi-api.md), раздел 5).

## 7. Квота подписки: `GET {адрес}/usages`

`packages/oauth/src/managed-usage.ts`: адрес — `L21-L23 (kimiCodeUsageUrl)`; запрос — `L218-L256 (fetchManagedUsage)`,
`Authorization: Bearer <токен>`, тайм-аут 8 с, 401 — ключ, 404 — адрес без квоты. Ответ (`L108-L153`):
`usages.limit_5h`, `limit_7d`, `limit_month_total`, `limit_month_code` — каждое `{used_ratio, reset_time}`;
`boosterWallet` — кошелёк «Extra Usage» (`L155-L185`: `balance.type = BOOSTER`, `amount`, `amountLeft` в миллионных
долях цента, `monthlyChargeLimit`, `monthlyUsed`). **Клиент шлёт сюда OAuth-токен;** примет ли адрес ключ консоли —
не проверено (ждёт ключа владельца).

## 8. Чего Меркурий не берёт

- **Заголовки устройства** — `packages/oauth/src/identity.ts:L56-L69 (createKimiDeviceHeaders)`: `X-Msh-Platform`
  (`kimi_code_cli`, `L8`), `X-Msh-Version`, `X-Msh-Device-Name` (имя хоста), `X-Msh-Device-Model`,
  `X-Msh-Os-Version`, `X-Msh-Device-Id` (UUID в файле `device_id`); `User-Agent` клиента — `L71-L75`. Повторить их —
  назваться официальным клиентом, что правила Kimi Code запрещают; Меркурий шлёт свой `User-Agent`.
- Вход OAuth (`packages/oauth/src/oauth*.ts`), телеметрия, инструменты клиента, Messages и Responses API.

## 9. Не прочитано

`files.ts` (загрузка файлов `ms://`), путь Anthropic (`kimiAnthropicProtocol`, `L177-L192`) и Responses, обновление
OAuth-токена, `refreshProviderModels.ts` сверх разбора записей, сервер ACP.
