# Resilient Order Processor

A backend system designed to handle order processing with concurrency, network failures, and data consistency under pressure. The goal is not to build an API that works locally with one user at a time — it's to design a system that behaves correctly under difficult conditions.

## Tech Stack

- **PHP 8.2+** with Slim Framework (micro-framework, no ORM magic)
- **PostgreSQL 16** — relational database with strong concurrency support (MVCC, row-level locking)
- **RabbitMQ 3** — message broker for async payment processing
- **Docker Compose** — local development orchestration (3 services)

## Architecture

```
                                    ┌─────────────────────────────────┐
                                    │         API (PHP/Slim)          │
                                    │                                 │
POST /orders ──────────────────────►│  1. Validate input              │
                                    │  2. Check external availability │──── Simulated external
                                    │     (with retry + backoff)      │     service (30% failure)
                                    │  3. Reserve stock (FOR UPDATE)  │
                                    │  4. Create order                │
                                    │  5. Write to outbox             │──── Same DB transaction
                                    │  6. Return 201                  │
                                    └──────────────┬──────────────────┘
                                                   │
                                    ┌──────────────▼──────────────────┐
                                    │   PostgreSQL (orders, outbox)   │
                                    └──────────────┬──────────────────┘
                                                   │
                                    ┌──────────────▼──────────────────┐
                                    │     Outbox Relay (polling)      │
                                    │     Reads unprocessed messages  │
                                    │     Publishes to RabbitMQ       │
                                    └──────────────┬──────────────────┘
                                                   │
                                    ┌──────────────▼──────────────────┐
                                    │   RabbitMQ (order_payments)     │
                                    └──────────────┬──────────────────┘
                                                   │
                                    ┌──────────────▼──────────────────┐
                                    │   Payment Worker (consumer)     │
                                    │   Processes payment             │
                                    │   Updates order status          │
                                    │   completed / failed            │
                                    └─────────────────────────────────┘
```

## Order State Machine

```
pending ──► confirmed ──► processing ──► completed
                │                            │
                └──────────► failed ◄────────┘
```

- **pending** — order created, not yet verified
- **confirmed** — availability verified, stock reserved, awaiting payment
- **completed** — payment processed successfully
- **failed** — payment failed or external service unreachable

## Getting Started

### Prerequisites

- Docker and Docker Compose
- Git

### Run the project

```bash
git clone https://github.com/yourusername/resilient-order-processor.git
cd resilient-order-processor

# Start all services
docker compose up --build

# In a second terminal: install PHP dependencies
docker compose exec app composer install

# Run the database migration
docker compose exec -T db psql -U app -d orders < database/001_initial_schema.sql

# Start the outbox relay (third terminal)
docker compose exec app php bin/relay.php

# Start the payment worker (fourth terminal)
docker compose exec app php bin/worker.php
```

### Verify it works

```bash
# Health check (should show database: ok, rabbitmq: ok)
curl http://localhost:8080/health

# List products
curl http://localhost:8080/products

# Create an order
curl -X POST http://localhost:8080/orders \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: my-unique-key-001" \
  -d '{"product_id": 1, "quantity": 2}'
```

### API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | System health check (DB + RabbitMQ) |
| GET | `/products` | List all products with stock |
| POST | `/orders` | Create a new order |

#### POST /orders

**Request body:**
```json
{
    "product_id": 1,
    "quantity": 2
}
```

**Headers (optional):**
- `Idempotency-Key: <unique-string>` — prevents duplicate orders on retry

**Responses:**
- `201` — order created successfully
- `200` — duplicate request detected (idempotent response)
- `400` — malformed request body
- `404` — product not found
- `409` — insufficient stock or product unavailable
- `422` — missing or invalid fields
- `503` — external availability service unreachable

## Design Decisions

### 1. Pessimistic locking with SELECT FOR UPDATE

**Problem:** Two concurrent requests could read the same stock value and both succeed, causing overselling.

**Decision:** Use `SELECT ... FOR UPDATE` inside a transaction. This acquires a row-level lock: the second transaction waits until the first commits or rolls back, then sees the updated stock.

**Trade-off:** Under very high concurrency, requests queue up waiting for the lock. This adds latency but guarantees correctness. For this use case (order processing), correctness is more important than throughput. An alternative would be optimistic locking with version numbers, which allows more parallelism but requires the client to handle conflicts.

**Tested:** 50 parallel requests for a product with 10 units of stock. Result: exactly 10 orders created, stock = 0, never negative.

### 2. Transactional Outbox Pattern

**Problem:** After confirming an order, we need to send a message to RabbitMQ for payment processing. But what if the database write succeeds and the RabbitMQ publish fails? The order is confirmed but the payment never gets processed.

**Decision:** Instead of publishing directly to RabbitMQ, we write the message to an `outbox` table in the same database transaction that creates the order. A separate relay process reads the outbox and publishes to RabbitMQ.

**Guarantee:** Since the order INSERT and the outbox INSERT are in the same transaction, either both succeed or both fail. The relay can safely retry without data loss.

**Trade-off:** Adds latency (the relay polls every 2 seconds) and a background process to manage. But guarantees that no message is ever lost, even if RabbitMQ is temporarily down.

