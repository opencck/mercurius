---
title: "Kimi Code — Error reference"
source: https://www.kimi.com/code/docs/en/kimi-code/error-reference.html
fetched: 2026-11-28
---

> Источник: <https://www.kimi.com/code/docs/en/kimi-code/error-reference.html>

# Error Reference

This document coversserver-side errorsandtool call errorsyou may encounter when calling the Kimi Code API, including authentication, rate limiting, request format, and internal server issues. If you are experiencing client-side problems (such as CLI installation failures, IDE connection issues, MCP configuration errors, etc.), please refer to theFAQ.

Find the relevant entry in the table below based on the error message shown in your terminal or client, and follow the guidance provided.

Note

If you are using a third-party client such as OpenCode or Claude Code, the client may transform or re-wrap error codes, so the code you see may differ from what is documented here. In that case,focus on the text content of the error messageand match keywords in the quick lookup table below.

## Quick Lookup

Errors fall into two groups:model call errorsoccur while the model is generating a response (authentication, rate limiting, request format, etc.), whiletool call errorsoccur while the AI is executing a tool call (such as reading a web page or processing an image). Match keywords in the corresponding table below based on where the error originates.

### Model calls

 | Code | Type | Error message

 | 400 | Request Format Errors | `Your model id does not exist, recognized as other:`

 | 400 | Request Format Errors | `total message size N exceeds limit 2097152`

 | 400 | Request Format Errors | `Your request exceeded model token limit: 262144`

 | 400 | Request Format Errors | `thinking is enabled but reasoning_content is missing`

 | 400 | Request Format Errors | `K3.1 Pro does not support disabled thinking mode`

 | 400 | Request Format Errors | `unsupported image url`

 | 400 | Request Format Errors | `function name ... is duplicated`

 | 400 | Blocked by model safety policy | `The request was rejected because it was considered high risk`

 | 401 | Authentication Errors | `The API Key appears to be invalid or may have expired`

 | 401 | Authentication Errors | `Invalid Authentication`

 | 401 | Authentication Errors | `The account associated with these credentials was not found or is no longer active`

 | 403 | Permission Errors | `Your current subscription does not have access to k3.1-pro`

 | 403 | Permission Errors | `Your current plan supports only k3.1-pro up to 256K context`

 | 403 | Permission Errors | `Your current subscription does not have access to k3`

 | 403 | Permission Errors | `Your current plan supports only k3 up to 256K context`

 | 403 | Permission Errors | `Your current subscription does not have access to kimi-for-coding-highspeed`

 | 403 | Permission Errors | `Your current subscription does not have access to k3.1-pro-highspeed`

 | 403 | Permission Errors | `unable to verify your membership benefits`

 | 404 | Resource Not Found | `Not found the model kimi-for-coding or Permission denied`

 | 404 | Resource Not Found | `method not found`

 | 429 | Quota & Account Limits | `You've reached your 5-hour usage limit`

 | 429 | Quota & Account Limits | `You've reached your weekly (7-day) usage limit`

 | 429 | Quota & Account Limits | `You've reached your monthly usage limit for this billing cycle`

 | 429 | Quota & Account Limits | `Your credit balance is insufficient`

 | 429 | Quota & Account Limits | `Your organization's Extra Usage balance has run out`

 | 429 | Quota & Account Limits | `You've reached the Extra Usage limit set for your account by your organization`

 | 429 | Quota & Account Limits | `You've reached your concurrent request limit`

 | 429 | Quota & Account Limits | `We're receiving too many requests`

 | 429 | Quota & Account Limits | `The engine is currently overloaded`

 | 500 | Internal Server Errors | `bot_id ... value does not match id_kinds`

 | 500 | Internal Server Errors | `The server had an error while processing your request`

 | 500 | Internal Server Errors | `failed to connect to ... database=membership_`

 | 500 | Internal Server Errors | `FATAL: terminating connection due to administrator command`

 | 500 | Internal Server Errors | `failed to evaluate rate limit script`

 | 500 | Internal Server Errors | `i/o timeout`/`conn closed`/`bad connection`

 | 500 | Internal Server Errors | `未找到该账号`/`该账号已被禁用`/`已被禁言`

 | 502 | Bad Gateway | `Bad Gateway`

