#!/usr/bin/env bash

set -Eeuo pipefail

log() {
    echo "[belgranowear-updater] $*"
}

start_group() {
    echo "::group::$*"
}

end_group() {
    echo "::endgroup::"
}

run_step() {
    local name="$1"
    shift

    start_group "$name"
    log "Running: $*"

    set +e
    "$@"
    local status=$?
    set -e

    end_group

    if [ "$status" -ne 0 ]; then
        echo "::error title=${name} failed::Command '$*' exited with status ${status}."

        return "$status"
    fi
}

count_files() {
    find "$1" -type f | wc -l | tr -d ' '
}

start_group "Prepare workspace"
log "Working directory: $(pwd)"
log "PHP version: $(php -v | head -n 1)"
log "Composer version: $(composer --version)"

if git config --global --add safe.directory /var/www 2>/tmp/belgranowear-git-config.err; then
    log "Configured /var/www as a safe Git directory"
else
    log "Could not configure Git safe.directory; continuing because the updater does not require it"
fi
end_group

run_step "Install PHP dependencies" composer install --no-interaction --prefer-dist --no-progress

start_group "Seed cache from docs"
mkdir -p storage/app
log "Docs files before seed: $(count_files docs)"
cp -rf docs/. storage/app/
log "Cached files after seed: $(count_files storage/app)"
end_group

run_step "Update availability options" php application app:update-availability-options
run_step "Update schedule" php application app:update-schedule
run_step "Update train stations map" php application app:update-train-stations-map
run_step "Update holidays list" php application app:update-holidays-list
run_step "Update checksum list" php application app:update-hash-list

start_group "Publish generated cache to docs"
log "Generated cache files: $(count_files storage/app)"
cp -rf storage/app/. docs/
log "Docs files after publish: $(count_files docs)"
end_group
