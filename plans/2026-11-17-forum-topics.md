# Форум: темы — проекты — план

**Цель:** в группе-форуме у каждой темы своя беседа и свой проект; агент сам ведёт темы по проектам.

**Спецификация:** [BRIEF.md → Форум: темы — проекты](../BRIEF.md#форум-темы--проекты). Опора: hermes
`gateway/session.py` `build_session_key` (ключ — чат + тема, беседа темы общая для её участников),
`plugins/platforms/telegram/adapter.py` `_create_dm_topic` / `rename_dm_topic` (ошибки создания темы: нет форума,
имя занято).

## Глобальные ограничения

- Сквозные требования роадмапа; миграции только добавляют схему.
- Тема General и личный чат — `thread_id = ''` (как сейчас).
- Создание, переименование, закрытие и открытие темы — `write`; удаление темы — `irreversible`.

## Задача 1: беседа и активный проект по теме

- Миграция 0031: `conversations.thread_id text NOT NULL DEFAULT ''`, уникальный индекс открытой беседы
  `(channel, chat_id, thread_id)`; `chat_projects.thread_id` в первичный ключ.
- `ConversationRepository::openConversation(principalId, channel, chatId, threadId = '')`, `close(channel, chatId,
  threadId = '')`; `ConversationMeta::$threadId`.
- `ProjectRepository::active/activate(channel, chatId, ?, threadId = '')`.
- `ChatService` открывает беседу по теме; `RunScheduler` берёт тему из meta (после перезапуска ответ остаётся в теме).
- Тесты: два сообщения в разных темах — две беседы; ответ после возобновления уходит в тему; проект активен в своей теме.

## Задача 2: методы тем в `messenger`

- `Channel`: `createTopic(chatId, name): ?string`, `editTopic`, `closeTopic`, `reopenTopic`, `deleteTopic` — bool;
  Telegram: `createForumTopic` и др. Ошибки — `null`/`false` и запись в журнал, без исключений.
- Тесты на фейковом сервере Telegram.

## Задача 3: проекты ведут темы

- `ForumTopics` (App): проект, созданный в форуме, получает тему и связь «чат + тема → проект»; переименование,
  завершение и повторное открытие проекта меняют тему; тема, созданная человеком (`forum_topic_created`), —
  проект-черновик с её именем и сообщение в теме.
- Инструмент `forum_topic` с действием `delete` (irreversible, карточка владельца).
- Тесты: создание проекта в форуме создаёт тему; закрытие проекта закрывает тему; тема человека даёт проект.
