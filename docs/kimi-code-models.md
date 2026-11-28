---
title: "Kimi Code — Model configuration"
source: https://www.kimi.com/code/docs/en/kimi-code/models.html
fetched: 2026-11-28
---

> Источник: <https://www.kimi.com/code/docs/en/kimi-code/models.html>

# Model Configuration

This page covers the models Kimi Code provides, how to switch models in each client, third-party tool setup, and common API issues.

## Model Overview

Kimi Code currently offers six model IDs across K3.1 Pro (including K3.1 Pro Highspeed), K3 (including its 256K context variant), K2.8 Preview, and K2.7 Code Highspeed. Use a model ID to select a specific version in official clients or third-party tools. The table below compares specifications and plan requirements.

Introducing the K3.1 Pro series

K3.1 Prois now fully rolled out in Kimi Code—the first model of the new K3.1 series and our most capable 3T-class model to date, with a 1M context window and substantial improvements in coding, 3D creation, financial research, and other long-horizon agent capabilities. For more technical details, see thetechnical report.

We're also releasingKimi K3.1 Pro Highspeed, which—with identical model capabilities—delivers approximately 2–3× the speed of K3.1 Pro for more responsive interactions.

Usage recommendations

- Keep thinking enabled: K3.1 Pro models only support thinking mode. Requests with thinking disabled are rejected.
- Start a new session: avoid switching to the new models within an existing K3 series, K2.8 Preview, or K2.7 Code Highspeed session—doing so degrades output quality and reduces cache hits. Start a new session to experience the K3.1 Pro series.
- Use the native APIs: for K3.1 Pro models, we recommend the Kimi Coding Plan's native Responses, chat completion, or message APIs for the most stable output. Protocol translation through proxy tools may cause model behavior to vary.

 | Model ID | `k3.1-pro` | `k3.1-pro-highspeed` | `k3` | `k3-256k` | `kimi-for-coding` | `kimi-for-coding-highspeed`

 | Model version | K3.1 Pro | K3.1 Pro Highspeed | K3 | K3 | K2.8 Preview | K2.7 Code Highspeed

 | Description | Kimi's latest flagship model: 3T-class parameters, a 1M context window, and thinking mode only | The high-speed version of K3.1 Pro, with the same capabilities, faster output, and about twice the quota usage of the regular version | The previous-generation flagship model, with 2.8T parameters and a 1M context window | The 256K context version of K3 —`k3`(1M) consumes about twice as much quota as`k3-256k` | Performance close to K3 with more efficient thinking; good at code completion and routine development tasks | The high-speed version of K2.7 Code, with the same coding ability and ~5–6× faster output

 | Speed | Regular | High-speed (approximately 2–3× speed, 2× quota usage) | Regular | Regular | Regular | High-speed (approximately 6× speed, 3× quota usage)

 | Context window | Up to`1048576`(for higher-tier members) | Up to`1048576`(for higher-tier members) | Up to`1048576`(for higher-tier members) | Up to`262144` | Up to`1048576` | Up to`262144`

 | Reasoning | `reasoning_effort:low`/`high`/`max`
(default`high`; thinking cannot be disabled) | `reasoning_effort:low`/`high`/`max`
(default`high`; thinking cannot be disabled) | `reasoning_effort:low`/`high`/`max`
(default`high`) | `reasoning_effort:low`/`high`/`max`(default`high`) | `reasoning_effort:low`/`high`/`max`
(default`max`) | `Thinking:ON`

 | Availability (new plans) | Plus and above
1M context for Pro and above | Pro and above | Plus and above
1M context for Pro and above | Plus and above | Plus and above | Pro and above

 | Availability (legacy plans) | Moderato and above
1M context for Allegretto and above | Allegretto and above | Moderato and above
1M context for Allegretto and above | Moderato and above | Andante and above | Allegretto and above

 | Multimodal input | Image, video | Image, video | Image, video | Image only | Image, video | Image, video

Explore or upgrade your membership plan?

Different membership plans unlock different models, context windows, and speeds.Membership subscription →

## How to Switch Models

Before you switch

- Start a new session: switching models or thinking effort invalidates the existing context cache, requiring a new prefill and increasing token usage. Reasoning chains from different models can also affect each other.
- Use the model ID for third-party calls: enter a model ID from the table above, such as`k3.1-pro`or`k3.1-pro-highspeed`, rather than a model version name such as K3.1 Pro or K3.1 Pro Highspeed. A mistyped model ID falls back to K2.8 Preview without thinking.
- Check thinking behavior: K3.1 Pro models only support thinking mode, and requests with thinking disabled are rejected. The K3 series, K2.8 Preview, and K2.7 Code Highspeed are routed to K2.8 Preview without thinking when thinking is disabled. K2.7 Code Highspeed does not support adjustable thinking effort. SeeEffort mapping and routing rulesfor third-party tools.

