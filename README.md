# Mercurius

**English** | [Русский](README.ru.md) | [中文](README.cn.md)

Mercurius is an autonomous personal agent. You run it on your own server with Docker Compose and talk to it through a Telegram bot. It is meant to help with any kind of work: searching the web, working with servers over SSH, writing code and running software projects, planning, research and testing.

**Status:** runs on the owner's server and on four test instances. The development and the finishing work after a review and a security audit are done; their outcome, the decisions and what still waits for the owner are in [`brief/history.md`](brief/history.md) (in Russian).

## What works now

- **Telegram:** webhook or long polling, streamed answers, Markdown, long messages split up, files and buttons. The agent sees message numbers, the message a reply answers and the run that wrote its own messages.
- **Agent loop:** tools, limits on turns, tokens and time enforced in code, runs that survive a restart, context compaction, model roles chosen per request (OpenRouter and the OpenAI API).
- **Approvals:** irreversible actions (`git push`, `ssh`, uploads, non-GET HTTP, posts in a channel, a request carrying a secret) wait for the owner's card; "allow always" saves a grant for that exact action and target.
- **Participants:** strangers wait for the owner's decision (also strangers seen in a group); each participant has their own tools, limits, projects and memory.
- **Memory:** layers of facts with sources, search by meaning, `/memory`, `/forget`, nightly consolidation.
- **Servers:** a vault of secrets, a registry of servers, SSH with a command policy and a log, routes through VLESS.
- **Internet:** web search through SearXNG, Brave, Tavily or a model with web search; reading pages and documents; a browser in its own container.
- **Skills and MCP:** a skill catalog with versions and the owner's approval; ordinary `SKILL.md` skills of other agents imported from GitHub or an archive with their files; MCP servers over HTTP and stdio in the sandbox, installed by the agent from a repository or an archive, each run by an entry of `.mcp.json` the owner approved, with secrets by name; SSH and mail (IMAP/SMTP) as MCPs that ship with it.
- **Scheduler and projects:** jobs by a schedule or a signed webhook, quiet hours; projects with a card, a folder, skills and jobs.
- **Groups:** work in groups the owner approves (on mention or freely); a forum where each topic is a project; posts in a channel through the owner's card.
- **Delegation:** worker agents with a clean context and their own rights (read or write), a model per part, a verifier for costly parts, background work.
- **Agent network:** Mercurius instances give each other tasks over A2A, known by their keys: a call that waits for the answer, parts given to several neighbours and merged, cancelling, deadlines, the spend carried along the chain.
- **WebSocket API:** a second channel: `GET /api/ws` with `Authorization: Bearer <key>`; the owner issues keys to participants with `/apikey new <participant>`; JSON frames of messages, buttons and files.
- **Self-improvement:** the agent reviews its own traces, proposes notes to its prompt and changes of model roles; a change passes the evaluation set and the owner's card, and can be rolled back (`/prompt`, `/models`, `/proposals`).
- **Export and import:** `/export <password>` packs the whole agent (database, data, working folders, settings) into an archive sealed by the password; `/import` replaces an agent with an archive, or takes only its memory or its skills.
- **Operation:** settings in the dialog (`/settings`, `/set`, `/restart`), the agent's language (`AGENT_LANGUAGE`, Russian by default), traces and spend, Prometheus metrics, backup and restore (`bin/backup.sh`, `bin/restore.sh`), update with one command (`bin/update.sh`), an installation check (`/doctor`), an evaluation set.

## Planned

Global skills of the agent in the owners' area; MCPs from private repositories over SSH; OAuth sign-in to external MCP servers. Open questions are in [`brief/decisions.md`](brief/decisions.md).

## Running it