### Tool calls

 | Code | Type | Error message

 | 400 | Tool Call Errors | `invalid_url: The provided URL is invalid`

 | 403 | Tool Call Errors | `The request was blocked for safety reasons`

 | 403 | Tool Call Errors | `The request was blocked for safety reasons`

 | 404 | Resource Not Found | `data source '<name>' not found`/`tool is not configured`

 | 404 | Resource Not Found | `The requested resource or content was not found`

 | 499 | Tool Call Errors | `context canceled`

 | 500 | Tool Call Errors | `The service failed to process the request`

 | 503 / 504 | Tool Call Errors | `The service is temporarily unavailable`/`The request timed out`

## Errors Not Covered Here

If none of the entries above match the error you encountered, go toKimi.aion the web, click your profile picture in the bottom-left corner, then selectGet Help→User Feedbackto contact our support team, and include the full error message, Request ID, and the time of the request.

## Authentication Errors

HTTP 401

The request did not carry valid credentials, or the credentials have expired. The server validates identity before processing any request — 401 means this step failed.Do not retry; fix the credentials and resend the request.

Next steps: Check your API key and retry

### Invalid API Key

```
error, status code: 401, message: The API Key appears to be invalid or may have expired. Please verify your credentials and try again.
```

The API Key is incorrect, or the key has been revoked or expired in the console.

How to fix:

- Typo in key: Check for extra spaces or missing characters
- Key revoked or expired: Go toConsole› API Keys and verify the key's status
- Environment variable override: Check whether a`KIMI_API_KEY`or`OPENAI_API_KEY`environment variable is overriding your config file — run`echo $KIMI_API_KEY`to check

### Invalid Authentication

```
error, status code: 401, message: Invalid Authentication
```

The request did not carry valid credentials, or uses an unsupported authentication format.

How to fix:

The most common cause is accidentally using an API key or Base URL from the Kimi Open Platform. Kimi Code and theKimi Open Platformare two separate systems — keys and Base URLs are not interchangeable:

- Kimi Code: get your key from theConsole, Base URL is`https://api.kimi.ai/coding/v1`(OpenAI protocol) or`https://api.kimi.ai/coding/`(Anthropic protocol)
- Open Platform: get your key fromplatform.kimi.com, Base URL is`https://api.moonshot.ai/v1`

### Account Not Found or Deactivated

```
The account associated with these credentials was not found or is no longer active. Please re-login to refresh your credentials.
```

The account tied to the credentials does not exist or is no longer active (not registered, disabled, etc.), so authentication failed.

Next steps: Check your account status

How to fix:

- Log in again to refresh your credentials and retry
- Confirm the account has completed registration and is not disabled; if it has been disabled, email[email protected]to learn the reason and how to appeal

## Permission Errors

HTTP 403

The request is correctly formatted and identity is verified, but the current account's subscription tier does not include the requested capability (such as K3, K3.1 Pro, 1M context, or the HighSpeed model).Retrying is pointless— upgrade your plan or adjust the request. For quota and concurrency issues, seeQuota & Account Limits.

Next steps: Upgrade your plan or switch models

### Unable to Verify Membership

```
error, status code: 403, message: We're unable to verify your membership benefits at this time. Please ensure your membership is active.
```

How to fix:

- Confirm your Kimi Code subscription is still active
- Wait a moment and retry
- Check your subscription status in theConsole
- If the issue persists, go toKimi.aion the web, click your profile picture in the bottom-left corner, then selectGet Help→User Feedbackto consult our support team

### No K3.1 Pro Access

