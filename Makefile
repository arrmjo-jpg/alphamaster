.PHONY: compose-version up down restart build logs ps gate admin admin-dev test test-sqlite shell artisan migrate seed adminer adminer-down

# Start all services in the background.
#
# The version check runs first: the composition needs Compose >= 2.24, and an older
# client fails with a YAML parse error that explains nothing.
up:
	@bash scripts/gate.sh compose-version
	docker compose up -d

# Stop and remove containers and networks
down:
	docker compose down

# Restart all services
restart:
	docker compose restart

# Build or rebuild container images
build:
	docker compose build

# View aggregated logs
logs:
	docker compose logs -f

# List container status and health
ps:
	docker compose ps

# Run the full quality gate — the same commands CI runs
gate:
	bash scripts/gate.sh all

# The Admin UI gate: format, lint, types, tests, production build, codegen drift
admin:
	bash scripts/gate.sh admin

# The Vite development server, on the host, proxying /api to the running stack so
# the browser still sees a single origin (ADR 0042)
admin-dev:
	npm --prefix admin run dev

# Run tests in the backend container, on the engine ADR 0027 makes authoritative
test:
	bash scripts/gate.sh test-pgsql

# Run the suite on the secondary engine
test-sqlite:
	bash scripts/gate.sh test-sqlite

# Open an interactive bash shell in the backend container
shell:
	docker compose exec backend sh

# Run an artisan command (usage: make artisan cmd="route:list")
artisan:
	docker compose exec backend php artisan $(cmd)

# Start the optional Adminer database web client on http://localhost:8080
adminer:
	docker compose --profile tools up -d adminer

# Stop and remove the Adminer container
adminer-down:
	docker compose --profile tools rm -sf adminer

# Run database migrations
migrate:
	docker compose exec backend php artisan migrate

# Seed database
seed:
	docker compose exec backend php artisan db:seed
