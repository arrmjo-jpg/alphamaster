.PHONY: up down restart build logs ps test shell artisan migrate seed

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

# Run tests in the backend container
test:
	docker compose exec backend php artisan test

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
