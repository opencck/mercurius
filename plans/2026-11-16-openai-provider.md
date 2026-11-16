# Провайдер OpenAI: план

> Исполнение — superpowers:executing-plans, задачи по порядку, тест вперёд.

**Цель:** модели OpenAI доступны агенту напрямую; приложение работает с любым одним из двух ключей.

**Спека:** [BRIEF.md → Провайдеры моделей: OpenRouter и OpenAI](../BRIEF.md#провайдеры-моделей-openrouter-и-openai).

## Ограничения

- Пакет `llm` — отдельный репозиторий: коммит там, README и README.ru вместе, запись в `packages/README.md` и
  `references/README.md`, затем `composer reinstall opencck/mercurius-llm` в основном.
- Ключи не печатаются; проверка на сервере читает `/v1/models` из контейнера и выводит только идентификаторы.
- Голый id модели по-прежнему значит OpenRouter — старые записи в базе не ломаются.

## Задача 1: пакет `llm` — клиент и каталог OpenAI

**Файлы:** `packages/llm/src/OpenRouter/PayloadMapper.php` (диалект), `src/OpenAI/OpenAIClient.php`,
`src/ModelCatalog/OpenAICatalog.php`, `src/OpenRouter/OpenRouterClient.php` (общий поток вынести, если без этого
дублирование), тесты `test/Unit/OpenAIClientTest.php`, `test/Unit/OpenAICatalogTest.php`.

- [ ] Тесты: тело запроса OpenAI — `max_completion_tokens`, `reasoning_effort: "low"`, нет `temperature` при усилии,
      нет `max_tokens`, нет `reasoning`; заголовки без `x-title`/`http-referer`; поток разбирается как у OpenRouter;
      ошибки с префиксом «OpenAI»; каталог берёт только модели, у которых есть запись `openai/<id>` в каталоге
      OpenRouter, и переносит цены, окно и индексы; `:batch` и прочие варианты не сопоставляются.
- [ ] Реализация; `composer check` в пакете зелёный; README обоих языков; коммит `feat: add an OpenAI client and catalog`.

## Задача 2: Меркурий — настройки, маршрутизация, роли, выбор

**Файлы:** `src/Infrastructure/Config/LlmSettings.php`, `src/Domain/Agent/ModelRef.php`, `src/App/Agent/Model/ModelRoles.php`,
`src/App/Agent/Model/ModelSelector.php`, `src/App/Agent/Tool/SetModelRoleTool.php`, `index.php`, `.env.example`, тесты.

- [ ] Тесты: без обоих ключей — `ConfigException`; только OpenAI — модель по умолчанию `openai:…`; `ModelRef::OPENAI`;
      роли по умолчанию по провайдерам; `set_model_role` отказывает модели ненастроенного провайдера; выбор по профилю
      находит модель из каталога OpenAI.
- [ ] Реализация: `LlmSettings::providers()`, ключи по провайдерам; `ProviderRouter` — только настроенные; источники
      каталога — по ключам; `ModelSelector` получает список провайдеров.
- [ ] `composer check` зелёный, коммиты `feat(llm): …`, `chore(deps): …`.

## Задача 3: проверка на сервере

- [ ] Развернуть; в журнале — `Model catalog loaded` для `openai` с числом моделей.
- [ ] Из контейнера `GET /v1/models` (через тот же маршрут) — сверить идентификаторы ролей по умолчанию для режима
      «только OpenAI», поправить, если расходятся.
- [ ] В Telegram: поручение воркеру на модели `openai:…` (явным профилем или временной ролью) — ответ пришёл, в `/trace`
      стоимость с пометкой «оценка», запрос шёл через VLESS (журнал `Model route`).
- [ ] BRIEF: «Решено» по провайдеру, ROADMAP: второй провайдер из фазы 12 сделан заранее; план удалить.
