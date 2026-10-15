#!/usr/bin/env bash
# Deploys one commit of main to the VPS: the routine deploy from docs/deploy-vps.md §4, run by
# .github/workflows/deploy.yml over SSH once the Quality Gate has passed on main. It also works by
# hand: `docker/production/deploy.sh <sha>`.
#
# The deploy key in GitHub is pinned to this script with a forced command in authorized_keys
# (runbook §4), so the commit arrives in $SSH_ORIGINAL_COMMAND — untrusted input, accepted only
# as a full SHA that is already on origin/main.
#
# Steps: wait for running pipeline steps to finish, back up, fast-forward to the commit, build,
# restart, check /up. If anything after the fast-forward fails, it goes back to the previous
# commit and restarts on that. Migrations are NOT reversed: restore the backup by hand if one
# changed data.
#
# Everything printed here ends up in the GitHub Actions log, which is public for this repo, so
# never print .env values or `set -x`.
set -euo pipefail

cd "$(dirname "$0")/../.."

# How long to wait for running pipeline steps (stopping the worker kills one after 2 minutes).
DEPLOY_WAIT_MINUTES="${DEPLOY_WAIT_MINUTES:-30}"
# Inside the checkout (var/ is git-ignored), not /tmp: in /tmp a file left by another user —
# e.g. root running this by hand — can't be opened by `deploy`, and anyone could plant one to
# block deploys.
LOCK_FILE="${LOCK_FILE:-var/deploy.lock}"

log() { printf '[deploy %s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

running_steps() {
    docker compose exec -T db sh -c 'mysql -N -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
        -e "SELECT COUNT(*) FROM jobs WHERE reserved_at IS NOT NULL" 2>/dev/null'
}

wait_for_running_steps() {
    local deadline=$((SECONDS + DEPLOY_WAIT_MINUTES * 60)) count
    while true; do
        if ! count="$(running_steps)"; then
            log "Couldn't check for running pipeline steps (is db up?); not deploying."
            return 1
        fi
        [ "$count" = "0" ] && return 0
        if [ "$SECONDS" -ge "$deadline" ]; then
            log "$count pipeline step(s) still running after ${DEPLOY_WAIT_MINUTES} minutes; not deploying. Re-run the deploy once they finish."
            return 1
        fi
        log "$count pipeline step(s) running; waiting…"
        sleep 30
    done
}

# Chained with && rather than relying on `set -e`, which bash ignores inside a function called
# from an `if` — as both calls below are.
build_and_restart() {
    docker compose build &&
        docker compose run --rm --no-deps app composer install --no-dev --optimize-autoloader --no-interaction &&
        docker compose run --rm --no-deps app sh -c "npm ci && npm run build" &&
        # The source is bind-mounted, so a code-only change doesn't recreate the containers on
        # its own. Migrations run as `app` and `worker` start.
        docker compose up -d --force-recreate app worker scheduler test-runner &&
        docker compose up -d
}

healthy() {
    local url
    url="$(grep -E '^APP_URL=' .env | tail -1 | cut -d= -f2- | tr -d "\"'")/up"
    for _ in $(seq 1 18); do
        if curl -fsS -o /dev/null --max-time 5 "$url"; then
            log "Healthy: $url"
            return 0
        fi
        sleep 5
    done
    log "Health check failed: $url"
    return 1
}

main() {
    local target="${1:-${SSH_ORIGINAL_COMMAND:-}}" previous

    if ! [[ "$target" =~ ^[0-9a-f]{40}$ ]]; then
        log "Expected a full commit SHA, got '${target:0:80}'."
        exit 64
    fi

    # One deploy at a time; a second one waits for the first.
    mkdir -p "$(dirname "$LOCK_FILE")"
    if ! exec 9>>"$LOCK_FILE"; then
        log "Can't open the lock file $LOCK_FILE as $(id -un); fix its owner (chown deploy:deploy)."
        exit 1
    fi
    flock 9

    git fetch --quiet origin main
    if ! git merge-base --is-ancestor "$target" origin/main; then
        log "$target is not on origin/main; refusing."
        exit 65
    fi

    previous="$(git rev-parse HEAD)"
    if git merge-base --is-ancestor "$target" "$previous"; then
        log "Already at $previous, which includes $target. Nothing to do."
        exit 0
    fi
    if [ "$(git symbolic-ref --short HEAD)" != "main" ] || ! git diff --quiet HEAD; then
        log "The server checkout isn't a clean main; fix it by hand (git status) before deploying."
        exit 1
    fi

    wait_for_running_steps
    log "Backing up"
    if ! ./docker/production/backup.sh; then
        log "Backup failed; not deploying. Nothing was changed."
        exit 1
    fi

    log "Deploying $previous -> $target"
    if git merge --quiet --ff-only "$target" && build_and_restart && healthy; then
        log "Deployed $target"
        exit 0
    fi

    log "Deploy failed; rolling back to $previous (migrations are not reversed, see the runbook)."
    git reset --quiet --hard "$previous"
    if build_and_restart && healthy; then
        log "Rolled back to $previous"
    else
        log "ROLLBACK FAILED: the app may be down. Follow the runbook's manual rollback."
    fi
    exit 1
}

# Everything is in functions and this is the last line: bash reads a script as it runs, and the
# deploy rewrites this file (git merge) halfway through, so the whole script must be parsed first.
main "$@"; exit
