-- Cookies that servers gave to http_request: a session the agent logged into stays for the project (or the participant
-- when there is no project), like a browser profile. The model never sees them.
-- spec: BRIEF.md#решения-по-доработкам-2026-11-18 — cookies of http_request are kept like browser profiles
CREATE TABLE http_cookies (
    scope      text        NOT NULL,
    host       text        NOT NULL,
    name       text        NOT NULL,
    value      text        NOT NULL,
    expires_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (scope, host, name)
);
CREATE INDEX http_cookies_expires ON http_cookies (expires_at);
