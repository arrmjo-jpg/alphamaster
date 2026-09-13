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
  openapi         Regenerate the contract, validate it, and fail on drift
  admin           Admin UI: format, lint, types, tests, build, and codegen drift
  all             Everything above, in order

Environment:
  COMPOSE                  override the compose command (default: docker compose)
  GATE_ALLOW_DESTRUCTIVE   set to 1 to let `all` run migrate-fresh outside CI
  GATE_SKIP_DB_LOCK        set to 1 to skip the one-suite-at-a-time database lock
  ADMIN_NODE_IMAGE         override the Node image the admin gate runs in
USAGE
}

step() {
    printf '\n\033[1m── %s\033[0m\n' "$1"
}

# One suite at a time against the PostgreSQL test database.
#
# `alphamaster_test` is a single database shared by every caller of this script, and
# RefreshDatabase migrates it at the start of a run. Two suites therefore destroy each
# other: the second one's migration drops tables the first is still reading, and the
# first reports failures like `relation "settings" does not exist` in tests that have
# nothing to do with settings. That has now happened twice — once when a second session
# ran the suite in a git worktree, and once when a branch was switched mid-run — and
# both times the failures looked like real defects and cost real time.
#
# So the run takes a lock and says who holds it. `mkdir` is the atomic primitive here
# because it is atomic everywhere this script runs, including Git Bash on Windows where
# `flock` does not exist.
GATE_LOCK_DIR="${TMPDIR:-/tmp}/alphamaster-gate-db.lock"
GATE_LOCK_HELD=""

acquire_db_lock() {
    local holder=""

    if mkdir "$GATE_LOCK_DIR" 2>/dev/null; then
        echo "$$" > "$GATE_LOCK_DIR/pid"
        GATE_LOCK_HELD=1
        trap release_db_lock EXIT INT TERM
        return 0
    fi

    holder="$(cat "$GATE_LOCK_DIR/pid" 2>/dev/null || echo unknown)"

    # A crashed run must not block every future one. If the recorded process is gone,
    # the lock is stale and this run takes it over rather than refusing forever.
    if [ "$holder" != unknown ] && ! kill -0 "$holder" 2>/dev/null; then
        echo "note: taking over a stale database lock left by process $holder" >&2
        rm -rf "$GATE_LOCK_DIR"
        acquire_db_lock
        return $?
    fi

    cat >&2 <<LOCKED

Another suite is already running against the PostgreSQL test database (process $holder).

They share one database, so running both would make each fail in ways that look like
defects and are not. Wait for the other run to finish, or set GATE_SKIP_DB_LOCK=1 if
you are certain nothing else is running.
LOCKED

    exit 1
}

