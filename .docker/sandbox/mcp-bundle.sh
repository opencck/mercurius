#!/bin/sh
# The MCPs of the delivery (submodules mcp/<name>) for the sandbox image: dependencies installed at build, a revision —
# a print of the content without dependencies (the image has no git) — and the index the application reads at start.
# spec: brief/skills-mcp.md#предустановленные-mcp-ssh-и-mail
set -eu
root="${1:-/opt/mercurius/mcp}"
for dir in "$root"/*/; do
    dir="${dir%/}"
    [ -f "$dir/mercurius.mcp.json" ] || { rm -rf "$dir"; continue; }
    rm -rf "$dir/vendor" "$dir/var" "$dir/test" "$dir/.git"
    if [ -f "$dir/composer.json" ]; then
        composer install -d "$dir" --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader
    fi
    (cd "$dir" && find . -type f ! -path './vendor/*' | LC_ALL=C sort | xargs sha256sum | sha256sum | cut -c1-12) > "$dir/.revision"
done
php -r '
$index = [];
foreach (glob($argv[1] . "/*/mercurius.mcp.json") as $plan) {
    $dir = dirname($plan);
    $composer = json_decode((string) @file_get_contents($dir . "/composer.json"), true);
    $index[] = [
        "name" => basename($dir),
        "package" => is_array($composer) ? ($composer["name"] ?? null) : null,
        "revision" => trim((string) file_get_contents($dir . "/.revision")),
        "plan" => json_decode((string) file_get_contents($plan), true),
    ];
}
file_put_contents($argv[1] . "/index.json", json_encode($index, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
' "$root"
chmod -R a+rX "$root"
