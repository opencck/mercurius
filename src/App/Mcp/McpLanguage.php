<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Mercurius\Mcp\McpTexts;

use function Mercurius\I18n\t;

/**
 * The texts of the MCP client package in the agent's language: what a tool result and an error of a server say.
 *
 * spec: brief/skills-mcp.md#скиллы-и-mcp
 */
final class McpLanguage {
    public static function texts(): McpTexts {
        return new McpTexts(
            unknownContent: t('mcp.http_mcp_client.neizvestnoe_soderzhimoe'),
            binary: t('mcp.http_mcp_client.binary'),
            error: t('mcp.http_mcp_client.oshibka'),
            serverError: t('mcp.http_mcp_client.mcp_server_otvetil_oshibkoy'),
            noAnswer: t('mcp.http_mcp_client.mcp_server_ne_otvetil'),
            httpStatus: t('mcp.http_mcp_client.mcp_server_otvetil_http'),
            noReply: t('mcp.http_mcp_client.mcp_server_ne_prislal_otvet'),
        );
    }
}
