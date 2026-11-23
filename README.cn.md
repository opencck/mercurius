# Mercurius

[English](README.md) | [Русский](README.ru.md) | **中文**

> 本文由模型翻译，尚未经人工审校；如有出入，以英文版为准。

Mercurius 是一个自主的个人智能体。你用 Docker Compose 把它运行在自己的服务器上，并通过 Telegram 机器人与它交流。它旨在协助完成各类工作：网络搜索、通过 SSH 操作服务器、编写代码并运行软件项目、规划、研究和测试。

**状态：** 运行在所有者的服务器和四个测试实例上。开发工作，以及评审和安全审计之后的收尾工作均已完成；其结果、各项决定以及仍在等待所有者处理的事项见 [`brief/history.md`](brief/history.md)（俄文）。

## 目前可用的功能

- **Telegram：** webhook 或长轮询、流式回答、Markdown、长消息拆分、文件和按钮。智能体能看到消息编号、回复所针对的消息，以及写下它自己消息的那次运行。
- **智能体循环：** 工具；对轮次、token 和时间的限制在代码中强制执行；重启后仍可继续的运行；上下文压缩；按请求选择的模型角色（OpenRouter 和 OpenAI API）。
- **审批：** 不可逆的操作（`git push`、`ssh`、上传、非 GET 的 HTTP、在频道发帖、携带密钥的请求）会等待所有者的卡片；“始终允许”会为该确切的操作和目标保存一项授权。
- **参与者：** 陌生人等待所有者决定（在群组中遇到的陌生人也一样）；每位参与者都有自己的工具、限制、项目和记忆。
- **记忆：** 带来源的分层事实、按语义搜索、`/memory`、`/forget`、夜间整合。
- **服务器：** 密钥保险库、服务器登记表、带命令策略和日志的 SSH、经由 VLESS 的路由。
- **互联网：** 通过 SearXNG、Brave、Tavily 或带网络搜索的模型进行网络搜索；读取网页和文档；运行在独立容器中的浏览器。
- **技能和 MCP：** 带版本并经所有者批准的技能目录；从 GitHub 或压缩包导入其他智能体的普通 `SKILL.md` 技能及其文件；带工具过滤和熔断器的基于 HTTP 的 MCP 服务器；作为 MCP 服务器的内置邮件（IMAP/SMTP）。
- **调度器和项目：** 按计划或签名 webhook 触发的任务、免打扰时段；带卡片、文件夹、技能和任务的项目。
- **群组：** 在所有者批准的群组中工作（被提及时或自由参与）；每个话题就是一个项目的论坛；通过所有者的卡片在频道发帖。
- **委派：** 上下文干净且拥有自己权限（读或写）的工作智能体、每个部分一个模型、针对高成本部分的验证器、后台工作。
- **智能体网络：** Mercurius 实例之间通过 A2A 互相分配任务，以密钥相互识别：等待回答的调用、分给多个邻居再合并的部分、取消、截止时间、沿链路传递的花费。
- **WebSocket API：** 第二个通道：带 `Authorization: Bearer <key>` 的 `GET /api/ws`；所有者用 `/apikey new <participant>` 向参与者签发密钥；消息、按钮和文件的 JSON 帧。
- **自我改进：** 智能体回顾自己的轨迹，提出对其提示词的补充和模型角色的变更；变更需通过评估集和所有者的卡片，并且可以回滚（`/prompt`、`/models`、`/proposals`）。
- **导出和导入：** `/export <password>` 把整个智能体（数据库、数据、工作文件夹、设置）打包成由密码加密的归档；`/import` 用归档替换一个智能体，或只取其记忆或技能。
- **运维：** 在对话中修改设置（`/settings`、`/set`、`/restart`）、智能体的语言（`AGENT_LANGUAGE`，默认为俄语）、轨迹和花费、Prometheus 指标、备份和恢复（`bin/backup.sh`、`bin/restore.sh`）、一条命令完成更新（`bin/update.sh`）、安装检查（`/doctor`）、评估集。

## 计划中

沙箱中基于 stdio 的 MCP 服务器。未决问题见 [`brief/decisions.md`](brief/decisions.md)。

## 运行

