.PHONY: up down restart build logs ps gate test test-sqlite shell artisan migrate seed

# Start all services in the background
up:
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

# Run database migrations
migrate:
	docker compose exec backend php artisan migrate

# Seed database
seed:
	docker compose exec backend php artisan db:seed
