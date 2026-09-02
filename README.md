# alphamaster — Enterprise Laravel Foundation Platform

A modular, high-performance Laravel 13 API foundation built on PostgreSQL 17, Redis 7, and Docker.

---

## Quick Start (Docker)

Ensure Docker Desktop is running, then manage the platform using `make`:

```bash
# Start all containers in the background
make up

# View container status and health
make ps

# Run tests
make test

# View aggregated logs
make logs

# Stop all containers
make down
```

---

## Service Architecture

| Service | Technology | Port (Host) | Description |
| :--- | :--- | :--- | :--- |
| `nginx` | Nginx Alpine | `80` | Reverse proxy forwarding HTTP requests to PHP-FPM |
| `backend` | PHP 8.4-FPM | `Internal: 9000` | Laravel 13 Core & Modules API |
| `horizon` | Laravel Horizon | `Internal` | Sole queue supervisor daemon for Redis queues |
| `scheduler` | PHP CLI | `Internal` | Background task scheduler (`schedule:work`) |
| `postgres` | PostgreSQL 17 Alpine | `127.0.0.1:5432` | Primary relational database (loopback bound) |
| `redis` | Redis 7 Alpine | `127.0.0.1:6379` | Cache broker and queue store (loopback bound) |

---

## GitHub Flow & Contribution Guidelines

To maintain code quality and production stability across all phases:

1. **Protected `main` Branch**: Direct pushes to `main` are prohibited for all future implementation phases.
2. **Feature Branches**: Every task or phase must be developed on a dedicated branch branched off `main`:
   ```bash
   git checkout -b feature/phase-2-core-user-auth
   ```
3. **Automated Verification**: Before opening a PR, ensure all container healthchecks, unit/feature tests (`make test`), and lint checks pass.
4. **Pull Requests**: Submit changes via Pull Request with a clear summary of changes, test coverage, and security validations.

---

## Documentation

- [Architecture Decision Records (ADRs)](docs/adr/README.md)