你需要一台装有 Docker 和 Docker Compose v2 的服务器，核心部分约需 4 GB 内存和 10 GB 磁盘（可选模块另有各自的需求），一个来自 [@BotFather](https://t.me/BotFather) 的 Telegram 机器人，以及一个 OpenRouter 或 OpenAI API 的密钥。

1. 克隆代码和各包的子模块（运行不需要参考仓库）：
   ```sh
   git clone https://github.com/opencck/mercurius.git && cd mercurius
   git submodule update --init --depth 1 packages/llm packages/connectors packages/messenger packages/search packages/i18n packages/mcp-client packages/memory packages/peer skills/skill-creator skills/memory-file
   ```
2. 将 `.env.example` 复制为 `.env`（权限 `600`）并填写：至少需要 `TELEGRAM_BOT_TOKEN`、`TELEGRAM_ADMIN_ID`（你的 Telegram 用户 id）、`OPENROUTER_API_KEY` 或 `OPENAI_API_KEY`（二选一即可）、`POSTGRES_PASSWORD`、`SANDBOX_TOKEN`（`openssl rand -hex 24`）。每个变量在文件中都有说明；大多数设置之后可以在对话中修改。没有 `APP_URL` 时，机器人使用长轮询，不需要公网地址；有它时则使用位于你的代理之后的 webhook。
3. `chmod 1777 storage/app storage/browser storage/whisper`——容器以各自的用户身份向这些目录写入。然后执行 `docker compose up -d --build`。应用在启动时执行数据库迁移；`docker compose ps` 应显示 `app`、`postgres` 和 `sandbox` 为 `healthy`。
4. 给你的机器人发消息。`/start` 列出各个命令；`/doctor` 检查安装情况（密钥、迁移、沙箱、模块、webhook、磁盘），并说明需要修复什么。

### 模块

较重且可选的部分是模块：每个模块位于自己的仓库，在这里是一个子模块，并在自己的 compose profile 中运行，默认关闭。启用一个模块需要：它的子模块、`COMPOSE_PROFILES` 中的一个 profile，以及 `.env` 中它的令牌；只有当服务有响应时，智能体才会获得相应的工具。

| 模块 | 子模块 | Profile | `.env` | 智能体获得什么 |
|---|---|---|---|---|
| [`mercurius-browser`](https://github.com/opencck/mercurius-browser) | `services/browser` | `browser` | `BROWSER_TOKEN` | Chromium：页面、点击、脚本、截图 |
| [`mercurius-media-video`](https://github.com/opencck/mercurius-media-video) | `services/media-video` | `media-video` | `MEDIA_VIDEO_TOKEN` | 为无法观看视频的模型提供视频的帧和声音 |
| [`mercurius-media-docs`](https://github.com/opencck/mercurius-media-docs) | `services/media-docs` | `media-docs` | `MEDIA_DOCS_TOKEN` | 办公文档和 PDF 的文本；新建 docx、xlsx、pptx |
| [`mercurius-claude-code`](https://github.com/opencck/mercurius-claude-code) | `services/claude-code` | `claude-code` | `CLAUDE_CODE_TOKEN`、`CLAUDE_CODE_UID`、`CLAUDE_CODE_GID` | 通过官方 Claude Code 使用所有者订阅的 Claude：模型 `claude:<模型>`。在所有者私聊中用 `/claude login` 登录（或把 `claude setup-token` 的令牌设为 `CLAUDE_CODE_OAUTH_TOKEN`），然后把模型加入类别列表。除非 `CLAUDE_CODE_EXTRA_USAGE` 允许，否则拒绝套餐外付费 |
| [`mercurius-whisper`](https://github.com/opencck/mercurius-whisper) | `services/whisper` | `whisper` | `WHISPER_TOKEN` | 在本机把语音转成文字（faster-whisper）；指定方式：`MEDIA_ENDPOINTS=whisper=http://whisper:8000/v1`、`MEDIA_SPEECH_MODELS=custom:whisper/small`，令牌是保管库密钥 `media-endpoint-whisper` |
| [`mercurius-skill-memory-file`](https://github.com/opencck/mercurius-skill-memory-file) | `skills/memory-file` | — | — | 内置技能：智能体如何把参与者的 `MEMORY.md` 保持在限额之内 |
| [`mercurius-skill-creator`](https://github.com/opencck/mercurius-skill-creator) | `skills/skill-creator` | — | — | 内置技能：智能体如何创建和改进自己的技能（启动时加载，无服务） |
| [`mercurius-mail-mcp`](https://github.com/opencck/mercurius-mail-mcp) | `mcp/mail` | `mail` | `MAIL_MCP_TOKEN` | 通过 IMAP 和 SMTP 访问参与者的邮箱 |
| [`mercurius-telegram-bot-api`](https://github.com/opencck/mercurius-telegram-bot-api) | `services/telegram-bot-api` | `telegram-api` | `TELEGRAM_API_ID`, `TELEGRAM_API_HASH`, `TELEGRAM_API_URL` | Telegram 中最大 2000 MB 的文件 |

例如，启用浏览器和文档：

```sh
git submodule update --init --depth 1 services/browser services/media-docs
# .env: COMPOSE_PROFILES=browser,media-docs, BROWSER_TOKEN and MEDIA_DOCS_TOKEN (openssl rand -hex 24)
docker compose up -d --build
```

### 更新

```sh
bin/update.sh    # a backup first (a failed one stops the update), git pull, the module submodules, build and start
```

手动执行同样的步骤：

```sh
bin/backup.sh                       # the database, data and .env; the last 7 copies stay in backups/
git pull --ff-only && git submodule update --init --depth 1 <the submodules you use>
docker compose up -d --build        # new migrations apply at start; they only add to the schema
```

新变量会出现在 `.env.example` 中；`/doctor` 会指出你的 `.env` 中缺少的变量，以及保存在对话中但此版本已不再有的设置。`bin/restore.sh backups/<time>` 可恢复一份备份。

### 同一主机上的多个实例

每个实例都是位于各自文件夹中的独立克隆，有自己的 `.env`：`COMPOSE_PROJECT_NAME`、机器人令牌和密钥、`WEB_ALIAS`（它在代理网络 `web` 中的名称）以及 `SANDBOX_SUBNET`（供其沙箱网络使用的一个空闲 `/24`，例如 `172.31.252.0/24`）。镜像是共享的：在任意文件夹中构建都会更新所有实例。

当主机上的 Docker 为新网络耗尽地址池时，请在 `docker-compose.yml` 旁的本地文件中为 `browser` 网络指定一个子网，并把该文件加入 `COMPOSE_FILE`：

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

数据文件夹 `storage/app` 是应用以自己的用户（uid 10001）写入的挂载点：像 `storage/browser` 一样，为它设置权限 `1777`；`/doctor` 会检查这一点。

## 技术栈

PHP 8.2+、AMPHP v3 / Revolt、[`opencck/server`](https://github.com/opencck)、带 pgvector 的 PostgreSQL；通过 OpenRouter 和 OpenAI API 调用模型。每个仓库都运行 PHPUnit、PHPStan 和 Psalm（`composer check`）。

## 仓库

在 Mercurius 之外也有用的组件位于各自的仓库中，并作为子模块包含在 `packages/` 中：

| 仓库 | 用途 |
|------------|---------|
| [`mercurius-llm`](https://github.com/opencck/mercurius-llm) | 调用大语言模型的统一接口：消息、工具、流式输出、token 和成本核算、错误与重试。 |
| [`mercurius-connectors`](https://github.com/opencck/mercurius-connectors) | 作为 Amp `SocketConnector` 工厂的出站连接路由：直连、HTTP CONNECT 代理、SOCKS5、VLESS（通过 `amphp/vless`）及它们的链。 |
| [`mercurius-messenger`](https://github.com/opencck/mercurius-messenger) | 带规范化消息模型的统一消息通道接口，以及 Telegram Bot API 适配器（webhook 和长轮询、群组、论坛话题、频道帖子）。 |
| [`mercurius-search`](https://github.com/opencck/mercurius-search) | 统一接口之后的网络搜索：SearXNG、Brave、Tavily 和带网络搜索的模型，支持回退和缓存。 |
| [`mercurius-i18n`](https://github.com/opencck/mercurius-i18n) | 智能体的俄语和英语文本：翻译器、目录、复数形式和一致性检查。 |
| [`mercurius-mcp-client`](https://github.com/opencck/mercurius-mcp-client) | MCP 客户端：基于 Streamable HTTP 的 JSON-RPC、分页的工具和资源、工具结果中的文件。 |
| [`mercurius-memory`](https://github.com/opencck/mercurius-memory) | 智能体的记忆：带版本和来源的事实、按键和 HRR 向量召回、由所有者裁决的提案、核心层、夜间整合；PostgreSQL 和内存存储及其迁移。 |
| [`mercurius-todo`](https://github.com/opencck/mercurius-todo) | 对话计划：带状态和子任务的条目，按编号替换或合并，为上下文提供未完成的条目，内存与 PostgreSQL 存储。 |
| [`mercurius-peer`](https://github.com/opencck/mercurius-peer) | 基于 A2A 的智能体间通信：P-256 密钥和签名、客户端（证明其密钥的卡片、任务、取消、文件）、带密钥握手的 WebSocket。 |

模块——浏览器、媒体、邮件和本地 Bot API 服务器——列在[模块](#模块)中。仓库 [`mercurius-command-policy`](https://github.com/opencck/mercurius-command-policy) 为预留：它的代码目前仍在本仓库中（`src/CommandPolicy/`）。

## 仓库结构

| 路径 | 内容 |
|------|----------|
| [`BRIEF.md`](BRIEF.md) | 规格说明：需求、设计、决定（俄文） |
| [`CLAUDE.md`](CLAUDE.md) | 使用 Claude Code 开发的工作规则（俄文） |
| `bootstrap/index.php`, `src/`, `migrations/`, `test/` | 智能体：入口点、按 opencck 分层组织的代码、数据库迁移、测试 |
| `bootstrap/sandbox.php`, `src/Sandbox/` | 沙箱执行器 |
| `services/`, `mcp/` | 可选模块，各自位于自己的仓库和 compose profile 中：browser、media-video、media-docs、whisper、claude-code、telegram-bot-api；mail |
| `bin/` | 备份、恢复、更新和安装检查 |
| `docs/`, `docs/notes/` | 关于构建自主智能体的知识库：文章和笔记 |
| `references/` | 作为 git 子模块的参考仓库 |

## 许可证

[MIT](LICENSE)
