#!/usr/bin/env bash
#
# Deploy LobbyHub: pull, install what changed, migrate, rebuild the frontend,
# refresh the caches, restart what runs.
#
#   sudo /var/www/lobbyhub/deploy/deploy.sh
#
# Run as root. Everything that touches the checkout is done as the deploy user
# anyway — a single command run as root leaves behind a file the services
# cannot write, and the failure surfaces days later as something else entirely.
#
# Options:
#   --skip-build   backend only; leaves the running frontend as it is
#   --skip-pull    deploy what is already checked out
#
set -euo pipefail

ROOT="${LOBBYHUB_ROOT:-/var/www/lobbyhub}"
DEPLOY_USER="${LOBBYHUB_USER:-deploy}"
SKIP_BUILD=false
SKIP_PULL=false

for arg in "$@"; do
    case "$arg" in
        --skip-build) SKIP_BUILD=true ;;
        --skip-pull) SKIP_PULL=true ;;
        -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run me as root: sudo $0" >&2; exit 1; }
[ -d "$ROOT/.git" ] || { echo "No checkout at $ROOT" >&2; exit 1; }

step() { printf '\n\033[1;32m==>\033[0m %s\n' "$1"; }
note() { printf '    %s\n' "$1"; }
# Everything the checkout is asked to do, including reading it: git refuses to
# work in a repository owned by another user, and this one belongs to deploy.
#
# -H sets HOME to that user's own, which is where npm and composer keep their
# caches. Pointing them anywhere else means two accounts writing to one cache
# directory, and the second one to arrive is refused.
as_deploy() { sudo -u "$DEPLOY_USER" -H "$@"; }

cd "$ROOT"

# The FPM pool is named after the PHP version, which differs per machine and is
# the one service name worth discovering rather than assuming.
FPM_SERVICE="$(systemctl list-units --type=service --all --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)"

started_at=$(date +%s)

# ---------------------------------------------------------------- code
before="$(as_deploy git rev-parse HEAD)"

if [ "$SKIP_PULL" = false ]; then
    step "Pulling"
    as_deploy git pull --ff-only
fi

after="$(as_deploy git rev-parse HEAD)"

if [ "$before" = "$after" ] && [ "$SKIP_PULL" = false ]; then
    note "Already at $(as_deploy git log -1 --format=%h\ %s)"
else
    as_deploy git --no-pager log --oneline "$before..$after" | sed 's/^/    /' || true
fi

# What changed decides what runs. A backend-only commit has no business
# spending two minutes rebuilding a frontend that did not move.
changed="$(as_deploy git diff --name-only "$before" "$after" 2>/dev/null || echo '')"
changed_in() { [ -z "$changed" ] || printf '%s\n' "$changed" | grep -q "$1"; }

# ---------------------------------------------------------------- backend
if changed_in '^composer\.\(json\|lock\)$'; then
    step "Installing PHP dependencies"
    as_deploy composer install --no-dev --optimize-autoloader --no-interaction
fi

step "Migrating"
as_deploy php artisan migrate --force

step "Rebuilding the framework caches"
# One command for config, routes, events and views. It rewrites the cached
# files rather than clearing them, so nothing serves an empty config in the
# gap — and the application cache, which holds the catalog counters, is left
# alone on purpose.
as_deploy php artisan optimize

# ---------------------------------------------------------------- frontend
if [ "$SKIP_BUILD" = false ] && changed_in '^web/'; then
    if changed_in '^web/package-lock\.json$'; then
        step "Installing frontend dependencies"
        as_deploy npm --prefix web ci
    fi

    step "Building the frontend"
    # NEXT_PUBLIC_* are read here, not at start: whatever web/.env.local says
    # right now is what ends up in the bundle every visitor downloads.
    as_deploy npm --prefix web run build
    BUILT=true
else
    note "Frontend unchanged, skipping the build"
    BUILT=false
fi