```
error, status code: 403, message: Your current subscription does not have access to k3.1-pro. Upgrade to higher-tier Kimi Code plans. Upgrade: https://www.kimi.com/code?from=server_k31-pro_error#pricing
```

Your current subscription does not include access to`k3.1-pro`. K3.1 Pro requires a Plus / Moderato plan or above; Pro / Allegretto and above unlock up to 1M context.

How to fix:

- Upgradeto a plan that includes`k3.1-pro`
- If you prefer not to upgrade, switch to a model supported by your current plan

### No`k3.1-pro`1M Access

```
error, status code: 403, message: Your current plan supports only k3.1-pro up to 256K context. 1M context is available on higher-tier Kimi Code plans. Upgrade: https://www.kimi.com/code?from=server_k31-pro_error#pricing
```

Your current plan only supports up to 256K context when calling`k3.1-pro`. To use up to 1M context, upgrade to a Pro / Allegretto plan or above. Plus / Moderato only supports up to 256K context.

How to fix:

- Upgradeto a Pro / Allegretto plan or above
- If you prefer not to upgrade, keep using`k3.1-pro`with 256K context

### No K3 Access

```
error, status code: 403, message: Your current subscription does not have access to k3. Upgrade to higher-tier Kimi Code plans. Upgrade: Upgrade: https://www.kimi.com/code?from=server_k3_error#pricing
```

Your current subscription does not include access to`k3`. Calling K3 requires a Moderato / Plus plan or above.

How to fix:

- Upgradeto a Moderato / Plus plan or above to call`k3`
- If you prefer not to upgrade, keep using the standard model`kimi-for-coding`

### No 1M Access

```
error, status code: 403, message: Your current plan supports only k3 up to 256K context. 1M context is available on higher-tier Kimi Code plans. Upgrade: https://www.kimi.com/code?from=server_k3_error#pricing
```

Your current plan only supports up to 256K context when calling`k3`. The 1M context window for K3 requires an Allegretto / Pro plan or above; Moderato / Plus only supports up to 256K context. When calling`k3-256k`directly with context over 256K, the server returns`k3-256k supports only 256K context.`— compact the context or switch to`k3`.

How to fix:

- Upgradeto an Allegretto / Pro plan or above to use`k3`with up to 1M context
- If you prefer not to upgrade, keep using`k3`with 256K context, or switch to the standard model`kimi-for-coding`

### HighSpeed Model Access Denied

```
error, status code: 403, message: Your current subscription does not have access to kimi-for-coding-highspeed. Upgrade to higher-tier Kimi Code plans. Upgrade: Upgrade: https://www.kimi.com/code?from=server_highspeed_error#pricing
```

Your current subscription does not include access to`kimi-for-coding-highspeed`. K2.7 Code Highspeed requires an Allegretto / Pro plan or above.

How to fix:

- Upgradeto an Allegretto / Pro plan or above to unlock the high-speed model
- If you prefer not to upgrade, keep using the standard model`kimi-for-coding`— identical capabilities, just slower output

### K3.1 Pro Highspeed Access Denied

```
error, status code: 403, message: Your current subscription does not have access to k3.1-pro-highspeed. Upgrade to higher-tier Kimi Code plans. Upgrade: https://www.kimi.com/code?from=server_k31-pro-highspeed_error#pricing
```

Your current subscription does not include access to`k3.1-pro-highspeed`. K3.1 Pro Highspeed requires a Pro / Allegretto plan or above.

How to fix:

- Upgradeto a plan that includes`k3.1-pro-highspeed`
- If you prefer not to upgrade, switch to a model supported by your current plan

## Quota & Account Limits

HTTP 429

Account-side limits have been reached, in two kinds: quota errors are account usage issues —retrying is pointless; wait for the window to reset, purchase extra usage, or upgrade your plan. The concurrent request limit is triggered by risk-control policy, is unrelated to your remaining quota, and follows the appeal process. When an enterprise user's personal 5-hour, 7-day, or monthly quota is exhausted, it is also handled as 429 with the same message as for personal plans.

