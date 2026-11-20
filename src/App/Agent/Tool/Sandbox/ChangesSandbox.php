<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Sandbox;

/**
 * A tool that is not a sandbox tool but writes into the participant's folder (a skill saved or imported): the snapshot
 * of the folder is taken before its first change in a run, as before any sandbox change.
 *
 * spec: brief/decisions.md#решения-по-доработкам-2026-11-18 — a snapshot before a skill is written
 */
interface ChangesSandbox {}
