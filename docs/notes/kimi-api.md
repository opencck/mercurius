---
title: "Kimi Platform и Kimi Code — API, модели, мышление, ошибки, условия подписки"
type: articles
source: https://platform.kimi.ai/docs, https://www.kimi.com/code/docs/en/, https://www.kimi.com/en/help/kimi-code/
fetched: 2026-11-28
---

# Kimi Platform и Kimi Code

Конспект статей из `docs/` (скачаны 2026-11-28): `kimi-platform-*.md` — документация платформы (оплата за токен),
`kimi-code-*.md` — справка и документация подписки Kimi Code. Ссылки — `<файл>:L<строки>`. Как это легло в Меркурий —
[Kimi: ключ API и подписка](../../brief/models.md#kimi-ключ-api-и-подписка); код официального клиента —
[kimi-code.md](kimi-code.md).

## 1. Два продукта

| | Kimi Platform | Kimi Code (подписка) |
|---|---|---|
| Адрес Chat Completions | `https://api.moonshot.ai/v1` (`kimi-platform-chat-api.md`) | Китай `https://api.kimi.com/coding/v1`, остальной мир `https://api.kimi.ai/coding/v1` (`kimi-code-models.md:L82-L87`) |
| Вход | `Authorization: Bearer <ключ>`; ключи разных платформ не взаимозаменяемы — 401 (`kimi-platform-errors.md:L38-L45`) | Ключ из консоли Kimi Code, до 5 ключей, показывается один раз (`kimi-code-overview.md:L131`) |
| Модели | `kimi-k3.1-pro`, `kimi-k3.1-pro-highspeed`, `kimi-k3`; K2 (`kimi-k2.7-code`, `kimi-k2.6`) — только старым аккаунтам, снимаются в конце декабря 2026 | `k3.1-pro`, `k3.1-pro-highspeed`, `k3`, `k3-256k`, `kimi-for-coding` (K2.8 Preview), `kimi-for-coding-highspeed` (`kimi-code-models.md:L29-L53`) |
| Окна | K3 — 1 048 576, K2 — 262 144 (`kimi-platform-pricing-chat.md`) | до 1 048 576 по плану; `k3-256k` и `kimi-for-coding-highspeed` — 262 144; Plus — 262 144 у K3 и K3.1 (`kimi-code-hermes.md`, таблица планов) |
| Медиа | `image_url` и `video_url` base64 (`kimi-platform-chat-api.md`) | картинка и видео у всех, кроме `k3-256k` (только картинка) (`kimi-code-models.md:L53`) |
| Список моделей | `GET /v1/models` с `context_length`, `supports_image_in`, `supports_video_in`, `supports_reasoning` (`kimi-platform-list-models.md:L68-L71`) | `GET /coding/v1/models` (формат — [kimi-code.md](kimi-code.md), раздел 3) |
| Остаток | `GET /v1/users/me/balance` → `available_balance` в USD; при ≤ 0 — `exceeded_current_quota_error` (`kimi-platform-balance.md:L59-L82`) | `GET /coding/v1/usages` ([kimi-code.md](kimi-code.md), раздел 7) |

## 2. Мышление

- K3 и K3.1 думают всегда; уровень — **верхнее поле `reasoning_effort`**: `low`/`high`/`max`; параметр `thinking` им
  не передаётся (`kimi-platform-thinking-models.md:L19-L33`, `L78-L80`). По умолчанию на платформе `max`, на
  подписке `high` у K3 и K3.1, `max` у `kimi-for-coding` (`kimi-code-models.md:L39`).
- Подписка принимает и чужие значения: `ultra`/`max`/`xhigh` → `max`, `high`/`medium` → `high`, `low`/`minimum`/
  `light` → `low`; неизвестное — 400; `off`/`none`/`disabled` — 400 у K3.1, а у остальных запрос молча уходит на
  K2.8 Preview без мышления (`kimi-code-models.md:L93-L110`). Значит, выключать мышление нельзя — ни одной модели.
- K2 на платформе: `thinking: {type: "enabled"|"disabled", keep: null|"all"}`; у `kimi-k2.7-code` только `enabled`
  (`kimi-platform-chat-api.md:L409-L416`).
- **Рассуждение возвращается:** ход ассистента — целиком, с `reasoning_content` (`kimi-platform-thinking-models.md:L303-L311`);
  без него на подписке — 400 «thinking is enabled but reasoning_content is missing in assistant tool call message»
  (`kimi-code-error-reference.md:L439-L451`).
- `max_tokens` включает рассуждение; советуют не меньше 16 000 (`kimi-platform-thinking-models.md:L89`, `L96`).
  `temperature` у K3 закреплена на 1.0 — не передавать (`L97`).
- Смена модели или уровня внутри беседы сбрасывает кеш и смешивает цепочки рассуждений (`kimi-code-models.md:L63`).

## 3. Кеш и usage

Кеш неявный, время жизни 5 минут или час (`prompt_cache_options: {mode: "implicit", ttl}`), свои точки кеша не
принимаются (`kimi-platform-context-caching.md:L66-L101`). Usage: `prompt_tokens` (включая кеш),
`prompt_tokens_details.cached_tokens` — чтение, `prompt_tokens_details.cache_write_tokens` — запись (`L128`). В потоке
последний кадр — `choices: []` с `usage` (`kimi-platform-chat-api.md:L233`).

## 4. Условия подписки

- Сторонние клиенты разрешены (`kimi-code-overview.md:L47`); среди примеров — OpenClaw и Hermes Agent, для Hermes
  у Kimi есть своя инструкция (`kimi-code-hermes.md`: свой провайдер `kimi-coding-plan`, адрес
  `api.kimi.ai/coding/v1`, `reasoning_effort` в теле, без подмены `User-Agent`).
- **Нельзя:** менять идентификатор клиента (`User-Agent`) (`kimi-code-overview.md:L143`,
  `kimi-code-community-guidelines.md:L29`); перепродавать доступ или делать из него сервис (`L21-L27`);
  **неинтерактивная автоматизация** — «Kimi Code subscriptions are for personal interactive use only», пакетные
  скрипты и конвейеры разметки (`L15`, `L33-L35`).
- «designed specifically for programming scenarios»; для своих продуктов — Kimi Platform (`kimi-code-overview.md:L147`).
- Нарушение — ограничение одновременных запросов (`kimi-code-community-guidelines.md:L37`), ошибка «You've reached
  your concurrent request limit». VPN и прокси в сторону Kimi — можно; общий доступ через обратный прокси — нет.
- Квота: скользящее окно 5 часов; у прежних планов ещё 7 дней; общая месячная квота всех услуг Kimi; после
  исчерпания — «Extra Usage» с предоплатой (`kimi-code-benefits.md:L29`, `L59-L71`).

## 5. Ошибки

**Платформа** (`kimi-platform-errors.md:L26-L80`), тело `{"error": {"type", "message"}}`:

| HTTP | `error.type` | Что делать |
|---|---|---|
| 400 | `content_filter` | не повторять (фильтр) |
| 400 | `invalid_request_error` | не повторять; «Input token length too long» — переполнение окна |
| 401 | `invalid_authentication_error`, `incorrect_api_key_error` | ключ |
| 403 | `permission_denied_error` | нет доступа |
| 404 | `resource_not_found_error` | нет модели или нет доступа к ней |
| 429 | `engine_overloaded_error` | повторять после `Retry-After` |
| 429 | `exceeded_current_quota_error` | баланс или квота — не повторять |
| 429 | `rate_limit_reached_error` | частота (RPM, TPM, одновременные; TPD — до завтра) |
| 500, 503 | `server_error`, `server_unavailable` | повторять |

**Подписка** (`kimi-code-error-reference.md:L23-L99`), текст вида `error, status code: N, message: …`:

- 401 — ключ неверен или отозван, «Invalid Authentication» (часто — ключ платформы на адресе подписки), аккаунт
  не найден (`L125-L176`);
- 403 — модель или окно 1M не входят в план («Your current subscription does not have access to k3», «Your current
  plan supports only k3 up to 256K context»), «unable to verify your membership benefits» (`L177-L275`); не повторять;
- 429, квота — не повторять: «You've reached your 5-hour usage limit», «…weekly (7-day) usage limit», «…monthly
  usage limit for this billing cycle», «Your credit balance is insufficient», исчерпание «Extra Usage» организации
  (`L276-L360`); «You've reached your concurrent request limit» — ограничение за нарушение правил (`L361-L376`);
- 429, временное — повторять: «We're receiving too many requests», «The engine is currently overloaded» (`L377-L404`);
- 400: тело сообщений больше **2 МБ** («total message size N exceeds limit 2097152»), окно, нет
  `reasoning_content`, K3.1 без мышления, неизвестная модель, плохой адрес картинки, повтор имени инструмента
  (`L405-L517`);
- 404 — «Not found the model … or Permission denied», «method not found» (`L518-L546`); 500/502 — повторять.

**Ошибочный идентификатор модели на подписке молча уходит на K2.8 Preview без мышления** (`kimi-code-models.md:L61`) —
каталог берётся только из `/models`.