Next steps: Wait for reset, buy Extra Usage or upgrade; for transient limits, retry later

### 5-Hour Rolling Quota Reached

```
error, status code: 429, message: You've reached your 5-hour usage limit. Your quota will reset when the current 5-hour window ends. To continue now, purchase extra usage or upgrade your plan: https://www.kimi.com/membership/subscription?tab=quota
```

The call volume within the current 5-hour rolling window has reached its limit.

How to fix:

- Wait for the current 5-hour window to end and the quota to reset; check the reset time in theConsole
- Go to theSubscription pageto purchase extra usage or upgrade your plan for a higher limit

### Weekly (7-Day) Quota Exhausted

```
error, status code: 429, message: You've reached your weekly (7-day) usage limit. Your quota will reset when the current 7-day window ends. To continue now, purchase extra usage or upgrade your plan: https://www.kimi.com/membership/subscription?tab=quota
```

Kimi Code subscription quota refreshes every 7 days, and the quota in the current 7-day window has been fully used. This error only applies to existing members on legacy plans (Andante / Moderato / Allegretto / Allegro); new members (Go / Plus / Pro / Max / Ultra) are not subject to the weekly quota limit and never trigger this error.

How to fix:

- Wait for the current 7-day window to end and the quota to refresh; view usage details in theConsole
- Go to theSubscription pageto purchase extra usage or upgrade your plan for a higher quota

### Monthly Kimi Quota Exhausted

```
error, status code: 429, message: You've reached your monthly usage limit for this billing cycle. Your quota will be refreshed in the next cycle. To continue now, purchase extra usage or upgrade your plan: https://www.kimi.com/membership/subscription?tab=quota
```

Your Kimimonthly quotahas been fully consumed.

All Kimi membership benefits — including PPT, Agent Cluster, Kimi Code, etc. — share the same monthly quota. Once the total quota is exhausted, further requests cannot be made even if Kimi Code still has remaining quota. You must wait for the quota to automatically reset next month or upgrade your plan. SeeKimi Membership Creditsfor details. When the quota is exhausted, your account enters a frozen state, as shown below:

How to fix:

- Wait for automatic reset at the start of the next billing cycle
- Go to theSubscription pageto purchase extra usage or upgrade your plan for a higher monthly quota

### Insufficient Credit Balance

```
error, status code: 429, message: Your credit balance is insufficient. To continue, purchase extra usage or upgrade your plan: https://www.kimi.com/membership/subscription?tab=quota
```

The account's available credit balance is insufficient to continue making calls.

How to fix:

- Go to theSubscription pageto purchase extra usage or upgrade your plan, then retry

### Organization Extra Usage Balance Exhausted

```
error, status code: 429, message: Your organization's Extra Usage balance has run out. Please contact your organization admin to add more Extra Usage.
```

The organization's Extra Usage balance has been exhausted.

How to fix:

- Contact your organization admin to add more Extra Usage

### Organization Extra Usage Limit Reached

```
error, status code: 429, message: You've reached the Extra Usage limit set for your account by your organization. Please contact your organization admin to increase your limit.
```

The personal Extra Usage limit set by your organization for this account has been reached.

How to fix:

- Contact your organization admin to increase your personal Extra Usage limit

### Concurrent Request Limit

```
error, status code: 429, message: You've reached your concurrent request limit. Please wait for your ongoing requests to finish and try again.
```

The number of in-flight requests on the account has reached its limit. This limit is triggered by risk-control policy.

Next steps: Slow down and retry after ongoing requests finish

How to fix:

- Read theKimi Code Community Guidelinesto understand the violation and what is covered
- To appeal, email[email protected]and include your account ID, the time the limit was triggered, a description of your use case, and relevant screenshots
Transient limits —a short wait and retry recovers them: too many concurrent requests means the account sent too many requests in a short period; inference engine overload is a server capacity issue, not related to your personal quota or account status.

