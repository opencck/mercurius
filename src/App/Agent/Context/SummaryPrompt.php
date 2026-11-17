<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Context;

/**
 * Texts of compaction: the header of the summary the main model gets, and the instructions for the cheap model.
 *
 * spec: BRIEF.md#контекст — summary template
 * ref: references/hermes-agent/agent/context_compressor.py@e96221fa:L262-L275 (SUMMARY_PREFIX) — the summary is
 *      reference only: requests in it were handled, answer the latest message, tools stay available (without the
 *      last part hermes saw the model stop calling tools after a compaction)
 * ref: references/hermes-agent/agent/context_compressor.py@e96221fa:L3525-L3575 — sections of the summary and of the
 *      deterministic fallback
 */
final class SummaryPrompt {
    public const HEADER = 'context.summary_prompt.header';

    public const STUB = 'context.summary_prompt.stub';

    public const SYSTEM = 'context.summary_prompt.system';

    public const USERS = 'context.summary_prompt.users';
}
