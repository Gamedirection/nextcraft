#!/usr/bin/env bash
# Installs or upgrades NextCraft into a Nextcloud, then (optionally) installs the Craft apps.
#
#   deploy/install.sh --docker nextcloud              # Nextcloud in a container named "nextcloud"
#   deploy/install.sh --path /var/www/nextcloud       # Nextcloud on this machine
#   options:
#     --apps all|none|"wordcraft gridcraft …"         which Craft apps to install/update (default: all)
#     --user www-data                                  web server user (default: www-data)
#     --skip-build                                     use the existing js/ build
#
# Re-running it upgrades NextCraft in place; installed Craft apps are kept (they are restored
# from NextCraft's cache if the copy replaced them).
set -euo pipefail
cd "$(dirname "$0")/.."

container="" path="" apps="all" web_user="www-data" build=1
while [[ $# -gt 0 ]]; do
    case "$1" in
        --docker) container="$2"; shift 2 ;;
        --path) path="$2"; shift 2 ;;
        --apps) apps="$2"; shift 2 ;;
        --user) web_user="$2"; shift 2 ;;
        --skip-build) build=0; shift ;;
        -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
        *) echo "unknown option $1" >&2; exit 2 ;;
    esac
done
[[ -n "$container" || -n "$path" ]] || { echo "use --docker <container> or --path <nextcloud dir>" >&2; exit 2; }

if (( build )); then
    if command -v npm >/dev/null && [[ "$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)" -ge 20 ]]; then
        npm ci --no-audit --no-fund --loglevel=error && npm run build
    else
        docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" node:22-alpine sh -c 'npm ci --no-audit --no-fund --loglevel=error && npm run build'
    fi
fi
[[ -f js/nextcraft-shim.js && -f js/nextcraft-files.mjs ]] || { echo "build output missing (js/)" >&2; exit 1; }

# The app as shipped: no sources, no node_modules, no source maps, no installed Craft builds.
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/nextcraft"
tar --exclude='*.map' -cf - appinfo lib templates css img js craft/.htaccess LICENSE README.md CHANGELOG.md 2>/dev/null \
    | tar -xf - -C "$stage/nextcraft"

occ() {
    if [[ -n "$container" ]]; then
        docker exec -u "$web_user" "$container" php occ "$@"
    else
        sudo -u "$web_user" php "$path/occ" "$@"
    fi
}

# Where apps go: the first writable apps_paths entry (usually custom_apps).
apps_dir=$(occ config:system:get apps_paths --output=json 2>/dev/null \
    | php -r '$p = json_decode(stream_get_contents(STDIN), true) ?: []; foreach ($p as $e) { if (!empty($e["writable"])) { echo $e["path"]; exit; } }' 2>/dev/null || true)
if [[ -z "$apps_dir" ]]; then
    apps_dir=$([[ -n "$container" ]] && echo /var/www/html/custom_apps || echo "$path/custom_apps")
fi
echo "Installing NextCraft into $apps_dir/nextcraft"

if [[ -n "$container" ]]; then
    docker exec "$container" sh -c "mkdir -p '$apps_dir/nextcraft' && find '$apps_dir/nextcraft' -mindepth 1 -maxdepth 1 ! -name craft -exec rm -rf {} +"
    docker cp "$stage/nextcraft/." "$container:$apps_dir/nextcraft/"
    docker exec "$container" chown -R "$web_user:$web_user" "$apps_dir/nextcraft"
else
    sudo mkdir -p "$apps_dir/nextcraft"
    sudo find "$apps_dir/nextcraft" -mindepth 1 -maxdepth 1 ! -name craft -exec rm -rf {} +
    sudo cp -a "$stage/nextcraft/." "$apps_dir/nextcraft/"
    sudo chown -R "$web_user:$web_user" "$apps_dir/nextcraft"
fi

if occ app:list --output=json | grep -q '"nextcraft"'; then
    occ app:enable nextcraft >/dev/null
    occ upgrade >/dev/null || true
else
    occ app:enable nextcraft
fi
occ maintenance:mimetype:update-js >/dev/null

case "$apps" in
    none) ;;
    all) occ nextcraft:install --all ;;
    *) occ nextcraft:install $apps ;;
esac
occ nextcraft:status
echo "Done. Users find NextCraft under Files ▸ Open with…, admins under Administration settings ▸ NextCraft."