### Too Many Concurrent Requests

```
error, status code: 429, message: We're receiving too many requests at the moment. Please wait a moment and try again.
```

Too many requests were sent in a short period, exceeding the account limit.

Next steps: Slow down and retry after ongoing requests finish

How to fix:

- Wait a moment and retry; avoid sending requests rapidly in succession

### Inference Engine Overloaded

```
error, status code: 429, message: The engine is currently overloaded, please try again later
```

The server's current request volume exceeds inference capacity.Not related to your personal quota or account status.This may occur during peak hours (14:00–17:00 on weekdays). Kimi Code will address this promptly — you can also avoid peak hours.

Next steps: Retry later

How to fix:

- Wait a moment and retry

## Request Format Errors

HTTP 400

The request content itself has a problem; the server rejects it during parsing or validation.Fix the request content — no need to wait or contact support.

Next steps: Check your input and retry

### Message Body Exceeds Context Limit

```
error, status code: 400, message: total message size 5943865 exceeds limit 2097152
```

The total size of all messages (including conversation history, system prompt, and tool results) exceeds the 2 MB context limit. This is the most frequently occurring 400 error.

How to fix:

- Trim conversation history by removing unnecessary earlier turns
- Process long content in segments to keep each request within size limits

### Token Limit Exceeded

```
error, status code: 400, message: Invalid request: Your request exceeded model token limit: 262144 (requested: 558009)
```

The number of tokens in the request exceeds the model's single-request limit (262,144 tokens).

How to fix:

- Shorten the prompt or truncate conversation history
- Process long content across multiple requests

### Missing Reasoning Content Field

```
error, status code: 400, message: thinking is enabled but reasoning_content is missing in assistant tool call message at index 2
```

Extended thinking (thinking mode) is enabled, but the`reasoning_content`field is missing from the tool call message. This is a Kimi Code-specific field required when thinking mode is active.

How to fix:

- Add the`reasoning_content`field to the assistant message in tool calls
- See theProviders and modelsfor the field specification

### Disabled Thinking Mode

```
error, status code: 400, message: K3.1 Pro does not support disabled thinking mode. Please enable thinking and try again, or choose another model.
```

K3.1 Pro does not support disabling thinking mode.

How to fix:

- Enable thinking and retry, or go to themodel listto choose another model

### Model ID Does Not Exist

```
error, status code: 400, message: Your model id does not exist, recognized as ${model_from_user}. Please set model id as `k3`.
```

The model ID used in the request is not recognized by the model side. A common cause is using a format that's only valid in a specific scenario in an entry that doesn't support it. The`k3[1m]`and`k3.1-pro[1m]`forms (with surrounding quotes) are only for Claude Code environment-variable configurations that explicitly request a 1M context window. In API requests or other third-party tools' Model ID fields, use a standard model ID.

How to fix:

- Check the actual model id shown after`recognized as other:`in the error message and make sure it has no typos or extra symbols
- If configuring Claude Code environment variables, make sure you followed the example inUsing in More Third-Party Tools, including the surrounding quotes
- For API requests or other third-party tools, change the Model ID to the standard form (e.g.`k3`,`k3.1-pro`,`k3.1-pro-highspeed`,`kimi-for-coding`,`kimi-for-coding-highspeed`) instead of the Claude Code env-only`k3[1m]`or`k3.1-pro[1m]`forms

### Unsupported Image URL

```
error, status code: 400, message: Invalid request: unsupported image url: C:\Users\pc\...\screenshot.jpg
```

The image URL format is not supported: local file paths, non-standard base64 prefixes, or unsupported external domains.

How to fix:

- Local paths must be uploaded to a publicly accessible URL
- Base64 images must use the standard format:`data:image/jpeg;base64,...`

### Duplicate Tool Name

```
error, status code: 400, message: function name unnamed_function is duplicated
```

