# Mercurius

**English** | [Русский](README.ru.md)

Mercurius is an autonomous personal agent. You run it on your own server with Docker Compose and talk to it through a Telegram bot. It is meant to help with any kind of work: searching the web, working with servers over SSH, writing code and running software projects, planning, research and testing.

**Status:** runs on the owner's server. Development phases 0–9 and 5a are done; phases 10 (scheduler, groups, channel, forum), 12 (delivery) and 13 (agent network) are in progress. The plan is in [`ROADMAP.md`](ROADMAP.md) (in Russian).

## What works now

- **Telegram:** webhook or long polling, streamed answers, Markdown, long messages split up, files and buttons. The agent sees message numbers, the message a reply answers and the run that wrote its own messages.
- **Agent loop:** tools, limits on turns, tokens and time enforced in code, runs that survive a restart, context compaction, model roles chosen per request (OpenRouter and the OpenAI API).
- **Approvals:** irreversible actions (`git push`, `ssh`, uploads, non-GET HTTP, posts in a channel, a request carrying a secret) wait for the owner's card; "allow always" saves a grant for that exact action and target.
- **Participants:** strangers wait for the owner's decision (also strangers seen in a group); each participant has their own tools, limits, projects and memory.
- **Memory:** layers of facts with sources, search by meaning, `/memory`, `/forget`, nightly consolidation.
- **Servers:** a vault of secrets, a registry of servers, SSH with a command policy and a log, routes through VLESS.
- **Internet:** web search through SearXNG, Brave, Tavily or a model with web search; reading pages and documents; a browser in its own container.
- **Skills and MCP:** a skill catalog with versions and the owner's approval; ordinary `SKILL.md` skills of other agents imported from GitHub or an archive with their files; MCP servers over HTTP with tool filters and a circuit breaker; built-in mail (IMAP/SMTP) as an MCP server.
- **Scheduler and projects:** jobs by a schedule or a signed webhook, quiet hours, a daily digest; projects with tasks and deadlines.
- **Groups:** work in groups the owner approves (on mention or freely); a forum where each topic is a project; posts in a channel through the owner's card.
- **Delegation:** worker agents with a clean context, a model per part, a verifier for costly parts, background work.
- **Agent network:** Mercurius instances give each other tasks over A2A, known by their keys.
- **Operation:** settings in the dialog (`/settings`, `/set`, `/restart`), the agent's language (`AGENT_LANGUAGE`, Russian by default), traces and spend, Prometheus metrics, backup and restore (`bin/backup.sh`), an installation check (`doctor`), an evaluation set.

## Planned

Self-improvement from its own traces; a second channel (REST/WebSocket API); MCP servers over stdio in the sandbox. Details are in [`ROADMAP.md`](ROADMAP.md).

## Running it

1. Clone with the package submodules: `git clone git@github.com:opencck/mercurius.git && cd mercurius && git submodule update --init --depth 1 packages/llm packages/connectors packages/messenger packages/search packages/mail-mcp packages/i18n`.
2. Copy `.env.example` to `.env` and fill it in: at least `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_ID` (your Telegram user id), `OPENROUTER_API_KEY` or `OPENAI_API_KEY` (one is enough), `POSTGRES_PASSWORD`, `SANDBOX_TOKEN`. Every variable is explained in the file; most settings can later be changed in the dialog.
3. `docker compose up -d --build`. The app applies database migrations at start.
4. Write to your bot. Commands: `/new`, `/stop`; for the owner also `/settings`, `/set`, `/restart`, `/permissions`, `/usage`, `/runs`, `/trace <id>`, `/delegation`, `/memory`, `/forget`, `/consolidate`, `/projects`, `/project`, `/secret`, `/secrets`, `/servers`, `/audit`, `/skills`, `/skill`, `/mcp`, `/jobs`, `/hook`, `/digest`, `/doctor`, `/peers`, `/peer`. `/start` lists them.

## Stack

PHP 8.2+, AMPHP v3 / Revolt, [`opencck/server`](https://github.com/opencck), PostgreSQL with pgvector; models through OpenRouter and the OpenAI API. Every repository runs PHPUnit, PHPStan and Psalm (`composer check`).

## Repositories

Components useful outside Mercurius live in their own repositories and are included as submodules in `packages/`:

| Repository | Purpose |
|------------|---------|
| [`mercurius-llm`](https://github.com/opencck/mercurius-llm) | A unified interface for calling large language models: messages, tools, streaming, token and cost accounting, errors and retries. |
| [`mercurius-connectors`](https://github.com/opencck/mercurius-connectors) | Outbound connection routes as an Amp `SocketConnector` factory: direct, HTTP CONNECT proxy, SOCKS5, VLESS (via `amphp/vless`) and chains of them. |
| [`mercurius-messenger`](https://github.com/opencck/mercurius-messenger) | A unified messaging channel interface with a normalized message model, and a Telegram Bot API adapter (webhook and long polling, groups, forum topics, channel posts). |
| [`mercurius-search`](https://github.com/opencck/mercurius-search) | Web search behind one interface: SearXNG, Brave, Tavily and a model with web search, with fallback and a cache. |
| [`mercurius-mail-mcp`](https://github.com/opencck/mercurius-mail-mcp) | An MCP server for mail with its own IMAP and SMTP clients. |
| [`mercurius-i18n`](https://github.com/opencck/mercurius-i18n) | The texts of the agent in Russian and English: a translator, catalogs, plural forms and a parity check. |

Components that may move to their own packages later: command policy, memory, the browser service, the MCP client, the agent network.

## Repository layout

| Path | Contents |
|------|----------|
| [`BRIEF.md`](BRIEF.md) | Specification: requirements, design, decisions (in Russian) |
| [`ROADMAP.md`](ROADMAP.md) | Development phases and their status (in Russian) |
| [`CLAUDE.md`](CLAUDE.md) | Working rules for development with Claude Code (in Russian) |
| `index.php`, `src/`, `migrations/`, `test/` | The agent: entry point, code in opencck layers, database migrations, tests |
| `sandbox.php`, `src/Sandbox/` | The sandbox executor |
| `browser/` | The browser service (Chrome behind an HTTP API) |
| `bin/` | Backup, restore and the installation check |
| `docs/`, `notes/` | Knowledge base on building autonomous agents: articles and notes |
| `references/` | Reference repositories as git submodules |

## License

[MIT](LICENSE)
