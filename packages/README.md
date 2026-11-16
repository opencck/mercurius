# packages/ — индекс пакетов Меркурия

Компоненты Меркурия, вынесенные в отдельные репозитории `github.com/opencck/mercurius-<имя>`, подключены сюда
git-сабмодулями (ветка `master`). Правила — в [CLAUDE.md](../CLAUDE.md) (пункт «Пакеты») и
[BRIEF.md → Качество кода и CI](../BRIEF.md#качество-кода-и-ci).

## Подключённые пакеты

| Пакет | Каталог | Коммит | Проверено | Назначение |
|-------|---------|--------|-----------|------------|
| [mercurius-llm](https://github.com/opencck/mercurius-llm) | [llm/](llm/) | `cdc9a0b` | 2026-11-16 | Единый интерфейс вызова LLM, адаптер OpenRouter |
| [mercurius-connectors](https://github.com/opencck/mercurius-connectors) | [connectors/](connectors/) | `6920581` | 2026-11-16 | Маршруты исходящих соединений: напрямую, HTTP CONNECT, SOCKS5, VLESS |
| [mercurius-messenger](https://github.com/opencck/mercurius-messenger) | [messenger/](messenger/) | `a1a2f2a` | 2026-11-16 | Канал связи и адаптер Telegram |

Остальные пакеты (`command-policy`, `memory`, `browser-service`, `mcp-client`, `peer`) подключаются в своих фазах.

## Как работать с пакетом

- Код пакета правится в его каталоге и коммитится в его репозиторий (`master`), затем в основном репозитории
  коммитится новый указатель сабмодуля.
- В основном `composer.json` пакеты подключены Composer-репозиториями типа `path`, поэтому приложение сразу видит
  изменения в `packages/<имя>/`.
- В Docker-образ пакеты попадают при сборке из каталога `packages/` (после `git submodule update --init`).
- У каждого пакета свой `composer check` и свой CI.

## Журнал

### 2026-11-16 — подключены llm, connectors, messenger

- `mercurius-llm` `cdc9a0b`: `LlmClient`, потоковый клиент OpenRouter, `RetryingClient`, каталог моделей.
- `mercurius-connectors` `6920581`: маршруты напрямую, HTTP CONNECT, SOCKS5, VLESS и их цепочки.
- `mercurius-messenger` `a1a2f2a`: `Channel`, адаптер Telegram (вебхук, long polling, разбиение сообщений, очередь по чатам).