The`tools`array contains duplicate tool definitions with the same name.

How to fix:

- Ensure every tool's`name`field is unique

### Blocked by model safety policy

```
error, status code: 400, message: The request was rejected because it was considered high risk
```

The model request triggered content safety detection and was rejected by the server.

Next steps: Edit content and retry

How to fix:

- Review your prompt for sensitive content and retry after modification
- If you believe this is a false positive, go toKimi.aion the web, click your profile picture in the bottom-left corner, then selectGet Help→User Feedbackto contact our support team with the content that triggered the error

## Resource Not Found

HTTP 404

The requested resource does not exist, or the current account does not have access. For model calls, verify the model name and endpoint path; for tool calls, check the data source name, URL, or query.

Next steps: Check the model name and API path

### Model Not Found

```
error, status code: 404, message: Not found the model kimi-for-coding or Permission denied
```

How to fix:

- Confirm the model name is spelled correctly (`kimi-for-coding`)
- Confirm the account hasKimi Codeaccess enabled

### Endpoint Not Found

```
method not found
```

How to fix:

- Verify the request URL is correct. Kimi Code Base URL is`https://api.kimi.ai/coding/v1`(OpenAI protocol) or`https://api.kimi.ai/coding/`(Anthropic protocol)

### Data Source Not Found

```
data source '<name>' not found
tool is not configured
```

The data source requested in the tool call does not exist, is not configured, or has been retired.

How to fix:

- Check the data source name spelling, or switch to another data source
- Confirm the tool and data source are configured in the current environment

### Web Page or Search Content Not Found

```
The requested resource or content was not found. Check your request or try a different source.
```

The page fetch or web search tool found no target content: the page does not exist (404/410), the search returned no results, or the page has no extractable content.

How to fix:

- Verify the URL is valid, or switch to a different source
- Adjust the query and retry

## Internal Server Errors

HTTP 500

An unexpected error occurred on the server —not caused by request content or account status. In most cases, waiting a moment and retrying will resolve it. If the issue persists, go toKimi.aion the web, click your profile picture in the bottom-left corner, then selectGet Help→User Feedbackto contact our support team.

Next steps: Retry later

The most common generic message (the underlying cause is not exposed; include the request ID when reporting the issue):

```
error, status code: 500, message: The server had an error while processing your request
```

### Invalid bot_id Format

```
invalid_argument: field kimi.billing.v1.ClawExtension.bot_id: value "KIMI_CLAW_ID" (id_kind=uuid_v4): value does not match id_kinds: [uuid_v4]
invalid_argument: field kimi.billing.v1.ClawExtension.bot_id: value "openclaw-local" (id_kind=uuid_v4): value does not match id_kinds: [uuid_v4]
```

The`bot_id`is not a valid UUID v4. This field is automatically attached by client software when sending requests — it is normally managed by the client, not set manually by users.

How to fix:

- Update to the latest version of your client and retry
- If the issue persists after updating, go toKimi.aion the web, click your profile picture in the bottom-left corner, then selectGet Help→User Feedbackto contact our support team with the full error message

### Database Connection Failed

```
internal: failed to connect to `user=kimi_chat_prod_rw database=membership_009`: ...: connection reset by peer
internal: FATAL: terminating connection due to administrator command (SQLSTATE 57P03)
```

The server could not connect to the membership verification database, or the database is under maintenance. This is an infrastructure-level failure.

How to fix:

- Wait 1–2 minutes and retry

### Internal Connection Error

```
internal: conn closed
internal: driver: bad connection
internal: read tcp ...: i/o timeout
internal: unexpected EOF
internal: failed to evaluate rate limit script: read tcp ...: i/o timeout
```

Internal network connection anomaly on the server side, covering connection resets, I/O timeouts, Redis rate-limit script timeouts, and similar low-level errors. Usually a transient fault.

How to fix:

- Wait a moment and retry (start with 1 second, up to 3 retries)

