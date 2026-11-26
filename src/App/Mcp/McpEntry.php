<?php

declare(strict_types=1);

namespace OpenCCK\App\Mcp;

use Closure;
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
 * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L158-L159 (_ENV_VAR_PATTERN), L571-L600
 *      (_interpolate_env_vars) — ${NAME} in every string of the entry; a bare {NAME} is taken too, as the spec writes
 *      secrets. hermes keeps an unset name as it is; here it is named as missing and the server is not called
 */
final class McpEntry {
    /** a secret named in a value: {NAME} or ${NAME}, the name as /secret keeps it */
    private const PLACEHOLDER = '~\$?\{([\p{L}\p{N}][\p{L}\p{N}._-]{0,63})\}~u';
    /** a command is one word: a program or a path, nothing a shell would read otherwise */
    private const COMMAND = '~^[A-Za-z0-9_./@+-]{1,200}$~';

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
        if (is_string($command) && preg_match(self::COMMAND, $command) !== 1) {
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

    /**
     * The entry with arguments the application adds at the start (the trusted host key of an SSH entry): not in the
     * file, not in the approved hash, but in the rendered one — another value is another process.
     *
     * @param list<string> $args
     */
    public function withMoreArgs(array $args): self {
        return $args === [] ? $this : new self($this->name, $this->url, $this->headers, $this->command, [...$this->args, ...$args], $this->env, $this->disabled);
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
     * The names of secrets the entry asks for, in the order they first appear: url, headers, args, env. The command is
     * one word without braces, so it never names one.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов
     *
     * @return list<string>
     */
    public function placeholders(): array {
        $names = [];
        foreach ([...($this->url === null ? [] : [$this->url]), ...array_values($this->headers), ...$this->args, ...array_values($this->env)] as $value) {
            preg_match_all(self::PLACEHOLDER, $value, $m);
            foreach ($m[1] as $name) {
                $names[mb_strtolower($name)] ??= $name;
            }
        }
        return array_values($names);
    }

    /** A secret in the arguments is seen by other processes of the sandbox in the list of processes: the card warns. */
    public function secretsInArgs(): bool {
        foreach ($this->args as $arg) {
            if (preg_match(self::PLACEHOLDER, $arg) === 1) {
                return true;
            }
        }
        return false;
    }

    /** The address as the file writes it, names of secrets and not their values: for lists and the card. */
    public function address(): string {
        return $this->url ?? self::commandLine((string) $this->command, $this->args);
    }

    /**
     * The entry with the secrets put in: the address (an url, or `stdio:` and the command line), the headers and the
     * environment. A name with no value is left as it is and listed in `missing`; the caller does not call the server
     * then.
     *
     * spec: brief/skills-mcp.md#подстановка-секретов
     * ref: references/hermes-agent/tools/mcp_tool_config.py@e96221fa:L602-L621 (_require_rendered_remote) — a name left
     *      unput fails before the call, naming it; here for a local server too
     *
     * @param Closure(string): ?string $value the value of a name, null — none
     */
    public function render(Closure $value): McpRendered {
        $values = [];
        $missing = [];
        $headers = [];
        foreach ($this->headers as $header => $text) {
            $headers[$header] = self::put($text, $value, $values, $missing);
        }
        $env = [];
        foreach ($this->env as $variable => $text) {
            $env[$variable] = self::put($text, $value, $values, $missing);
        }
        $args = [];
        foreach ($this->args as $text) {
            $args[] = self::put($text, $value, $values, $missing);
        }
        $address = $this->url !== null ? self::put($this->url, $value, $values, $missing) : self::commandLine((string) $this->command, $args);
        $hash = hash('sha256', json_encode([$address, $headers, $env], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return new McpRendered($address, $headers, $env, array_values(array_unique($values)), array_values($missing), $hash);
    }

    /**
     * One value of the entry with its names put in; a name with no value stays and is listed.
     *
     * @param Closure(string): ?string $value
     * @param list<string> $values
     * @param array<string, string> $missing
     */
    private static function put(string $text, Closure $value, array &$values, array &$missing): string {
        if (preg_match_all(self::PLACEHOLDER, $text, $m, PREG_SET_ORDER) === 0) {
            return $text;
        }
        foreach ($m as [$whole, $name]) {
            $found = $value($name);
            if ($found === null || $found === '') {
                $missing[mb_strtolower($name)] ??= $name;
                continue;
            }
            $values[] = $found;
            $text = str_replace($whole, $found, $text);
        }
        return $text;
    }

    /**
     * `stdio:` and the command line: the command is one word of safe characters and goes as it is (the lookup of its
     * package in OSV reads it so); every argument is quoted for the shell of the Linux sandbox, whatever the platform.
     *
     * @param list<string> $args
     */
    private static function commandLine(string $command, array $args): string {
        return 'stdio:' . $command . implode('', array_map(static fn(string $a): string => " '" . str_replace("'", "'\\''", $a) . "'", $args));
    }
}
