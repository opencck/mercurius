---
title: "Kimi Code — FAQ"
source: https://www.kimi.com/en/help/kimi-code/faq
fetched: 2026-11-28
---

> Источник: <https://www.kimi.com/en/help/kimi-code/faq>

# FAQ

## Installation and Authentication

### Empty model list during`/login`

If you see the error "No models available for the selected platform" when running the`/login`command, it may be due to:

- Invalid or expired API key: Check whether the API key you entered is correct and still valid.
- Network connection issues: Confirm you can access the API service addresses (such as`api.kimi.ai`or`api.moonshot.ai`).
Note: Distinguish Platforms

Kimi Code membership benefits and theKimi Platformhave different Base URLs. Please ensure your Base URL and API Key are matched correctly.

 | Platform | Base URL | Billing | Key Source

 | Kimi Code | Anthropic-compatible: China`https://api.kimi.com/coding/`
Overseas`https://api.kimi.ai/coding/` | Membership subscription (quota included) | Kimi Code Console

 | Kimi Open Platform | China`https://api.moonshot.cn/v1`
Overseas`https://api.moonshot.ai/v1` | Pay-as-you-go | Kimi Open Platform

### Invalid API key

Possible reasons:

- Typo: Check for extra spaces or missing characters.
- Expired or revoked: Verify the key status in the console.

### Membership expired or quota exhausted

Use the`/usage`command to check your current quota and membership status. If your quota is exhausted or membership has expired, renew or upgrade atKimi Code.

## Subscription

### How do I request an invoice or upgrade my membership?

Visit theKimi Membership pageand follow the instructions to submit an invoice request or upgrade your plan.

## Interaction Issues

### Image paste fails

If you see "Current model does not support image input" when pasting with`Ctrl-V`, the current model does not support image input.

Solutions:

- Switch to a vision-capable model: Use a model with`image_in`capability.
- Check clipboard content: Make sure the clipboard contains actual image data, not just a file path.

## Updates and Upgrades

### Slow first launch on macOS

macOS Gatekeeper performs a security check on new programs the first time they run, causing a slow startup. Solutions:

- Wait for the check to complete: Be patient on first launch; subsequent startups will be normal.
- Add to Developer Tools: Go to System Settings → Privacy & Security → Developer Tools and add your terminal app.

### How to upgrade Kimi Code CLI

Run`kimi upgrade`inside the CLI to automatically check for the latest version and be guided through the upgrade. You can also use the package manager directly:

Bash

## VS Code Extension FAQ

The following are common questions about the Kimi Code VS Code extension.

### VS Code says no workspace is open

Open a folder in VS Code — the Kimi Code VS Code extension requires a workspace to function properly.

### VS Code says CLI not found

Manually install Kimi Code CLI and configure`kimi.executablePath`in VS Code settings, or ensure the built-in CLI exists.

### VS Code login fails

Try skipping login and using API key mode instead, check your network connection, or retry later via the extension's action menu.

### VS Code shows no response after sending a message

Confirm that Kimi Code CLI is available, a model is configured, and a workspace folder is open in VS Code. Check error logs via "Kimi Code: Show Logs".

### VS Code connection timeout

Requests time out after 30 seconds with no response. Check your network and retry.

### VS Code shows an error before sending a message

Certain errors prevent sending messages in VS Code, such as CLI not found, version too low, not logged in, or session busy. Errors appear as toast notifications; your input is preserved for retry.

## Feedback and Contact

### Problem not resolved by this page

If the above content hasn't resolved your issue, please go toKimion the web, click your profile picture in the bottom-left corner, then selectGet Help→User Feedbackto consult our support team. Please describe the problem, steps taken, and any relevant logs — we'll get back to you as soon as possible.

Was this article helpful?
