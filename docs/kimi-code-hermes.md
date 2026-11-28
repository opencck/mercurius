---
title: "Kimi Code — Using Kimi in Hermes Agent"
source: https://www.kimi.com/code/docs/en/third-party-tools/hermes.html
fetched: 2026-11-28
---

> Источник: <https://www.kimi.com/code/docs/en/third-party-tools/hermes.html>

# Using Kimi in Hermes Agent

Hermes Agentis Nous Research's open-source self-improving AI agent with a built-in learning loop, accessible from the terminal, Telegram, Discord, and more. This guide shows how to connect it to the Kimi Code membership (Coding Plan) and use Kimi's model inference.

## Prerequisites

Complete these preparations before you start. Follow the linked official guides for installation and account setup; they are not repeated here.

Install Hermes AgentInstall or update Hermes Agent with the official installation docs.Create an API keyCreate and safely store a membership API key in the Kimi Code Console.Check your membership tierSee the available models and quota rules for your tier on the Membership Benefits page.

## Step 1: Set the API key

Hermes reads your membership key from the`KIMI_API_KEY`environment variable. Set it in the current terminal:

sh

```
export KIMI_API_KEY="sk-kimi-your-membership-key"
```

This only applies to the current terminal session. To make it persistent, append the same line to your shell profile (e.g.`~/.zshrc`), or write it to`~/.hermes/.env`, which Hermes loads automatically on startup.

## Step 2: Write the complete configuration

Before configuring, confirm the models and context windows available to your membership tier — seeMembership Benefits. Using models or context windows beyond your tier's allowance will trigger an error:

 | Membership tier | Available models | Context window

 | Go (new plans) | No coding quota | —

 | Andante (legacy plans) | `kimi-for-coding` | `1048576`

 | Plus / Moderato | `k3.1-pro`
`k3`
`k3-256k`
`kimi-for-coding` | `k3.1-pro`:`262144`
`k3`:`262144`
`k3-256k`:`262144`
`kimi-for-coding`:`1048576`

 | Pro and above / Allegretto and above | `k3.1-pro`
`k3.1-pro-highspeed`
`k3`
`k3-256k`
`kimi-for-coding`
`kimi-for-coding-highspeed` | `k3.1-pro`:`1048576`
`k3.1-pro-highspeed`:`1048576`
`k3`:`1048576`
`k3-256k`:`262144`
`kimi-for-coding`:`1048576`
`kimi-for-coding-highspeed`:`262144`

Introducing the K3.1 Pro series

K3.1 Prois now fully rolled out in Kimi Code. It is our most capable 3T-class model to date, with a 1-million-token context window and substantial improvements in coding, 3D creation, financial research, and other long-horizon agent capabilities.

We also launchedK3.1 Pro Highspeed. With the same model quality, it delivers approximately 2–3× the speed of K3.1 Pro for more responsive interactions.

Note: K3.1 Pro models only support thinking mode, and requests with thinking disabled are rejected. Start a new session and use the Kimi Coding Plan's native API protocols for the most stable results; protocol conversion through proxy tools may cause behavior to vary.

Run:

sh

```
hermes config edit
```

`hermes config edit`simply opens`~/.hermes/config.yaml`in the default terminal editor. If you are not comfortable with nano/vim, you can open the same file in any editor you like, such as VS Code — the result is identical.

With the file open, merge the complete configuration below into it: the`model`and`agent`sections usually already exist — only change the fields shown (you can replace the whole`model`section outright); the`custom_providers`and`auxiliary`sections usually do not exist — paste them at the end of the file. Keep any other existing custom providers.

yaml

```
custom_providers:
  - name: kimi-coding-plan
    base_url: https://api.kimi.ai/coding/v1
    key_env: KIMI_API_KEY
    api_mode: chat_completions
    model: k3.1-pro
    extra_body:
      reasoning_effort: high
    models:
      k3.1-pro:
        context_length: 262144
        supports_vision: true

model:
  provider: custom:kimi-coding-plan
  default: k3.1-pro
  context_length: 262144
  supports_vision: true

agent:
  reasoning_effort: high

auxiliary:
  vision:
    provider: main
    model: k3.1-pro
    extra_body:
      reasoning_effort: high
```

