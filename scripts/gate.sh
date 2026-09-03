#!/usr/bin/env bash
#
# The quality gate, defined once.
#
# Every gate this project has runs through this script, so a developer and the CI
# workflow issue the same command and get the same statement. Before this existed
# the gate was a list of commands typed by hand at review time, which meant CI
# could only ever be a second copy of it — and a copy drifts. If a gate needs to
# change, it changes here and both callers change with it.
#
# All work happens inside the project's own image (docker/backend/Dockerfile), not
# on whatever PHP the host or the runner happens to provide. A green result is
# therefore a statement about the environment this project actually ships.
#
# Usage: scripts/gate.sh <command> [args]
#
set -euo pipefail

COMPOSE="${COMPOSE:-docker compose}"
BACKEND_SERVICE="backend"

# `all` refuses to run the destructive migration outside CI unless asked, because
# migrate:fresh drops the developer's local database.
ALLOW_DESTRUCTIVE="${GATE_ALLOW_DESTRUCTIVE:-${CI:-}}"

usage() {
    cat <<'USAGE'
scripts/gate.sh <command>

  pint            Code style check (no files rewritten)
  arch            Architecture rules only
  test-pgsql      Full suite on PostgreSQL, asserting the engine really was PostgreSQL
  test-sqlite     Full suite on SQLite, asserting the engine really was SQLite
  migrate-fresh   migrate:fresh --seed (DESTRUCTIVE: drops the target database)
  diff [base]     Whitespace errors; with a base ref, checks that range instead of the worktree
  secrets         Secret scan over the whole repository, with its own positive controls
  all             Everything above, in order

Environment:
  COMPOSE                  override the compose command (default: docker compose)
  GATE_ALLOW_DESTRUCTIVE   set to 1 to let `all` run migrate-fresh outside CI
USAGE
}

step() {
    printf '\n\033[1m── %s\033[0m\n' "$1"
}

in_backend() {
    # shellcheck disable=SC2086
    $COMPOSE exec -T "$@"
}

require_stack() {
    if ! $COMPOSE ps --status running --services 2>/dev/null | grep -qx "$BACKEND_SERVICE"; then
        echo "The $BACKEND_SERVICE service is not running. Start it with: $COMPOSE up -d" >&2
        exit 1
    fi
}

cmd_pint() {
    step "Code style"
    require_stack
    in_backend "$BACKEND_SERVICE" ./vendor/bin/pint --test
}

cmd_arch() {
    step "Architecture rules"
    require_stack
    in_backend "$BACKEND_SERVICE" php artisan test --filter=ArchitectureTest
}

# EXPECTED_DB_DRIVER is not decoration. PHPUnit's <env> entries are not force="true",
# so a run with no database environment silently falls back to the SQLite defaults in
# phpunit.xml — and passes. A PostgreSQL job that quietly became a SQLite job would
# report the same 354 green tests while proving nothing about PostgreSQL. The suite
# carries a test that compares the live driver against this value and fails on a
# mismatch, so the fallback becomes visible instead of comfortable.
cmd_test_pgsql() {
    step "Test suite — PostgreSQL"
    require_stack
    in_backend \
        -e DB_CONNECTION=pgsql \
        -e EXPECTED_DB_DRIVER=pgsql \
        "$BACKEND_SERVICE" php artisan test
}

cmd_test_sqlite() {
    step "Test suite — SQLite"
    require_stack
    in_backend \
        -e DB_CONNECTION=sqlite \
        -e DB_DATABASE=:memory: \
        -e EXPECTED_DB_DRIVER=sqlite \
        "$BACKEND_SERVICE" php artisan test
}

cmd_migrate_fresh() {
    step "Migrations and seeders from empty"
    require_stack
    in_backend "$BACKEND_SERVICE" php artisan migrate:fresh --seed --force
}

cmd_diff() {
    step "Whitespace"
    if [ $# -gt 0 ] && [ -n "$1" ]; then
        echo "range: $1...HEAD"
        git diff --check "$1...HEAD"
    else
        echo "range: working tree and index"
        git diff --check
        git diff --cached --check
    fi
    echo "no whitespace errors"
}

# The scan runs inside the project image so it does not depend on a PHP binary
# happening to exist on the host or the runner, and mounts the repository root
# because the compose mount only exposes backend/.
cmd_secrets() {
    step "Secret scan"
    local image
    image="$($COMPOSE images -q "$BACKEND_SERVICE" 2>/dev/null | head -1)"

    if [ -z "$image" ]; then
        echo "No built $BACKEND_SERVICE image found. Run: $COMPOSE build" >&2
        exit 1
    fi

    # `pwd -W` yields a Windows-style path under Git Bash and fails elsewhere, and
    # MSYS_NO_PATHCONV stops that shell rewriting the container-side /repo into a
    # host path. Both are inert on Linux. Without them this command fails on
    # Windows with a mangled working directory — the same path-translation trap
    # that once made a secret scan report a confident, empty, wrong result.
    local host_path
    host_path="$(pwd -W 2>/dev/null || pwd)"

    MSYS_NO_PATHCONV=1 docker run --rm \
        -v "${host_path}":/repo:ro \
        -w /repo \
        "$image" php scripts/security/secrets-scan.php
}

cmd_all() {
    cmd_pint
    cmd_arch
    cmd_test_pgsql
    cmd_test_sqlite

    if [ -n "$ALLOW_DESTRUCTIVE" ]; then
        cmd_migrate_fresh
    else
        step "Migrations and seeders from empty"
        echo "skipped: migrate:fresh drops the local database."
        echo "run scripts/gate.sh migrate-fresh, or set GATE_ALLOW_DESTRUCTIVE=1, to include it."
    fi

    cmd_diff "${1:-}"
    cmd_secrets

    printf '\n\033[1mgate: all checks passed\033[0m\n'
}

case "${1:-}" in
    pint)          cmd_pint ;;
    arch)          cmd_arch ;;
    test-pgsql)    cmd_test_pgsql ;;
    test-sqlite)   cmd_test_sqlite ;;
    migrate-fresh) cmd_migrate_fresh ;;
    diff)          shift; cmd_diff "${1:-}" ;;
    secrets)       cmd_secrets ;;
    all)           shift; cmd_all "${1:-}" ;;
    -h|--help|help|"") usage ;;
    *)             echo "Unknown command: $1" >&2; echo >&2; usage >&2; exit 1 ;;
esac