**Tested:** Stopped the relay, created an order, verified the outbox had an unprocessed message, restarted the relay, verified the message was delivered and payment processed.

### 3. Retry with exponential backoff and jitter

**Problem:** The external availability service fails 30% of the time (simulating real-world unreliability).

**Decision:** Retry failed calls up to 3 times with exponential backoff (200ms, 400ms, 800ms base delays) plus random jitter (±50%).

**Why jitter?** Without jitter, if 100 clients fail at the same time and all retry after exactly 400ms, they create a "thundering herd" that overwhelms the recovering service. Jitter spreads retries over time.

**Effective failure rate:** Without retry, 30% of orders would fail. With 3 attempts, the probability of all 3 failing is 0.3³ = 2.7%. The retry mechanism brings the effective failure rate from 30% down to ~3%.

### 4. Idempotency key for duplicate prevention

**Problem:** If the client sends an order, the server processes it, but the response is lost (network timeout), the client retries and creates a duplicate order.

**Decision:** The client can send an `Idempotency-Key` header. The server checks if an order with that key already exists. If yes, it returns the existing order without creating a new one.

**Implementation:** The `idempotency_key` column has a UNIQUE constraint in PostgreSQL. Even if two concurrent requests with the same key bypass the application-level check, the database rejects the second INSERT. Defense in depth.

**Tested:** 20 parallel requests with the same idempotency key. Result: exactly 1 order created.

### 5. Conservative availability check

**Problem:** When the external availability service is completely down (all retries failed), should we accept the order anyway (optimistic) or reject it (conservative)?

**Decision:** Reject with 503. We chose the conservative approach: if we can't verify availability, we don't promise anything to the customer.

**Trade-off:** We lose some valid orders when the external service is down. An optimistic approach would accept the order and verify later, but risks confirming orders for products that are actually unavailable, leading to a worse customer experience (cancellation after confirmation).

### 6. Manual acknowledgment in RabbitMQ

**Problem:** If the payment worker crashes after reading a message but before processing it, the message is lost.

**Decision:** Use manual acknowledgment (`no_ack: false`). The worker sends `ack()` only after successfully updating the order status. If the worker crashes before ack, RabbitMQ redelivers the message.

**Trade-off:** A message could be processed twice if the worker crashes after updating the database but before sending ack. This is acceptable because payment processing should be idempotent (a topic for further improvement).

## Failure Modes

| Failure | Behavior | Protection |
|---------|----------|------------|
| External service timeout | Retry up to 3x with backoff | RetryService |
| External service fully down | Order rejected with 503 | Conservative approach |
| Race condition on stock | Second request waits, sees updated stock | SELECT FOR UPDATE |
| Overselling | Impossible: DB constraint `stock >= 0` | CHECK constraint + row lock |
| Duplicate order on retry | Returns existing order | Idempotency key + UNIQUE |
| RabbitMQ down during order | Message saved in outbox table | Transactional Outbox |
| Relay crashes | Restarts and picks up unprocessed messages | `outbox.processed` flag |
| Worker crashes mid-processing | Message redelivered by RabbitMQ | Manual ack |
| Database down | Health check returns 503 | Health endpoint |

## Accepted Limitations

- **No dead letter queue:** Messages that fail payment processing are nacked and requeued indefinitely. In production, after N failures they should be routed to a dead letter queue for manual inspection.
- **Polling-based relay:** The outbox relay polls every 2 seconds. PostgreSQL `LISTEN/NOTIFY` could reduce latency to near-zero.
- **Single consumer:** Only one payment worker runs. In production, multiple workers would consume from the same queue for horizontal scaling.
- **No circuit breaker:** The external service retry has no circuit breaker pattern. If the service is down for a long time, every request still attempts 3 calls before failing. A circuit breaker would fast-fail after detecting sustained failures.
- **Payment idempotency:** If the worker processes a payment but crashes before ack, the message is redelivered and the payment could be processed twice. The payment processor should check order status before processing.

## Project Structure

```
resilient-order-processor/
├── bin/
│   ├── relay.php                  # Outbox relay process
│   └── worker.php                 # Payment consumer process
├── config/
│   └── database.php               # DB connection config (from env vars)
├── database/
│   └── 001_initial_schema.sql     # Tables: products, orders, outbox
├── docker/
│   └── php/
│       └── Dockerfile             # PHP 8.2 + extensions + Composer
├── public/
│   └── index.php                  # Entry point: routes definition
├── src/
│   ├── Controller/
│   │   └── OrderController.php    # HTTP request handling
│   └── Service/
│       ├── Database.php           # PDO singleton connection
│       ├── ExternalAvailabilityService.php  # Simulated external service
│       ├── ExternalServiceException.php     # Custom exception
│       ├── InventoryService.php   # Atomic stock reservation + outbox
│       ├── OutboxRelay.php        # Outbox → RabbitMQ bridge
│       ├── PaymentProcessor.php   # RabbitMQ consumer
│       ├── QueueService.php       # RabbitMQ client
│       └── RetryService.php       # Generic retry with backoff
├── composer.json
├── docker-compose.yml
└── README.md
```
