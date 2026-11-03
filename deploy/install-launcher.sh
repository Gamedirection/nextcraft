#!/usr/bin/env bash
# Optional: builds GameDirection's ArtCraft Launcher, NextCraft edition (the launcher/ submodule,
# branch NextCraft of https://github.com/Gamedirection/craft-launcher) and installs it into
# NextCraft, where it shows up as "ArtCraft Launcher" in Nextcloud's app menu. Its Open buttons
# open the installed Craft apps in NextCraft.
#
#   deploy/install-launcher.sh --docker nextcloud
#   deploy/install-launcher.sh --path /var/www/nextcloud
#   options:
#     --user www-data     web server user (default: www-data)
#     --src <dir>         launcher checkout to build (default: launcher/, the submodule)
#
# Needs Docker (the Rust/WebAssembly build runs in a container). Re-run it to update.
set -euo pipefail
cd "$(dirname "$0")/.."

container="" path="" web_user="www-data" src="launcher"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --docker) container="$2"; shift 2 ;;
        --path) path="$2"; shift 2 ;;
        --user) web_user="$2"; shift 2 ;;
        --src) src="$2"; shift 2 ;;
        -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
        *) echo "unknown option $1" >&2; exit 2 ;;
    esac
done
[[ -n "$container" || -n "$path" ]] || { echo "use --docker <container> or --path <nextcloud dir>" >&2; exit 2; }

if [[ "$src" == launcher && ! -f launcher/Cargo.toml ]]; then
    git submodule update --init launcher
fi
[[ -f "$src/Cargo.toml" && -f "$src/Dockerfile" ]] || { echo "$src is not a craft-launcher checkout" >&2; exit 1; }

version=$(sed -n '/^\[workspace.package\]/,/^\[/s/^version *= *"\([^"]*\)".*/\1/p' "$src/Cargo.toml" | head -n1)
sha=$(git -C "$src" rev-parse --short HEAD 2>/dev/null || echo local)
label="${version:-0}-nextcraft.${sha}"
image="nextcraft/craft-launcher-build:${sha}"

echo "Building ArtCraft Launcher ${label} (this takes a few minutes the first time)…"
docker build -q -t "$image" "$src" >/dev/null

stage=$(mktemp -d)
trap 'rm -rf "$stage"; [[ -n "${cid:-}" ]] && docker rm "$cid" >/dev/null 2>&1 || true' EXIT
mkdir -p "$stage/artcraft-launcher-web-${label}"
cid=$(docker create "$image")
docker cp "$cid:/usr/share/nginx/html/." "$stage/artcraft-launcher-web-${label}/"
find "$stage" \( -name '*.gz' -o -name '50x.html' -o -name 'craft-links.json' \) -delete
(cd "$stage" && python3 -I -c '
import os, sys, zipfile
with zipfile.ZipFile("launcher.zip", "w", zipfile.ZIP_DEFLATED) as z:
    for root, _, files in os.walk(sys.argv[1]):
        for f in files:
            z.write(os.path.join(root, f))
' "artcraft-launcher-web-${label}")

if [[ -n "$container" ]]; then
    docker cp "$stage/launcher.zip" "$container:/tmp/nextcraft-launcher.zip"
    docker exec "$container" chown "$web_user" /tmp/nextcraft-launcher.zip
    docker exec -u "$web_user" "$container" php occ nextcraft:install artcraft-launcher --zip /tmp/nextcraft-launcher.zip --build-version "$label"
    docker exec "$container" rm -f /tmp/nextcraft-launcher.zip
else
    sudo install -o "$web_user" -m 0600 "$stage/launcher.zip" /tmp/nextcraft-launcher.zip
    sudo -u "$web_user" php "$path/occ" nextcraft:install artcraft-launcher --zip /tmp/nextcraft-launcher.zip --build-version "$label"
    sudo rm -f /tmp/nextcraft-launcher.zip
fi
docker image rm "$image" >/dev/null 2>&1 || true
echo "Done: ArtCraft Launcher is in Nextcloud's app menu."
