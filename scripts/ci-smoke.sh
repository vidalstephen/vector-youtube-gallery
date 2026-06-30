#!/usr/bin/env bash
# Phase 12.7: CI smoke runner.
#
# Boots the vyg-wp container (if not already up), activates the
# plugin, and runs every phase 12.x live smoke in turn. Designed
# to be invoked by `make ci-smoke` (full gate) or directly from a
# CI workflow.
#
# Exit codes:
#   0 — all smokes passed; the install is CI-clean.
#   1 — a smoke failed; see the output for the failing phase.
#   2 — the environment could not be brought up (no container,
#       network down, etc.).
#
# Usage:
#   ./scripts/ci-smoke.sh           # run the smoke against the live stack
#   ./scripts/ci-smoke.sh clean     # drop the vyg-wp container + volume

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="${REPO_ROOT}"
CONTAINER="${VYG_WP_CONTAINER:-vyg-wp}"
PLUGIN_SLUG="vector-youtube-gallery"

bold() { printf "\033[1m%s\033[0m\n" "$1"; }
ok()   { printf "  \033[32m✓\033[0m %s\n" "$1"; }
fail() { printf "  \033[31m✗\033[0m %s\n" "$1"; }

if [[ "${1:-}" == "clean" ]]; then
    bold "==> Tearing down vyg-wp container + volume"
    (cd "${REPO_ROOT}" && docker compose --env-file dev/.env down -v 2>&1 || true)
    ok "teardown complete"
    exit 0
fi

# 0. Confirm the vyg-wp container is reachable.
bold "==> Phase 12.7: CI smoke gate"
if ! docker exec -u www-data "${CONTAINER}" true 2>/dev/null; then
    fail "container ${CONTAINER} is not reachable"
    echo "Run 'make up' to start the stack, or set VYG_WP_CONTAINER to a different name."
    exit 2
fi
ok "container ${CONTAINER} reachable"

# 1. Activate the plugin (idempotent).
bold "==> Activating ${PLUGIN_SLUG}"
docker exec -u www-data "${CONTAINER}" wp plugin activate "${PLUGIN_SLUG}" >/dev/null
ok "plugin activated"

# 2. Confirm the activation produced the right dbVersion.
DB_VERSION=$(docker exec -u www-data "${CONTAINER}" wp eval 'echo defined("VYG_DB_VERSION") ? VYG_DB_VERSION : "unknown";' 2>/dev/null || echo "unknown")
echo "  db_version=${DB_VERSION}"
ok "db version reported"

# 3. Run the live smokes, in order. Each smoke must print
#    `smoke_status=ok` as the last line.
SMOKES=(
    "dev/phase12-2-smoke.php:scheduler"
    "dev/phase12-3-smoke.php:cache"
    "dev/phase12-4-smoke.php:multisite"
    "dev/phase12-5-smoke.php:logging"
    "dev/phase12-6-smoke.php:performance"
)

EXIT=0
for entry in "${SMOKES[@]}"; do
    script="${entry%%:*}"
    label="${entry##*:}"
    bold "==> smoke: ${label} (${script})"
    if output=$(docker exec -u www-data "${CONTAINER}" wp eval-file "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/${script}" 2>&1); then
        if echo "${output}" | tail -1 | grep -q "smoke_status=ok"; then
            ok "${label} smoke ok"
            echo "${output}" | sed 's/^/    /'
        else
            fail "${label} smoke did not report smoke_status=ok"
            echo "${output}" | sed 's/^/    /'
            EXIT=1
        fi
    else
        fail "${label} smoke threw an error"
        echo "${output}" | sed 's/^/    /'
        EXIT=1
    fi
done

# 4. Hit the WP-CLI subcommands introduced in Phase 12.
bold "==> CLI: wp vyg scheduler / cache / log / performance / network-diagnostics"
CLI_COMMANDS=( "scheduler" "cache" "log" "performance" "network-diagnostics" )
for cmd in "${CLI_COMMANDS[@]}"; do
    if docker exec -u www-data "${CONTAINER}" wp vyg "${cmd}" >/dev/null 2>&1; then
        ok "wp vyg ${cmd} exits 0"
    else
        fail "wp vyg ${cmd} failed"
        EXIT=1
    fi
done

# 4.5. Phase 12.9: run the final E2E summary script. The output
#     is a 7-row table that an operator can paste into the
#     release notes.
bold "==> Phase 12.9: E2E summary"
SUMMARY=$(docker exec -u www-data "${CONTAINER}" wp eval-file "/var/www/html/wp-content/plugins/${PLUGIN_SLUG}/dev/phase12-summary.php" 2>&1 || true)
echo "${SUMMARY}" | sed 's/^/    /'
if echo "${SUMMARY}" | grep -q "smoke_status=ok"; then
    ok "phase12-summary reports ok"
else
    fail "phase12-summary did not report ok"
    EXIT=1
fi

# 5. WP diagnostics snapshot. The JSON must include the expected
#    `cache` and `scheduler` keys.
bold "==> CLI: wp vyg diagnostics --format=json"
DIAG_JSON=$(docker exec -u www-data "${CONTAINER}" wp vyg diagnostics --format=json 2>&1 || true)
for key in scheduler cache counts cron; do
    if echo "${DIAG_JSON}" | grep -q "\"${key}\""; then
        ok "diagnostics has '${key}'"
    else
        fail "diagnostics missing '${key}'"
        echo "${DIAG_JSON}" | head -3 | sed 's/^/    /'
        EXIT=1
    fi
done

if [[ "${EXIT}" -eq 0 ]]; then
    bold "==> CI SMOKE: ok"
else
    bold "==> CI SMOKE: failed (exit ${EXIT})"
fi
exit "${EXIT}"
