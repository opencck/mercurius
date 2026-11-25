<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use InvalidArgumentException;
use OpenCCK\Domain\Mcp\McpServer;

use function Mercurius\I18n\t;

/**
 * One entry of a context's .mcp.json, in the format of Claude Code: a server by `url` (with `headers`) or a local one
 * by `command`, `args` and `env`; `"disabled": true` turns it off. Its hash — of the entry without the switch, before
 * secrets are put in — is what an approval stands for.
 *
 * spec: brief/skills-mcp.md#файл-mcpjson-и-одобрение-записи
 * ref: references/hermes-agent/hermes_cli/mcp_config.py@e96221fa:L683-L692 — an entry of hermes is the same url/headers
 *      or command/args/env; its tools.include/exclude and enabled live in the entry. Here the format is Claude Code's
 *      (owner's decision): the switch is `disabled`, the filters of tools stay in the store and are not approved again
 * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L158-L159 (_ENV_VAR_PATTERN) — ${NAME}; a bare {NAME} is
 *      taken too, as the spec writes secrets
 */
final class McpEntry {
    /** a secret named in a value: {name} or ${name} */
    private const SECRET = '~^\$?\{([A-Za-z0-9_.:-]{1,64})\}$~';
    private const ANY_SECRET = '~\$?\{[A-Za-z0-9_.:-]{1,64}\}~';

    /**
     * @param array<string, string> $headers
     * @param list<string> $args
     * @param array<string, string> $env
     */
    private function __construct(
        public readonly string $name,
        public readonly ?string $url,
        public readonly array $headers,
        public readonly ?string $command,
        public readonly array $args,
        public readonly array $env,
        public readonly bool $disabled,
    ) {}

    /**
     * @param array<array-key, mixed> $entry
     * @throws InvalidArgumentException what is wrong with the entry
     */
    public static function fromArray(string $name, array $entry): self {
        if (preg_match(McpServer::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(t('mcp.file.bad_name'), mb_substr($name, 0, 40)));
        }
        $url = self::text($entry, 'url');
        $command = self::text($entry, 'command');
        if (is_string($url) === is_string($command)) {
            throw new InvalidArgumentException(sprintf(t('mcp.file.url_or_command'), $name));
        }
        if (is_string($url) && preg_match('~^https?://\S+$~', $url) !== 1) {
            throw new InvalidArgumentException(sprintf(t('mcp.file.bad_url'), $name));
        }
        if (is_string($command) && (trim($command) === '' || preg_match('~[\x00-\x1F]~', $command) === 1)) {
            throw new InvalidArgumentException(sprintf(t('mcp.file.bad_command'), $name));
        }
        return new self(
            $name,
            is_string($url) ? $url : null,
            self::strings($entry['headers'] ?? [], $name, keyed: true),
            is_string($command) ? $command : null,
            array_values(self::strings($entry['args'] ?? [], $name, keyed: false)),
            self::strings($entry['env'] ?? [], $name, keyed: true),
            ($entry['disabled'] ?? false) === true,
        );
    }

    /** @param array<array-key, mixed> $entry */
    private static function text(array $entry, string $key): ?string {
        return isset($entry[$key]) && is_string($entry[$key]) ? $entry[$key] : null;
    }

    /**
     * @return ($keyed is true ? array<string, string> : array<int, string>)
     * @throws InvalidArgumentException
     */
    private static function strings(mixed $value, string $name, bool $keyed): array {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(t('mcp.file.not_strings'), $name));
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($item) || ($keyed && (!is_string($key) || preg_match('~^[A-Za-z_][A-Za-z0-9_-]*$~', $key) !== 1))) {
                throw new InvalidArgumentException(sprintf(t('mcp.file.not_strings'), $name));
            }
            $out[$key] = $item;
        }
        return $out;
    }

    /** @return array<string, mixed> the entry as the file holds it */
    public function toArray(): array {
        $entry = $this->url !== null
            ? ['url' => $this->url] + ($this->headers === [] ? [] : ['headers' => $this->headers])
            : ['type' => 'stdio', 'command' => $this->command] + ($this->args === [] ? [] : ['args' => $this->args]) + ($this->env === [] ? [] : ['env' => $this->env]);
        return $entry + ($this->disabled ? ['disabled' => true] : []);
    }

    /** The approved entry: of the entry without its switch, before secrets are put in. */
    public function hash(): string {
        $entry = $this->toArray();
        unset($entry['disabled']);
        return hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function withDisabled(bool $disabled): self {
        return new self($this->name, $this->url, $this->headers, $this->command, $this->args, $this->env, $disabled);
    }

    /**
     * What keeps the entry from running now; null — nothing. Until secrets are put in by their binding to an MCP, a
     * secret is taken from the vault of the context's owner in two places only: the bearer token of a server by url
     * and one variable of a local one.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов
     */
    public function problem(): ?string {
        $secrets = 0;
        foreach ([...$this->args, ...($this->command === null ? [] : [$this->command]), ...($this->url === null ? [] : [$this->url])] as $value) {
            if (preg_match(self::ANY_SECRET, $value) === 1) {
                return sprintf(t('mcp.file.secret_later'), $this->name);
            }
        }
        foreach ($this->headers as $header => $value) {
            if (preg_match(self::ANY_SECRET, $value) === 1 && (strtolower($header) !== 'authorization' || preg_match('~^Bearer \$?\{[A-Za-z0-9_.:-]{1,64}\}$~', $value) !== 1)) {
                return sprintf(t('mcp.file.secret_later'), $this->name);
            }
        }
        foreach ($this->env as $value) {
            if (preg_match(self::ANY_SECRET, $value) === 1) {
                if (preg_match(self::SECRET, $value) !== 1 || ++$secrets > 1) {
                    return sprintf(t('mcp.file.secret_later'), $this->name);
                }
            }
        }
        return null;
    }

    /**
     * The address and the secret of the connection: an http(s) url, or `stdio:<command and args>`; the secret as the
     * connection names it (`name`, or `name:VARIABLE` for a local server).
     *
     * @return array{string, ?string}
     */
    public function connection(): array {
        if ($this->url !== null) {
            $secret = null;
            foreach ($this->headers as $header => $value) {
                if (strtolower($header) === 'authorization' && preg_match('~^Bearer \$?\{([A-Za-z0-9_.:-]{1,64})\}$~', $value, $m) === 1) {
                    $secret = $m[1];
                }
            }
            return [$this->url, $secret];
        }
        // the command runs in the Linux sandbox: POSIX quoting, whatever the platform of the application
        $command = 'stdio:' . (string) $this->command . implode('', array_map(static fn(string $a): string => " '" . str_replace("'", "'\''", $a) . "'", $this->args));
        foreach ($this->env as $variable => $value) {
            if (preg_match(self::SECRET, $value, $m) === 1) {
                return [$command, $m[1] . ':' . $variable];
            }
        }
        return [$command, null];
    }
}