release_db_lock() {
    if [ -n "$GATE_LOCK_HELD" ]; then
        rm -rf "$GATE_LOCK_DIR"
        GATE_LOCK_HELD=""
    fi
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

    if [ -z "${GATE_SKIP_DB_LOCK:-}" ]; then
        acquire_db_lock
    fi

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

    # Drops the database it runs against, so it waits for the same lock the suite
    # takes rather than dropping one out from under a run in progress.
    if [ -z "${GATE_SKIP_DB_LOCK:-}" ]; then
        acquire_db_lock
    fi

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
# The image this scan runs in, resolved so that a stale answer is not a broken gate.
#
# `compose images -q` reports the image the *running container* was created from, and
# that id stops existing the moment the image is rebuilt or pruned while the container
# keeps running — compose then reports `<none>` for the repository and an id `docker
# run` cannot resolve. The scan died with "No such image" on a developer machine in
# exactly that state, and the gate was skipped rather than fixed, which is how a
# secret reached CI that this scan exists to catch.
#
# So the tag is tried first, since it survives a rebuild, and the id is the fallback.
# A state where neither resolves says what to run rather than failing obscurely.
resolve_backend_image() {
    local tag candidate

    tag="${ALPHAMASTER_IMAGE_NAMESPACE:-alphamaster}/backend:${ALPHAMASTER_IMAGE_TAG:-latest}"

    if docker image inspect "$tag" >/dev/null 2>&1; then
        echo "$tag"

        return 0
    fi

    candidate="$($COMPOSE images -q "$BACKEND_SERVICE" 2>/dev/null | head -1)"

    if [ -n "$candidate" ] && docker image inspect "$candidate" >/dev/null 2>&1; then
        echo "$candidate"

        return 0
    fi

    return 1
}

cmd_secrets() {
    step "Secret scan"
    local image

    if ! image="$(resolve_backend_image)"; then
        cat >&2 <<'MISSING'
No usable backend image to run the secret scan in.

The tag is missing and the id compose reports does not resolve, which usually means
the image was rebuilt or pruned while the container kept running. Rebuild it:

    docker compose build backend

This gate is not optional: skipping it is how a credential reaches CI.
MISSING

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

# Four steps, each gating the next: generate, validate, compare, fail on difference.
#
# The generated document is committed. A specification nobody compares against the code
# drifts from it, which is the whole reason ADR 0010 chose an inferred contract over a
# maintained one — so the comparison is the gate, not the generation.
#
# Validation runs Redocly and Spectral at full strength. ADR 0029 item 19 records an
# upstream defect in the generator that produces an invalid keyword; it is removed by a
# document transformer in the application, not by silencing either validator. The suite
# carries a control that plants an invalid document and requires both to reject it, so a
# validator that stopped validating is visible rather than comfortable.
#
# Node runs on the host rather than in the backend container: that image is PHP-only by
# ADR 0005 (one process per container), and adding a Node toolchain to it to lint a JSON
# file would be the wrong trade.
cmd_openapi() {
    step "OpenAPI contract"
    require_stack

    # The validators live in backend/package.json, which is the repository's only npm
    # manifest. Invoked through their own bin paths rather than npx: npx resolves by
    # package name, and the binary `redocly` comes from the package `@redocly/cli`, so
    # `npx --no-install redocly` looks for a package that does not exist and fails.
    local bin="backend/node_modules/.bin"

    if [ ! -x "$bin/redocly" ] || [ ! -x "$bin/spectral" ]; then
        echo "The OpenAPI validators are not installed. Run: npm ci --prefix backend" >&2
        exit 1
    fi

    echo "generating"
    in_backend "$BACKEND_SERVICE" php artisan scramble:export

    # Redocly is the blocking validator. It understands OpenAPI 3.1, including the
    # `prefixItems` tuples this document contains, and its `recommended` ruleset runs
    # with nothing disabled. Errors fail the gate; style warnings do not.
    echo "validating (redocly) — blocking"
    "$bin/redocly" lint --config redocly.yaml

    # Spectral runs as a diagnostic and does not fail the gate at this stage.
    #
    # This is not a suppression: the `array-items` rule stays enabled and still
    # reports. ADR 0029 item 19 records why its findings cannot be acted on — the rule
    # predates JSON Schema 2020-12 and requires a sibling `items` for every
    # `type: array`, so it flags valid 3.1 tuples that carry `prefixItems` instead.
    # That is a limitation of the rule, not a defect in this document, and the record
    # is explicit that the document must not be rewritten to satisfy it.
    #
    # Making it blocking would leave a permanently red gate whose only remedy is to
    # disable the rule or corrupt the contract. Reporting it keeps the finding visible
    # so that an upstream rule fix can simply flip this to blocking.
    echo "validating (spectral) — diagnostic, non-blocking (ADR 0029 item 19)"
    "$bin/spectral" lint backend/openapi.json --ruleset .spectral.yaml || true

    # The control, before the result is trusted. A validator that has stopped
    # validating reports a clean document forever, and this project has been burned by
    # that twice: an architecture rule guarding a namespace nothing imported, and a
    # secret scan reporting clean because it had stopped looking. The secret scan now
    # proves its own detectors; so does this.
    #
    # The planted node is the exact defect ADR 0029 item 19 describes — an
    # `additionalItems` beside a `prefixItems` — so the control proves the validator
    # catches the specific thing the transformer exists to remove, not merely that it
    # can reject some malformed file.
    # Written in Node rather than any other scripting language: the validators are Node
    # tools, so a runtime that is guaranteed present wherever this gate can run at all
    # is the one with no portability question attached.
    echo "proving the validator (positive controls)"
    control_keyword="$(mktemp -t openapi-control-keyword-XXXXXX.json)"
    control_struct="$(mktemp -t openapi-control-struct-XXXXXX.json)"
    trap 'rm -f "$control_keyword" "$control_struct"' RETURN

    CONTROL_KEYWORD="$control_keyword" CONTROL_STRUCT="$control_struct" node <<'CONTROL'
const fs = require('fs');
const read = () => JSON.parse(fs.readFileSync('backend/openapi.json', 'utf8'));

// Control 1: the exact defect ADR 0029 item 19 describes — `additionalItems` beside
// `prefixItems`. Proves the validator catches the specific thing the transformer
// removes, not merely that it can reject some malformed file.
const keyword = read();
keyword.components = keyword.components || {};
keyword.components.schemas = keyword.components.schemas || {};
keyword.components.schemas.GateControlKeyword = {
    type: 'array',
    prefixItems: [{ type: 'string' }],
    additionalItems: false,
    minItems: 1,
    maxItems: 1,
};
fs.writeFileSync(process.env.CONTROL_KEYWORD, JSON.stringify(keyword));

// Control 2: an unrelated structural error. A validator tuned to one keyword and blind
// to everything else would pass control 1 and still be worthless.
const struct = read();
struct.paths = struct.paths || {};
struct.paths['/gate-control'] = { get: { responses: { '200': { description: 42 } } } };
fs.writeFileSync(process.env.CONTROL_STRUCT, JSON.stringify(struct));
CONTROL

    if "$bin/redocly" lint "$control_keyword" --config redocly.yaml >/dev/null 2>&1; then
        echo >&2
        echo "UNPROVEN: the validator accepted a document carrying the invalid keyword." >&2
        exit 1
    fi
    echo "  planted additionalItems rejected: DETECTED"

    if "$bin/redocly" lint "$control_struct" --config redocly.yaml >/dev/null 2>&1; then
        echo >&2
        echo "UNPROVEN: the validator accepted a structurally invalid document." >&2
        exit 1
    fi
    echo "  planted structural error rejected: DETECTED"

    echo "checking for drift"

    # Tracked first, and not as a formality. `git diff` says nothing about an untracked
    # file, so on a document that has never been committed the comparison below would
    # report no drift and the gate would go green having compared nothing — the precise
    # shape of failure this project has twice been caught by.
    if ! git ls-files --error-unmatch backend/openapi.json >/dev/null 2>&1; then
        echo >&2
        echo "backend/openapi.json is not tracked by git, so there is nothing to" >&2
        echo "compare the regenerated document against. Commit it first." >&2
        exit 1
    fi

    if ! git diff --quiet -- backend/openapi.json; then
        echo >&2
        echo "The committed contract does not match what the code produces." >&2
        echo "Regenerate and commit backend/openapi.json:" >&2
        git --no-pager diff --stat -- backend/openapi.json >&2
        exit 1
    fi

    echo "contract validates and matches the committed document"
}

# The Admin UI gate.
#
# Node runs in a container for the same reason PHP does: the version is the one the
# image ships with, not whatever the developer or the runner happens to have. The
# admin Dockerfile pins node:22-alpine and so does this, so a green result here is a
# statement about the environment the bundle is actually built in.
#
# The backend stack is not required. Nothing below talks to the API — these are
# static checks and a build.
ADMIN_NODE_IMAGE="${ADMIN_NODE_IMAGE:-node:22-alpine}"

# Docker wants a host path. On Git Bash a POSIX path reaches the daemon as
# `C:/Program Files/Git/...` after MSYS rewrites it, which fails as a mount source;
# cygpath is what converts it back.
admin_mount() {
    if command -v cygpath >/dev/null 2>&1; then
        cygpath -m "$PWD"
    else
        printf '%s' "$PWD"
    fi
}

# The whole repository is mounted, not just admin/. The code generator reads
# ../backend/openapi.json — the contract is the backend's, and copying it into the
# frontend to make a narrower mount possible would create a second copy to keep in
# step. The container's working directory is admin/, so every command still runs
# where its package.json is.
admin_node() {
    MSYS_NO_PATHCONV=1 docker run --rm \
        -v "$(admin_mount):/repo" \
        -w /repo/admin \
        "$ADMIN_NODE_IMAGE" \
        sh -c "$1"
}

cmd_admin() {
    step "Admin UI"

    if [ ! -d admin/node_modules ]; then
        echo "installing dependencies from the lockfile"
        admin_node "npm ci --no-audit --no-fund"
    fi

    echo "formatting"
    admin_node "npm run format"

    echo "linting"
    admin_node "npm run lint"

    echo "types"
    admin_node "npm run typecheck"

    echo "tests"
    admin_node "npm test"

    echo "production build"
    admin_node "npm run build"

    cmd_admin_codegen
}

# The generated API client must match the contract it was generated from.
#
# The types in admin/src/api/generated are committed, because the build and the
# editor both need them and neither should have to run a generator first. That makes
# them capable of going stale the moment backend/openapi.json changes — a renamed
# field would keep typechecking against the old name and fail at runtime, in the
# browser, as an undefined. Regenerating and comparing is what closes that.
cmd_admin_codegen() {
    step "Admin API client"

    local generated="admin/src/api/generated"
    # Script scope with an EXIT trap, not `local` with a RETURN trap: bash runs a
    # RETURN trap again when the calling function returns, and by then the local is
    # gone, so `set -u` aborts the gate after it has already passed.
    ADMIN_CONTRACT_BACKUP="$(mktemp -t openapi-drift-control-XXXXXX.json)"
    trap 'rm -f "${ADMIN_CONTRACT_BACKUP:-}"' EXIT

    # Tracked first. `git diff` is silent about an untracked file, so on output that
    # has never been committed the comparison below would report no drift having
    # compared nothing — the same shape of failure the OpenAPI gate guards against.
    if ! git ls-files --error-unmatch "$generated" >/dev/null 2>&1; then
        echo >&2
        echo "$generated is not tracked by git, so there is nothing to compare" >&2
        echo "the regenerated client against. Commit it first." >&2
        exit 1
    fi

    echo "regenerating from backend/openapi.json"
    admin_node "npm run api:generate"

    if ! git diff --quiet -- "$generated"; then
        echo >&2
        echo "The committed API client does not match the contract." >&2
        echo "Regenerate and commit it:  npm --prefix admin run api:generate" >&2
        git --no-pager diff --stat -- "$generated" >&2
        exit 1
    fi

    echo "client matches the committed contract"

    # The control, before the result is trusted. A generator that has stopped writing
    # output, or a diff that has stopped looking, reports a clean tree forever.
    #
    # A schema is added to a copy of the contract and the client is regenerated from
    # it; the output must then differ. This proves the whole path — generator reads
    # the contract, writes the files, git sees the change — rather than any one link.
    echo "proving the check (positive control)"
    cp backend/openapi.json "$ADMIN_CONTRACT_BACKUP"
    node -e '
        const fs = require("fs");
        const doc = JSON.parse(fs.readFileSync("backend/openapi.json", "utf8"));
        doc.components = doc.components || {};
        doc.components.schemas = doc.components.schemas || {};
        doc.components.schemas.GateControlDriftProbe = {
            type: "object",
            properties: { probe: { type: "string" } },
        };
        fs.writeFileSync("backend/openapi.json", JSON.stringify(doc, null, 2) + "\n");
    '

    admin_node "npm run api:generate"

    if git diff --quiet -- "$generated"; then
        cp "$ADMIN_CONTRACT_BACKUP" backend/openapi.json
        admin_node "npm run api:generate" >/dev/null
        echo >&2
        echo "UNPROVEN: the client did not change after the contract did." >&2
        exit 1
    fi

    echo "  planted schema produced a client change: DETECTED"

    # Restore both, and verify the restoration actually took: leaving a mutated
    # contract or a mutated client behind would fail the next gate for a reason that
    # has nothing to do with the change under review.
    cp "$ADMIN_CONTRACT_BACKUP" backend/openapi.json
    admin_node "npm run api:generate"

    if ! git diff --quiet -- backend/openapi.json "$generated"; then
        echo >&2
        echo "The control did not clean up after itself; the working tree is dirty." >&2
        git --no-pager diff --stat -- backend/openapi.json "$generated" >&2
        exit 1
    fi

    echo "  contract and client restored"
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
    cmd_openapi
    cmd_admin

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
    openapi)       cmd_openapi ;;
    compose-version) cmd_compose_version ;;
    admin)         cmd_admin ;;
    all)           shift; cmd_all "${1:-}" ;;
    -h|--help|help|"") usage ;;
    *)             echo "Unknown command: $1" >&2; echo >&2; usage >&2; exit 1 ;;
esac