# ---------------------------------------------------------------- services
step "Restarting"

if [ -n "$FPM_SERVICE" ]; then
    systemctl reload "$FPM_SERVICE"
    note "reloaded $FPM_SERVICE"
else
    note "no php-fpm unit found — reload it yourself if PHP is served that way"
fi

# Only when there is a new build to serve. Restarting otherwise drops the
# frontend's in-memory cache for nothing.
if [ "$BUILT" = true ]; then
    systemctl restart lobbyhub-web
    note "restarted lobbyhub-web"
fi

systemctl restart lobbyhub-scheduler
note "restarted lobbyhub-scheduler"

# However many workers this machine runs, which is none at the time of writing:
# every Schedule:: entry in routes/console.php is commented out, so nothing
# enqueues QueryServer or SyncSteamGame any more and the Go sweeper does that
# work instead. Not a failure to report, and not something to be told to fix on
# every deploy — so a machine with no workers says nothing here.
#
# The restart itself stays. The jobs, the console commands that dispatch them
# and the unit template are all still in the tree, and a worker enabled again
# later must not be left serving the previous release's code.
#
# Asked to restart a glob that matches nothing, systemd fails the whole call —
# so the instances are counted first.
if systemctl list-units --no-legend 'lobbyhub-worker@*' 2>/dev/null | grep -q .; then
    systemctl restart 'lobbyhub-worker@*'
    note "restarted the workers"
fi

# ---------------------------------------------------------------- check
step "Checking"

check() {
    local label="$1" code
    shift
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" || echo 000)"
    printf '    %-22s %s\n' "$label" "$code"
    [ "$code" = "200" ]
}

failed=false
check "api /up" http://127.0.0.1:8080/up || failed=true
check "api /api/games" http://127.0.0.1:8080/api/games || failed=true
check "site /" -H 'Host: lobbyhub.gg' http://127.0.0.1/ || failed=true

# Is a rendered page actually prerendered?
#
# A status code cannot answer this. A game page whose build artefacts the server
# cannot find still returns 200 with a complete body to curl and to anyone who
# types the URL — what breaks is the *click*: the router gets a postponed shell
# with nothing to resume it, and lands on "This page couldn't load". So the
# check is the header, not the code. `x-nextjs-prerender` is present when the
# shell was found and absent when it was not.
prerender="$(curl -s -o /dev/null -D - --max-time 10 -H 'Host: lobbyhub.gg' \
    http://127.0.0.1/games/rust 2>/dev/null | grep -ci '^x-nextjs-prerender' || true)"

if [ "${prerender:-0}" -ge 1 ]; then
    printf '    %-22s %s\n' "game page prerender" "found"
else
    printf '    %-22s %s\n' "game page prerender" "MISSING"
    echo "      Direct visits work; clicking a game will fail with \"This page couldn't load\"."
    echo "      The build's prerendered pages are not being found. Check that the build ran"
    echo "      through, and that the service can read what it wrote:"
    echo "      ls $ROOT/web/.next/server/app/games/rust.html"
    echo "      sudo -u www-data head -c1 $ROOT/web/.next/server/app/games/rust.html >/dev/null && echo readable"
    failed=true
fi

for unit in lobbyhub-web lobbyhub-scheduler; do
    state="$(systemctl is-active "$unit" 2>/dev/null || true)"
    printf '    %-22s %s\n' "$unit" "$state"
    [ "$state" = "active" ] || failed=true
done

if [ "$failed" = true ]; then
    printf '\n\033[1;31m==>\033[0m Deployed, but something is not answering. Start here:\n'
    echo "    journalctl -u lobbyhub-web -n 40 --no-pager"
    echo "    tail -40 $ROOT/storage/logs/laravel.log"
    exit 1
fi

printf '\n\033[1;32m==>\033[0m Done in %ss — now at %s\n' "$(( $(date +%s) - started_at ))" "$(as_deploy git log -1 --format='%h %s')"