### Official clients

- Kimi Code Desktop: switch models in the model picker inside the Composer; if the target model isn't listed yet, restart the app or sign in again.
- Official Kimi Code CLI: type`/model`to switch models—no config changes needed; if the latest model isn't listed yet,`/logout`and sign in again with`/login`.
- Kimi Code for VS Code: pick the target model from the dropdown menu in the input bar; if it isn't listed yet, restart VS Code or reinstall the extension.
If you don't have a client yet, download Desktop, the CLI, or the VS Code extension from theKimi Code homepage.

### Third-party tools

- Create an API Key in theKimi Code Console.
- Fill in the Base URL and the target model ID in your tool.
The Kimi Code API supports OpenAI (Responses / Chat Completions) and Anthropic protocols. Tools that support the Responses API should use the native Responses format. Base URLs:

 | Protocol | Base URL

 | OpenAI
(Responses / Chat Completions) | China`https://api.kimi.com/coding/v1`
Overseas`https://api.kimi.ai/coding/v1`

 | Anthropic compatible | China`https://api.kimi.com/coding/`
Overseas`https://api.kimi.ai/coding/`

For detailed setup steps, see the corresponding tool guide:

Claude CodeAnthropic's command-line coding assistantOpenCodeA terminal-based coding agentCodexOpenAI's coding agent

#### Effort mapping and routing rules

K3.1 Pro models, the K3 series, and K2.8 Preview support`low`/`high`/`max`thinking effort levels. Third-party tool values are mapped as follows. K2.7 Code Highspeed does not support adjustable thinking effort.

 | Tool input | Resulting effort or behavior

 | No`effort`(`null`/`undefined`) | Model default:`high`for K3.1 Pro models and the K3 series;`max`for K2.8 Preview

 | `ultra`/`max`/`xhigh` | `max`

 | `high`/`medium` | `high`

 | `low`/`minimum`/`light` | `low`

 | `off`/`none`/`disabled` | K3.1 Pro models: requests with thinking disabled are rejected (HTTP 400)
K3 series, K2.8 Preview, and K2.7 Code Highspeed: requests with thinking disabled are served by K2.8 Preview without thinking

 | Any other unknown value | Return HTTP 400

Changing thinking effort also invalidates the context cache. Keep the same effort within a session when possible. If you need to change it, start a new session to avoid repeated prefills and additional token usage.

## FAQ
The model ID is correct, but the API returns 403 or 400
A valid model ID only identifies the model. Whether the request succeeds also depends on account permissions, thinking mode, and context limits. Check the error message:

- Your account doesn't have access to the model or 1M context (403): your current plan doesn't include the requested capability.Check plan requirements in the model table, thengo to Membership subscription →as needed.
- Thinking is disabled (400): K3.1 Pro models only support thinking mode. Keep thinking enabled;`none`or`disabled`is rejected.
- The selected model doesn't support 1M or the request exceeds the context limit (400): some models, such as`k3-256k`and`kimi-for-coding-highspeed`, don't support 1M context—check the model tablefor details. Compact long context, or switch to a model and plan that supports up to 1M.How can I preserve model quality and reduce usage?
Keep the same model and thinking effort within a session instead of switching back and forth. Switching models or thinking effort invalidates the existing context cache, requiring a new prefill and increasing token usage. Reasoning chains from different models can also affect each other.
Why isn't Highspeed noticeably faster?
Two common reasons:

- Mistyped model ID: the Highspeed ID must be`k3.1-pro-highspeed`or`kimi-for-coding-highspeed`; a wrong value silently falls back to K2.8 Preview without thinking — no speedup.
- Tools and scripts dominate: Highspeed only speeds upmodel output. Tool calls (reading/writing files, running commands, etc.) and script execution are unaffected, so when they take up most of a turn the overall speedup feels small.Switching between K3 (1M) and K3-256k
Switching from K3 (1M) to K3-256k: if the current session's context already exceeds 256k, some coding tools (such as Kimi Code CLI and Claude Code) will perform a compact on the tool side. Recommendations:

- Manually run compact once before switching to compress the context to within 256k. This preserves the key points of the task, keeps the session intact, and lets you benefit from more durable quota after switching.
- If the conversation history includes video files, switching directly will fail because K3-256k does not support video input. Please compact first, then switch.
Switching from K3-256k to K3 (1M): if`k3-256k`is close to the 256k limit and you don't want compact to lose information, you can switch directly to 1M. The current version switching from 256k to 1M does not affect the cache.
