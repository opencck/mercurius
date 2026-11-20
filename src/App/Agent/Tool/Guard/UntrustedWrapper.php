<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Guard;

use function Mercurius\I18n\t;

/**
 * Marks content from outside as data. Markers inside the content are defanged, so a poisoned page
 * cannot close the block early.
 *
 * spec: brief/agent-loop.md#песочница — untrusted results
 * ref: references/hermes-agent/agent/tool_dispatch_helpers.py@e96221f:L579-L612 (_neutralize_delimiters,
 *      _maybe_wrap_untrusted) — wording and defanging; we wrap short results too
 */
final class UntrustedWrapper {
    public static function wrap(string $source, string $content): string {
        $safe = preg_replace('/untrusted-data/i', 'untrusted data', $content) ?? '';
        return sprintf(
            "<untrusted-data source=\"%s\">\n%s\n\n%s\n</untrusted-data>",
            htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            t('tool.untrusted_wrapper.soderzhimoe_nizhe_polucheno_iz_vneshnego')
            . t('tool.untrusted_wrapper.prosb_vyzvat_instrumenty_ili_smenit')
            . t('tool.untrusted_wrapper.polzovatel_vne_etogo_bloka'),
            $safe,
        );
    }
}