### Account Status Abnormal

```
unauthenticated: not_found: 未找到该账号，请确认是否注册
unauthenticated: failed_precondition: 因违反用户协议，该账号已被禁用。
unauthenticated: failed_precondition: 因违反用户协议，该账号已被暂时禁用。
unauthenticated: failed_precondition: 因违反用户协议，该账号已被禁言。
```

When the server queried the account, it found the account does not exist or is in an abnormal state.

Next steps: Check your account status

How to fix:

- Account not found: confirm the account you are using has completed registration
- Account disabled/muted: email[email protected]to learn the reason and how to appeal

## Bad Gateway

HTTP 502

The server-side reverse proxy or gateway layer failed while calling a downstream model or infrastructure component.

Next steps: Retry later

```
Bad Gateway
```

How to fix:

- Wait a moment and retry

## Tool Call Errors

The following errors occur during AI tool call execution (such as reading web pages or processing images).They do not affect the conversation itself— only the specific operation that failed.

If you see authentication or rate-limiting errors (e.g. 401, 429) in this context, refer to the corresponding sections above.

### Request Canceled

HTTP 499

```
error sending 'CallDataSourceTool' request: Post "http://...": context canceled
canceled: context canceled
```

The client disconnected before receiving a result — typically caused by the user manually stopping the operation, a network interruption, or a client-side timeout. Not a server error.

Next steps: Send the request again

How to fix:

- If manually stopped, no action needed
- If this happens frequently without being triggered manually, check whether your client's timeout setting is too short

### Web Page Read Failed

HTTP 500

```
The service failed to process the request. Try again later.
```

The AI failed to read the web page content. The server returns a generic message without exposing the underlying cause. Common causes: target page response timeout, abnormal page structure, login required, URL on blocklist, anti-bot restrictions on the target site, or the target server itself returned an error.

Next steps: Retry later

How to fix:

- Timeout or server error: retry later
- Login-required or anti-bot pages: cannot be read via tool call — manually copy the content and paste it to the AI, or switch to a different URL or source
- Blocklisted URL: the address is blocked by the system and cannot be accessed

### Search or Fetch Service Unavailable or Timed Out

HTTP 503 / 504

```
The service is temporarily unavailable. Try again later.
The request timed out. Try again later.
```

The web search or page fetch service is temporarily unavailable (503), or the gateway timed out waiting for the upstream response (504) while the AI was using the web search or page read tool.

Next steps: Retry later

How to fix:

- Wait a moment and retry

### Image Moderation Failed

HTTP 403

```
The request was blocked for safety reasons. Try a different query, URL, or source.
```

While the AI was processing an image, the content did not pass the safety review, or the moderation service was temporarily unavailable — both are handled uniformly as a safety rejection without exposing the underlying cause.

Next steps: Edit sensitive content and retry

How to fix:

- Content violation: ensure the image complies with usage policies and switch to a different image
- Moderation service issue: retry later

### URL Security Risk Blocked

HTTP 403

```
The request was blocked for safety reasons. Try a different query, URL, or source.
```

The URL or search content passed in the tool call did not pass the safety review (including internal network addresses, known high-risk domains, and blocklisted sources), and the server refused to process it.

How to fix:

- Internal network addresses (e.g.`192.168.x.x`,`10.x.x.x`) and known high-risk domains will be blocked
- Pages that require login cannot be fetched via tool calls

### Invalid URL Format

HTTP 400

```
(invalid_url) The provided URL is invalid: parse "https://the repo for the contents of the path": invalid character " " in host name
(invalid_url) The provided URL is invalid: missing scheme
```

The model passed a natural-language description or a malformed string as a URL to the tool — typically a descriptive phrase treated as an address, or a missing protocol prefix.

Next steps: Check the URL format and retry

How to fix:

- Ensure the value passed is a real, accessible URL — not a text description of one
- The protocol prefix (`http://`or`https://`) is required and cannot be omitted
