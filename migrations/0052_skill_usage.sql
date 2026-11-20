-- How skills are used: openings, the last one and the owner's pin (a pinned skill is never marked stale).
-- spec: brief/decisions.md#самоулучшение-решено-2026-11-18
CREATE TABLE skill_usage (
    name text PRIMARY KEY,
    views integer NOT NULL DEFAULT 0,
    last_viewed timestamptz,
    pinned boolean NOT NULL DEFAULT false
);
