# Mercurius

**English** | [Русский](README.ru.md)

Mercurius is an autonomous personal agent. You run it on your own server with Docker Compose and talk to it through a Telegram bot. It is meant to help with any kind of work: searching the web, working with servers over SSH, writing code and running software projects, planning, research and testing.

**Status:** early development. The specification is ready; there is nothing to install yet.

## Planned capabilities

- An agent loop with tools, limits enforced in code and owner confirmation of dangerous actions.
- An orchestrator that decides on its own when to split a task across worker agents.
- Choosing a model for each part of a task by cost and speed.
- Long-term memory: an always-in-context profile, projects and their tasks, facts with trust and history, background consolidation.
- Servers over SSH, also through VLESS; a browser; skills and MCP servers.
- Detailed traces of every run for diagnostics and optimization.
- A network of agents: Mercurius instances connect over WebSocket and use each other as agents.

## Stack

PHP 8.2+, AMPHP v3 / Revolt, [`opencck/server`](https://github.com/opencck), PostgreSQL; models through OpenRouter. Every repository runs PHPUnit, PHPStan and Psalm.

## Repositories

Components useful outside Mercurius live in their own repositories and are included as submodules in `packages/`:

| Repository | Purpose |
|------------|---------|
| [`mercurius-llm`](https://github.com/opencck/mercurius-llm) | A unified interface for calling large language models: messages, tools, streaming, token and cost accounting, errors and retries. |
| [`mercurius-connectors`](https://github.com/opencck/mercurius-connectors) | Outbound connection routes as an Amp `SocketConnector` factory: direct, HTTP CONNECT proxy, SOCKS5, VLESS (via `amphp/vless`) and chains of them. |
| [`mercurius-messenger`](https://github.com/opencck/mercurius-messenger) | A unified messaging channel interface with a normalized message model, and a Telegram Bot API adapter (webhook and long polling). |
| [`mercurius-command-policy`](https://github.com/opencck/mercurius-command-policy) | Command danger classification and an execution policy: read-only only when proven, a never-allowed list, a role × server group matrix, one-time grants, quotas and an audit log of decisions. |
| [`mercurius-memory`](https://github.com/opencck/mercurius-memory) | Long-term memory for an agent: a fact store with versions, trust, provenance and entities, lexical retrieval with a relevance gate and holographic reduced representations (HRR), and background consolidation. |
| [`mercurius-browser-service`](https://github.com/opencck/mercurius-browser-service) | A browser service for the agent: Chrome driven by Puppeteer behind an HTTP API on Fastify, shipped as a Docker image. |
| [`mercurius-mcp-client`](https://github.com/opencck/mercurius-mcp-client) | An asynchronous Model Context Protocol (MCP) client with stdio and HTTP transports. |
| [`mercurius-peer`](https://github.com/opencck/mercurius-peer) | A protocol for connecting Mercurius instances over WebSocket: agent card, task, status stream, result, cancellation and reconnection — client and server. |

## Repository layout

| Path | Contents |
|------|----------|
| [`BRIEF.md`](BRIEF.md) | Specification: requirements, design, decisions (in Russian) |
| [`CLAUDE.md`](CLAUDE.md) | Working rules for development with Claude Code (in Russian) |
| `docs/`, `notes/` | Knowledge base on building autonomous agents: articles and notes |
| `references/` | Reference repositories as git submodules |

To clone with submodules:

```bash
git clone git@github.com:opencck/mercurius.git
cd mercurius
git submodule update --init --depth 1
```

## License

[MIT](LICENSE)
