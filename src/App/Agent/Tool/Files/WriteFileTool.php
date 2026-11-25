<?php

declare(strict_types=1);

namespace OpenCCK\App\Agent\Tool\Files;

use Mercurius\Llm\ToolDefinition;
use OpenCCK\App\Agent\Tool\Args;
use OpenCCK\App\Agent\Tool\Sandbox\SandboxTool;
use OpenCCK\App\Agent\Tool\ToolContext;
use OpenCCK\App\Agent\Tool\ToolResult;
use OpenCCK\Domain\Agent\DangerClass;
use OpenCCK\Domain\Agent\SandboxLimits;

use function Mercurius\I18n\t;

final class WriteFileTool extends SandboxTool implements \OpenCCK\App\Agent\Tool\GrantKeyed {
    public const NAME = 'write_file';

    /** \var (\Closure(int, string): void)|null told of a written .mcp.json: the member and the path */
    private ?\Closure $mcpChanged = null;

    /**
     * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи — a written .mcp.json is checked as an MCP file and its
     *       context reads it at once
     *
     * \param \Closure(int, string): void $changed
     */
    public function useMcpFiles(\Closure $changed): void {
        $this->mcpChanged = $changed;
    }

    /** Why the content of a .mcp.json must not be written; null — it may. */
    private static function mcpProblem(string $content): ?string {
        $file = \OpenCCK\App\Mcp\McpFile::parse($content);
        if ($file->errors !== []) {
            return implode("\n", $file->errors);
        }
        $guard = new \OpenCCK\App\Mcp\McpEntryGuard();
        foreach ($file->entries as $entry) {
            $problem = $guard->problem($entry);
            if ($problem !== null) {
                return $problem;
            }
        }
        return null;
    }

    private static function isMcpFile(string $path): bool {
        return basename(str_replace('\\', '/', $path)) === \OpenCCK\App\Mcp\McpFile::NAME;
    }

    public function definition(): ToolDefinition {
        return new ToolDefinition(self::NAME, t('tool.write_file.pishet_fayl_v_tvoem_rabochem')
            . t('tool.write_file.zamenoy_fragmenta_old_new_old'), [
            'type' => 'object',
            'properties' => [
                'area' => self::areaProperty(),
                'path' => ['type' => 'string', 'maxLength' => 4096],
                'content' => ['type' => 'string'],
                'old' => ['type' => 'string'],
                'new' => ['type' => 'string'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ]);
    }

    /** @param array<string, mixed> $args */
    private function ownDanger(array $args): DangerClass {
        return DangerClass::Write;
    }

    protected function run(array $args, ToolContext $context, SandboxLimits $limits): ToolResult {
        $path = Args::string($args, 'path') ?? '';
        $content = Args::string($args, 'content');
        $old = Args::string($args, 'old');
        $new = Args::string($args, 'new');
        if ($content !== null && $old === null && $new === null) {
            // spec: brief/skills-mcp.md#как-сделано-каталог-замок-и-проверка-скиллов — a SKILL.md stays a skill: a file that
            //       is not one would vanish from the index, so it is refused here and skill_save is named
            if (preg_match('~(?:^|/)\.mercurius/skills/([^/]+)/SKILL\.md$~', str_replace('\\', '/', $path), $m) === 1) {
                try {
                    $name = \OpenCCK\App\Skill\SkillBundle::fromFiles(['SKILL.md' => $content], 'folder', '')->name;
                } catch (\InvalidArgumentException $e) {
                    return ToolResult::error(sprintf(t('tool.write_file.not_a_skill'), $e->getMessage()));
                }
                if ($name !== strtolower($m[1])) {
                    return ToolResult::error(sprintf(t('tool.write_file.not_a_skill'), sprintf(t('tool.write_file.skill_name'), $name, $m[1])));
                }
            }
            if (self::isMcpFile($path)) {
                $problem = self::mcpProblem($content);
                if ($problem !== null) {
                    return ToolResult::error(sprintf(t('tool.write_file.not_an_mcp_file'), $problem));
                }
            }
            $this->sandbox->write($this->holder($args, $context), $limits, $path, $content, $context->cancellation);
            if (self::isMcpFile($path) && $this->mcpChanged !== null) {
                ($this->mcpChanged)($this->holder($args, $context), $path);
            }
            return ToolResult::ok(sprintf(t('tool.write_file.fayl_zapisan_bayt'), $path, strlen($content)));
        }
        if ($content === null && $old !== null && $new !== null) {
            $this->sandbox->replace($this->holder($args, $context), $limits, $path, $old, $new, $context->cancellation);
            // a piece replaced is checked when the file is read: an entry that cannot run becomes an error there
            if (self::isMcpFile($path) && $this->mcpChanged !== null) {
                ($this->mcpChanged)($this->holder($args, $context), $path);
            }
            return ToolResult::ok(sprintf(t('tool.write_file.fayl_izmenen'), $path));
        }
        return ToolResult::error(t('tool.write_file.ukazhi_content_ili_paru_old'));
    }

    public function danger(array $args): DangerClass {
        return self::areaDanger($args, $this->ownDanger($args));
    }

    /** @param array<string, mixed> $args a write in the owners' area has a grant of its own; in one's folder — as before */
    public function grantKey(array $args): ?string {
        return self::areaGrant($args, null, 'write_file');
    }

    /** @param array<string, mixed> $args */
    public function describe(array $args): string {
        return self::areaLabel($args, 'write_file ' . (\OpenCCK\App\Agent\Tool\Args::string($args, 'path') ?? ''));
    }
}