You need a server with Docker and Docker Compose v2, about 4 GB of RAM and 10 GB of disk for the core (the optional
modules add their own), a Telegram bot from [@BotFather](https://t.me/BotFather) and a key of OpenRouter or the OpenAI
API.

1. Clone the code and the package submodules (reference repositories are not needed to run it):
   ```sh
   git clone https://github.com/opencck/mercurius.git && cd mercurius
   git submodule update --init --depth 1 packages/llm packages/connectors packages/messenger packages/search packages/i18n packages/mcp-client packages/memory packages/peer skills/skill-creator skills/memory-file
   ```
2. Copy `.env.example` to `.env` (mode `600`) and fill it in: at least `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_ID` (your
   Telegram user id), one key of a model provider (`OPENROUTER_API_KEY`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY`, `MOONSHOT_API_KEY`, `KIMI_CODE_API_KEY` for the Kimi Code subscription — the full list is in `.env.example`), `POSTGRES_PASSWORD`, `SANDBOX_TOKEN`
   (`openssl rand -hex 24`). Every variable is explained in the file; most settings can be changed later in the dialog.
   Without `APP_URL` the bot uses long polling and needs no public address; with it — a webhook behind your proxy.
3. `chmod 1777 storage/app storage/browser storage/whisper` — the containers write there as their own users. Then
   `docker compose up -d --build`. The app applies database migrations at start; `docker compose ps` shows `app`,
   `postgres` and `sandbox` as `healthy`.
4. Write to your bot. `/start` lists the commands; `/doctor` checks the installation (keys, migrations, sandbox,
   modules, webhook, disk) and says what to fix.

### Modules

Heavy and optional parts are modules: each lives in its own repository, is a submodule here and runs in its own
compose profile, off by default. Turn one on with its submodule, a profile in `COMPOSE_PROFILES` and its token in
`.env`; the agent gets its tool only while the service answers.

| Module | Submodule | Profile | `.env` | What the agent gets |
|---|---|---|---|---|
| [`mercurius-browser`](https://github.com/opencck/mercurius-browser) | `services/browser` | `browser` | `BROWSER_TOKEN` | Chromium: pages, clicks, scripts, screenshots |
| [`mercurius-media-video`](https://github.com/opencck/mercurius-media-video) | `services/media-video` | `media-video` | `MEDIA_VIDEO_TOKEN` | frames and sound of a video for models that do not watch video |
| [`mercurius-media-docs`](https://github.com/opencck/mercurius-media-docs) | `services/media-docs` | `media-docs` | `MEDIA_DOCS_TOKEN` | the text of office documents and PDF; new docx, xlsx, pptx |
| [`mercurius-claude-code`](https://github.com/opencck/mercurius-claude-code) | `services/claude-code` | `claude-code` | `CLAUDE_CODE_TOKEN`, `CLAUDE_CODE_UID`, `CLAUDE_CODE_GID` | Claude by the owner's subscription through the official Claude Code: models `claude:<model>`. Sign in with `/claude login` in the owner's private chat (or set `CLAUDE_CODE_OAUTH_TOKEN` from `claude setup-token`), then put the models into the category lists. Paid use beyond the plan is refused unless `CLAUDE_CODE_EXTRA_USAGE` allows it |
| [`mercurius-whisper`](https://github.com/opencck/mercurius-whisper) | `services/whisper` | `whisper` | `WHISPER_TOKEN` | speech to text on this host (faster-whisper); the profile and the token are enough: while the module answers and no speech model is named, speech is recognised by it |
| [`mercurius-skill-memory-file`](https://github.com/opencck/mercurius-skill-memory-file) | `skills/memory-file` | — | — | a built-in skill: how the agent keeps a participant's `MEMORY.md` within its limit |
| [`mercurius-skill-creator`](https://github.com/opencck/mercurius-skill-creator) | `skills/skill-creator` | — | — | a built-in skill: how the agent creates and improves its skills (loaded at the start, no service) |
| [`mercurius-mcp-ssh`](https://github.com/opencck/mercurius-mcp-ssh) | `mcp/ssh` | — | — | an MCP of the delivery, installed into the sandbox at start: an SSH server is an entry of `.mcp.json` |
| [`mercurius-mail-mcp`](https://github.com/opencck/mercurius-mail-mcp) | `mcp/mail` | — | — | an MCP of the delivery, installed into the sandbox at start: a mailbox (IMAP and SMTP) is an entry of `.mcp.json` |
| [`mercurius-telegram-bot-api`](https://github.com/opencck/mercurius-telegram-bot-api) | `services/telegram-bot-api` | `telegram-api` | `TELEGRAM_API_ID`, `TELEGRAM_API_HASH`, `TELEGRAM_API_URL` | files up to 2000 MB in Telegram |

For example, the browser and documents:

```sh
git submodule update --init --depth 1 services/browser services/media-docs
# .env: COMPOSE_PROFILES=browser,media-docs, BROWSER_TOKEN and MEDIA_DOCS_TOKEN (openssl rand -hex 24)
docker compose up -d --build
```

### Updating

```sh
bin/update.sh    # a backup first (a failed one stops the update), git pull, the module submodules, build and start
```

The same by hand:

```sh
bin/backup.sh                       # the database, data and .env; the last 7 copies stay in backups/
git pull --ff-only && git submodule update --init --depth 1 <the submodules you use>
docker compose up -d --build        # new migrations apply at start; they only add to the schema
```

New variables appear in `.env.example`; `/doctor` names the ones your `.env` lacks and the settings stored in the
dialog that this version no longer has. `bin/restore.sh backups/<time>` brings a copy back.

### Several instances on one host

Each instance is its own clone in its own folder with its own `.env`: `COMPOSE_PROJECT_NAME`, the bot token and keys,
`WEB_ALIAS` (its name in the proxy network `web`) and `SANDBOX_SUBNET` (a free `/24` for its sandbox network, for
example `172.31.252.0/24`). Images are shared: a build in any folder updates all of them.

When Docker on the host runs out of address pools for new networks, give the `browser` network a subnet in a local
file next to `docker-compose.yml` and add it to `COMPOSE_FILE`:

```yaml
# compose.local.yml — not in git
networks:
  browser:
    ipam:
      config:
        - subnet: 172.31.248.0/24
```

```sh
# .env
COMPOSE_FILE=docker-compose.yml:compose.local.yml
```

The data folder `storage/app` is a mount the application writes to as its own user (uid 10001): give it mode `1777`,
as `storage/browser`; `/doctor` checks it.

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
| [`mercurius-i18n`](https://github.com/opencck/mercurius-i18n) | The texts of the agent in Russian and English: a translator, catalogs, plural forms and a parity check. |
| [`mercurius-mcp-client`](https://github.com/opencck/mercurius-mcp-client) | An MCP client: JSON-RPC over Streamable HTTP, tools and resources by pages, files in tool results. |
| [`mercurius-memory`](https://github.com/opencck/mercurius-memory) | Memory for agents: facts with versions and provenance, recall by keys and HRR vectors, proposals the owner decides, a core layer, nightly consolidation; PostgreSQL and in-memory stores with their migrations. |
| [`mercurius-todo`](https://github.com/opencck/mercurius-todo) | The plan of a conversation: items with statuses and subtasks, replace or merge by id, the items still to do, stores in memory and on PostgreSQL. |
| [`mercurius-peer`](https://github.com/opencck/mercurius-peer) | Agent-to-agent over A2A: P-256 keys and signatures, a client (a card that proves its key, tasks, cancelling, files), a WebSocket with a handshake of keys. |

Modules — the browser, media, mail and the local Bot API server — are listed in [Modules](#modules). The repository [`mercurius-command-policy`](https://github.com/opencck/mercurius-command-policy) is reserved: its code still lives in this repository (`src/CommandPolicy/`).

## Repository layout

| Path | Contents |
|------|----------|
| [`BRIEF.md`](BRIEF.md) | Specification: requirements, design, decisions (in Russian) |
| [`CLAUDE.md`](CLAUDE.md) | Working rules for development with Claude Code (in Russian) |
| `bootstrap/index.php`, `src/`, `migrations/`, `test/` | The agent: entry point, code in opencck layers, database migrations, tests |
| `bootstrap/sandbox.php`, `src/Sandbox/` | The sandbox executor |
| `services/`, `mcp/` | Optional modules, each in its own repository and compose profile: browser, media-video, media-docs, whisper, claude-code, telegram-bot-api; MCPs of the delivery: ssh, mail |
| `bin/` | Backup, restore, update and the installation check |
| `docs/`, `docs/notes/` | Knowledge base on building autonomous agents: articles and notes |
| `references/` | Reference repositories as git submodules |

## License

[MIT](LICENSE)
