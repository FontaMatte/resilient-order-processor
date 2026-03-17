# Resilient Order Processor

A backend system designed to handle order processing with concurrency, network failures, and data consistency under pressure.

Built as a learning project to explore real-world backend engineering patterns: distributed transactions, message queues, idempotency, and failure handling.

## Tech Stack

- **PHP 8.2+** with Slim Framework (micro-framework)
- **PostgreSQL 16** — relational database with strong concurrency support
- **RabbitMQ 3** — message broker for async payment processing
- **Docker Compose** — local development orchestration

## Architecture

```
Client → [POST /orders] → App (PHP)
                            ├── Verify availability (external service, can fail)
                            ├── Reserve inventory (atomic, SELECT FOR UPDATE)
                            └── Publish to queue → [RabbitMQ] → Payment Consumer
```

## Getting Started

### Prerequisites

- Docker and Docker Compose installed
- Git

### Run the project

```bash
git clone https://github.com/yourusername/resilient-order-processor.git
cd resilient-order-processor
docker compose up --build
```

The API will be available at `http://localhost:8080`.

### Verify it works

```bash
curl http://localhost:8080/health
```

## Design Decisions

> This section documents the architectural choices made during development, including trade-offs and failure modes considered.

*Coming soon as the project evolves.*

## Learning Journey

This project was built incrementally as a backend engineering exercise. Each phase tackled a specific challenge:

| Phase | Focus | Status |
|-------|-------|--------|
| 1 | Project setup & Docker orchestration | ✅ |
| 2 | Database schema & PostgreSQL | ⬜ |
| 3 | Order creation API endpoint | ⬜ |
| 4 | External service simulation & failure handling | ⬜ |
| 5 | Atomic inventory management | ⬜ |
| 6 | Async payment processing with RabbitMQ | ⬜ |
| 7 | Idempotency & retry handling | ⬜ |
| 8 | Resilience testing & documentation | ⬜ |