Save and exit when done (nano:`Ctrl+O`then Enter to save,`Ctrl+X`to exit; vim:`:wq`; VS Code: just save the file).

What the key fields in the block do:

- `reasoning_effort`: thinking effort, explicitly set to`high`here; see the value mapping below
- `context_length`and`supports_vision`: enable the 256K context window and native image understanding; the`auxiliary`section routes image analysis through the same main provider
- `key_env`: references the`KIMI_API_KEY`set in Step 1, so the key never has to be written into the config file
- `base_url`: only goes up to`/coding/v1`; Hermes appends`/chat/completions`automatically, so do not put the full request endpoint in

 | `reasoning_effort`input | Actual effort

 | Not set (default) | `high`(K3.1 Pro default) /`high`(K3 series default) /`max`(K2.8 Preview default)

 | `low` | `low`

 | `high` | `high`

 | `max` | `max`

K3.1 Pro models only support thinking mode, and disabling thinking returns 400. With thinking disabled, requests to the K3 series, K2.8 Preview, and K2.7 Code HighSpeed are served by K2.8 Preview without thinking.

Using a different model

The configuration above defaults to`k3.1-pro`. To use K3.1 Pro or K3.1 Pro Highspeed with a 1M context window (Allegretto / Pro or above), replace every model ID in the configuration with the corresponding ID and change both`context_length`values to`1048576`; on Moderato / Plus, K3.1 Pro is also capped at 256K, so no change is needed. To use`k3-256k`, likewise replace every model ID and keep both`context_length`values at`262144`.

## Step 3: Enable video analysis (optional)

Hermes Agent ships an official video analysis tool — enable it once:

sh

```
hermes tools enable video
```

Once enabled, Hermes can call the official`video_analyze`tool. For local videos, the tool reads the full file, encodes it as`data:video/...;base64,...`, and sends it to the model as a`video_url`content block — it does not extract frames locally with FFmpeg first.

Then, in a chat, provide the absolute video path and explicitly ask it to call`video_analyze`— no`/video`command needed:

text

```
Call video_analyze on /absolute/path/to/demo.mp4, summarize the video, and list three details that can be confirmed from the visuals.
```

Common formats including MP4, WebM, MOV, AVI, MKV, and MPEG are supported, with an approximately 50 MB Base64-encoded payload limit — a hard-coded cap of the Hermes client video tool, not a Kimi API limit. Trim or compress larger videos first. You can also provide a directly accessible HTTP/HTTPS video URL.

Video analysis depends on the model's multimodal capability, and`k3-256k`does not support video input. If you configured`k3-256k`in Step 2, switch the default model to`k3`before analyzing videos.

## Step 4: Use images (optional)

Reference a local image by its absolute path in a chat:

text

```
/image /absolute/path/to/example.png
```

You can also copy an image to the clipboard, type`/paste`, and then ask your question. The image is sent as native vision content to the model configured in Step 2.

## Step 5: Launch and verify

Start a new session so the provider and model settings take effect:

sh

```
hermes
```

After startup, confirm the status bar shows the expected model and context window (e.g.`k3.1-pro`with`256K`), then send a simple request to verify connectivity.

## Troubleshooting
401 error or key rejected
Confirm the`KIMI_API_KEY`set in Step 1 is an`sk-kimi-`membership key created in theKimi Code Console, and that your membership tier supports the selected model (see the tier table inStep 2). If you previously ran the`hermes model`setup wizard, check`~/.hermes/.env`for a stale key and remove or update it before retrying.
403 error
You've hit a quota or rate limit. Note the rolling 5-hour rate window; for existing members on legacy plans, the subscription quota also refreshes every 7 days (new members are not subject to the weekly limit) — both recover automatically when their windows reset. You can check your quota usage in theKimi Code Console. For the exact error messages and how to handle them, see theerror reference.
The video tool is not called
Confirm you ran`hermes tools enable video`, explicitly asked for`video_analyze`in your prompt, and used an absolute local path. The encoded payload must stay below approximately 50 MB.

## Next steps

- Model configuration— model capability comparison and the full effort mapping table
- Membership Benefits— available models, quota refresh rules, and tier requirements
