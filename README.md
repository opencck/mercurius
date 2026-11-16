# Mercurius

**English** | [Русский](README.ru.md)

Mercurius is an autonomous personal agent. You run it on your own server with Docker Compose and talk to it through a Telegram bot. It is meant to help with any kind of work: searching the web, working with servers over SSH, writing code and running software projects, planning, research and testing.

**Status:** runs on the owner's server; development phases 0–5 are done (the MVP and delegation to workers). The rest is in development; the plan is in [`ROADMAP.md`](ROADMAP.md) (in Russian).

## What works now

- A Telegram bot (webhook or long polling) with streamed answers, Markdown, long messages split up, files and buttons.
- An agent loop with tools: HTTP requests, files and commands in a sandbox container with per-participant users and limits (PHP with Composer, Python, Node.js, Go). Limits on turns, tokens and time are enforced in code; a run survives a restart.
- Irreversible actions (`git push`, `ssh`, uploads, non-GET HTTP) wait for the owner's approval card; "allow always" saves a grant for that exact action and target; `/permissions` lists and revokes grants.
- Participants: a stranger who writes waits for the owner's decision; each participant has their own tools and limits.
- Context compaction: old tool results are cut first, then the middle of the conversation is summarised by a cheap model.
- Model roles (cheap, main, strong, media) chosen by a rough class of the first request; models of any provider (OpenRouter and the OpenAI API); the agent and the owner change roles with a reason.
- Traces of every run: steps with time, tokens and cost; `/usage`, `/runs`, `/trace <id>`; Prometheus metrics behind a token.
- Delegation: the agent splits a task into parts for worker agents with a clean context, read-only parts side by side, within a shared token budget. Each part gets a model by its needs (scores 1–10 against catalog benchmarks, the cheapest model that fits, a retry on a stronger model when the model fails); a part where a mistake is costly is checked by a separate verifier. Long work runs in the background, survives a restart and reports back as a new message; `/delegation` shows how the agent splits work.

## Planned

Long-term memory; servers over SSH, also through VLESS; a browser; skills and MCP servers; scheduled tasks and projects; a network of Mercurius instances over WebSocket.

## Running it

1. Clone with submodules: `git clone git@github.com:opencck/mercurius.git && cd mercurius && git submodule update --init --depth 1 packages/llm packages/connectors packages/messenger`.
2. Copy `.env.example` to `.env` and fill it in: at least `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_ID` (your Telegram user id), `OPENROUTER_API_KEY` or `OPENAI_API_KEY` (one is enough), `POSTGRES_PASSWORD`, `SANDBOX_TOKEN`. Every variable is explained in the file.
3. `docker compose up -d --build`. The app applies database migrations at start.
4. Write to your bot. Commands: `/new`, `/stop`, `/permissions`, `/usage [days]`, `/runs`, `/trace <id>`, `/delegation [days]`.

## Stack

PHP 8.2+, AMPHP v3 / Revolt, [`opencck/server`](https://github.com/opencck), PostgreSQL; models through OpenRouter and the OpenAI API. Every repository runs PHPUnit, PHPStan and Psalm (`composer check`).

## Repositories

Components useful outside Mercurius live in their own repositories and are included as submodules in `packages/`:

| Repository | Purpose |
|------------|---------|
| [`mercurius-llm`](https://github.com/opencck/mercurius-llm) | A unified interface for calling large language models: messages, tools, streaming, token and cost accounting, errors and retries. |
| [`mercurius-connectors`](https://github.com/opencck/mercurius-connectors) | Outbound connection routes as an Amp `SocketConnector` factory: direct, HTTP CONNECT proxy, SOCKS5, VLESS (via `amphp/vless`) and chains of them. |
| [`mercurius-messenger`](https://github.com/opencck/mercurius-messenger) | A unified messaging channel interface with a normalized message model, and a Telegram Bot API adapter (webhook and long polling). |

Planned packages: `mercurius-command-policy` (command danger classification and policy), `mercurius-memory` (long-term memory), `mercurius-browser-service` (Chrome behind an HTTP API), `mercurius-mcp-client` (MCP client), `mercurius-peer` (connecting Mercurius instances).

## Repository layout

| Path | Contents |
|------|----------|
| [`BRIEF.md`](BRIEF.md) | Specification: requirements, design, decisions (in Russian) |
| [`ROADMAP.md`](ROADMAP.md) | Development phases and their status (in Russian) |
| [`CLAUDE.md`](CLAUDE.md) | Working rules for development with Claude Code (in Russian) |
| `index.php`, `src/`, `migrations/`, `test/` | The agent: entry point, code in opencck layers, database migrations, tests |
| `sandbox.php`, `src/Sandbox/` | The sandbox executor |
| `docs/`, `notes/` | Knowledge base on building autonomous agents: articles and notes |
| `references/` | Reference repositories as git submodules |

## License

[MIT](LICENSE)
