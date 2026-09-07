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
  stan            Static analysis (Larastan, level 5)
  test-pgsql      Full suite on PostgreSQL, asserting the engine really was PostgreSQL
  test-sqlite     Full suite on SQLite, asserting the engine really was SQLite
  migrate-fresh   migrate:fresh --seed (DESTRUCTIVE: drops the target database)
  compose-version Docker Compose meets the minimum the composition requires
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

# The composition uses the `!override` merge tag, which Docker Compose understands
# from 2.24. An older client does not ignore the tag — it fails to parse the file it
# appears in, and every command here loads the development override automatically, so
# the failure is total and the message is a YAML parse error that names nothing useful.
#
# Checked explicitly so the requirement is a stated precondition rather than a latent
# assumption discovered by whoever upgrades last.
COMPOSE_MINIMUM_MAJOR=2
COMPOSE_MINIMUM_MINOR=24

cmd_compose_version() {
    step "Docker Compose version"

    local reported major minor
    reported="$($COMPOSE version --short 2>/dev/null || true)"

    if [ -z "$reported" ]; then
        echo "Could not determine the Docker Compose version from: $COMPOSE version --short" >&2
        echo "This project requires Docker Compose ${COMPOSE_MINIMUM_MAJOR}.${COMPOSE_MINIMUM_MINOR} or newer." >&2
        exit 1
    fi

    # Leading `v` where the client prints one, then the first two components.
    reported="${reported#v}"
    major="${reported%%.*}"
    minor="${reported#*.}"
    minor="${minor%%.*}"

    case "$major$minor" in
        *[!0-9]*|'')
            echo "Unrecognised Docker Compose version string: $reported" >&2
            echo "This project requires Docker Compose ${COMPOSE_MINIMUM_MAJOR}.${COMPOSE_MINIMUM_MINOR} or newer." >&2
            exit 1
            ;;
    esac

    if [ "$major" -lt "$COMPOSE_MINIMUM_MAJOR" ] ||
       { [ "$major" -eq "$COMPOSE_MINIMUM_MAJOR" ] && [ "$minor" -lt "$COMPOSE_MINIMUM_MINOR" ]; }; then
        echo >&2
        echo "Docker Compose $reported is too old for this composition." >&2
        echo >&2
        echo "Required: ${COMPOSE_MINIMUM_MAJOR}.${COMPOSE_MINIMUM_MINOR} or newer." >&2
        echo "Reason:   docker-compose.override.yml uses the \`!override\` merge tag," >&2
        echo "          which Compose understands from ${COMPOSE_MINIMUM_MAJOR}.${COMPOSE_MINIMUM_MINOR}. Without it the" >&2
        echo "          development proxy would publish both 80 and 8080." >&2
        echo "Fix:      update Docker Desktop, or install a newer docker-compose-plugin." >&2
        exit 1
    fi

    echo "docker compose $reported meets the required ${COMPOSE_MINIMUM_MAJOR}.${COMPOSE_MINIMUM_MINOR} minimum"
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

# Level 5 rather than the level 8 ADR 0021 named. Level 8 reports 96 errors against
# this codebase and level 5 reported 31, all of which were fixed rather than
# baselined — a gate that runs is worth more than one that is aspired to, and a
# baseline would have turned 96 known defects into a green check. The remaining
# level 8 findings stay recorded in ADR 0029 item 5.
cmd_stan() {
    step "Static analysis"
    require_stack
    in_backend "$BACKEND_SERVICE" ./vendor/bin/phpstan analyse --memory-limit=1G --no-progress
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

    # The scan asks git whether an accepted path is really ignored. Inside the
    # container the mounted tree belongs to a different user than the one running
    # git, which git refuses to touch ("dubious ownership") — so the question came
    # back unanswered and a gitignored .env was reported as a committed secret.
    # These variables declare the exception through the environment rather than by
    # writing a config file into a read-only mount.
    MSYS_NO_PATHCONV=1 docker run --rm \
        -v "${host_path}":/repo:ro \
        -w /repo \
        -e GIT_CONFIG_COUNT=1 \
        -e GIT_CONFIG_KEY_0=safe.directory \
        -e GIT_CONFIG_VALUE_0=/repo \
        "$image" php scripts/security/secrets-scan.php
}

cmd_all() {
    cmd_pint
    cmd_arch
    cmd_stan
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
    stan)          cmd_stan ;;
    test-pgsql)    cmd_test_pgsql ;;
    test-sqlite)   cmd_test_sqlite ;;
    migrate-fresh) cmd_migrate_fresh ;;
    diff)          shift; cmd_diff "${1:-}" ;;
    secrets)       cmd_secrets ;;
    compose-version) cmd_compose_version ;;
    all)           shift; cmd_all "${1:-}" ;;
    -h|--help|help|"") usage ;;
    *)             echo "Unknown command: $1" >&2; echo >&2; usage >&2; exit 1 ;;
esac
