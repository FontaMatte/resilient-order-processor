# Resilient Order Processor

A backend system designed to handle order processing with concurrency, network failures, and data consistency under pressure. The goal is not to build an API that works locally with one user at a time — it's to design a system that behaves correctly under difficult conditions.

## Tech Stack

- **PHP 8.2+** with Slim Framework (micro-framework, no ORM magic)
- **PostgreSQL 16** — relational database with strong concurrency support (MVCC, row-level locking)
- **RabbitMQ 3** — message broker for async payment processing
- **Docker Compose** — local development orchestration (3 services)
- **Monolog** — structured JSON logging (PSR-3)
- **PHPUnit** — automated testing

## Architecture

```
                                    ┌─────────────────────────────────┐
                                    │         API (PHP/Slim)          │
                                    │                                 │
POST /orders ──────────────────────►│  1. Rate limiting (per IP)      │
                                    │  2. Idempotency check           │
                                    │  3. Validate input              │
                                    │  4. Check availability          │──── Simulated external
                                    │     Circuit Breaker             │     service (30% failure)
                                    │     └── Retry + backoff         │
                                    │  5. Reserve stock (FOR UPDATE)  │
                                    │  6. Create order                │
                                    │  7. Write to outbox             │──── Same DB transaction
                                    │  8. Return 201                  │
                                    └──────────────┬──────────────────┘
                                                   │
                                    ┌──────────────▼──────────────────┐
                                    │   PostgreSQL (orders, outbox)   │
                                    │   LISTEN/NOTIFY trigger         │
                                    └──────────────┬──────────────────┘
                                                   │ real-time notification
                                    ┌──────────────▼──────────────────┐
                                    │     Outbox Relay                │
                                    │     LISTEN/NOTIFY + fallback    │
                                    │     Publishes to RabbitMQ       │
                                    └──────────────┬──────────────────┘
                                                   │
                                    ┌──────────────▼──────────────────┐
                                    │   RabbitMQ                      │
                                    │   order_payments (main queue)   │
                                    │   order_payments_dlq (dead)     │
                                    └──────────────┬──────────────────┘
                                                   │
                                    ┌──────────────▼──────────────────┐
                                    │   Payment Worker                │
                                    │   Idempotent processing         │
                                    │   DLQ after 3 failures          │
                                    │   Graceful shutdown             │
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

# Run the database migrations
docker compose exec -T db psql -U app -d orders < database/001_initial_schema.sql
docker compose exec -T db psql -U app -d orders < database/002_outbox_notify.sql

# Start the outbox relay (third terminal)
docker compose exec app php bin/relay.php

# Start the payment worker (fourth terminal)
docker compose exec app php bin/worker.php
```

### Web Dashboard

Open **http://localhost:8080/dashboard.html** for a visual interface to create orders, monitor metrics, and inspect system health.

### API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | System health check (DB + RabbitMQ) |
| GET | `/products` | List all products with stock |
| POST | `/orders` | Create a new order |
| GET | `/orders/list` | List recent orders (last 20) |
| GET | `/orders/{id}` | Get order details by UUID |
| GET | `/metrics` | System metrics (counters, timings) |

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
- `429` — rate limit exceeded
- `503` — external service unreachable or circuit breaker open

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

**Trade-off:** Adds a background process to manage. But guarantees that no message is ever lost, even if RabbitMQ is temporarily down.

**Tested:** Stopped the relay, created an order, verified the outbox had an unprocessed message, restarted the relay, verified the message was delivered and payment processed.

### 3. LISTEN/NOTIFY for real-time relay

**Problem:** The original outbox relay used polling (checking the database every 2 seconds). This added unnecessary latency and wasted resources.

**Decision:** Use PostgreSQL's native LISTEN/NOTIFY mechanism. A database trigger fires `pg_notify()` on every outbox INSERT. The relay listens for notifications and processes messages in near real-time, with a fallback poll every 5 seconds for safety.

**Trade-off:** Slightly more complex setup (trigger + LISTEN), but reduces message delivery latency from ~2 seconds to milliseconds.

### 4. Retry with exponential backoff and jitter

**Problem:** The external availability service fails 30% of the time (simulating real-world unreliability).

**Decision:** Retry failed calls up to 3 times with exponential backoff (200ms, 400ms, 800ms base delays) plus random jitter (±50%).

**Why jitter?** Without jitter, if 100 clients fail at the same time and all retry after exactly 400ms, they create a "thundering herd" that overwhelms the recovering service. Jitter spreads retries over time.

**Effective failure rate:** Without retry, 30% of orders would fail. With 3 attempts, the probability of all 3 failing is 0.3³ = 2.7%. The retry mechanism brings the effective failure rate from 30% down to ~3%.

### 5. Circuit Breaker

**Problem:** When the external service is completely down, every request still makes 3 retry attempts before failing — wasting time and resources.

**Decision:** Implement the Circuit Breaker pattern with 3 states: CLOSED (normal), OPEN (reject immediately), HALF-OPEN (try one call to check recovery). After 5 consecutive failures, the circuit opens and all calls are rejected instantly for 30 seconds.

**State persistence:** The circuit breaker state is saved to a file so it survives across HTTP requests (PHP's built-in server re-executes the script per request). In production, Redis would be used.

**Tested:** With 100% failure rate, first 5 requests triggered retries (~1 second each). From request 6 onward, all were rejected instantly by the circuit breaker.

### 6. Idempotency key for duplicate prevention

**Problem:** If the client sends an order, the server processes it, but the response is lost (network timeout), the client retries and creates a duplicate order.

**Decision:** The client can send an `Idempotency-Key` header. The server checks if an order with that key already exists. If yes, it returns the existing order without creating a new one.

**Implementation:** The `idempotency_key` column has a UNIQUE constraint in PostgreSQL. Even if two concurrent requests with the same key bypass the application-level check, the database rejects the second INSERT. Defense in depth.

**Tested:** 20 parallel requests with the same idempotency key. Result: exactly 1 order created.

### 7. Dead Letter Queue

**Problem:** Messages that fail payment processing were being requeued indefinitely, creating an infinite loop.

**Decision:** Track retry count in the message payload. After 3 failed attempts, the message is moved to a dedicated Dead Letter Queue (`order_payments_dlq`) and the order is marked as `failed`.

**Implementation:** On failure, the worker acknowledges the original message and republishes a copy with an incremented `retry_count`. This allows modifying the payload (unlike `nack` with requeue which sends the identical message).

### 8. Payment idempotency

**Problem:** If the worker processes a payment but crashes before sending `ack`, RabbitMQ redelivers the message. Without protection, the payment could be processed twice.

**Decision:** Before processing, the worker checks the order status in the database. If it's already `completed` or `failed`, the message is acknowledged and skipped.

### 9. Conservative availability check

**Problem:** When the external availability service is completely down (all retries failed), should we accept the order anyway (optimistic) or reject it (conservative)?

**Decision:** Reject with 503. If we can't verify availability, we don't promise anything to the customer.

**Trade-off:** We lose some valid orders when the external service is down. An optimistic approach would accept the order and verify later, but risks confirming orders for products that are actually unavailable.

### 10. Manual acknowledgment in RabbitMQ

**Problem:** If the payment worker crashes after reading a message but before processing it, the message is lost.

**Decision:** Use manual acknowledgment (`no_ack: false`). The worker sends `ack()` only after successfully updating the order status. If the worker crashes before ack, RabbitMQ redelivers the message.

### 11. Graceful shutdown

**Problem:** When the worker is stopped (Ctrl+C or Docker SIGTERM), it dies immediately, potentially mid-processing.

**Decision:** Register signal handlers for SIGINT and SIGTERM. When received, a flag is set and the worker finishes processing the current message before stopping cleanly.

### 12. Rate limiting

**Problem:** Without rate limiting, a single client can flood the API with thousands of requests.

**Decision:** Sliding window rate limiter implemented as PSR-15 middleware. Each IP is limited to 30 requests per minute. Returns 429 with `Retry-After` header when exceeded. Response headers `X-RateLimit-Remaining` inform clients of their quota.

### 13. Structured logging

**Problem:** Plain `error_log()` produces unstructured text that's hard to parse and filter.

**Decision:** Use Monolog (PSR-3) with JSON formatter writing to stdout. Each log entry includes structured context (operation name, attempt number, error details) that monitoring systems can index and query.

## Failure Modes

| Failure | Behavior | Protection |
|---------|----------|------------|
| External service timeout | Retry up to 3x with backoff | RetryService |
| External service sustained failure | Instant rejection | Circuit Breaker |
| External service fully down | Order rejected with 503 | Conservative approach |
| Race condition on stock | Second request waits, sees updated stock | SELECT FOR UPDATE |
| Overselling | Impossible: DB constraint `stock >= 0` | CHECK constraint + row lock |
| Duplicate order on retry | Returns existing order | Idempotency key + UNIQUE |
| RabbitMQ down during order | Message saved in outbox table | Transactional Outbox |
| Relay crashes | Restarts and picks up unprocessed messages | `outbox.processed` flag |
| Worker crashes mid-processing | Message redelivered by RabbitMQ | Manual ack |
| Payment fails repeatedly | Moved to DLQ after 3 attempts | Dead Letter Queue |
| Duplicate payment processing | Skipped if order already completed | Payment idempotency |
| Worker stopped (deploy/scaling) | Finishes current message first | Graceful shutdown |
| Client flooding | Rejected with 429 | Rate limiting middleware |
| Database down | Health check returns 503 | Health endpoint |

## Accepted Limitations

- **File-based state for circuit breaker and rate limiter:** In production, Redis would provide shared state across multiple processes/containers.
- **Single consumer:** Only one payment worker. In production, multiple workers would consume from the same queue for horizontal scaling.
- **No authentication:** The API has no auth layer. In production, JWT or API key authentication would be required.
- **No TLS:** Communication is unencrypted. In production, HTTPS would be mandatory.
- **Polling fallback in relay:** The LISTEN/NOTIFY relay falls back to polling every 5 seconds. In a perfect world, LISTEN/NOTIFY alone would suffice, but the fallback provides defense in depth.

## Running Tests

```bash
docker compose exec app vendor/bin/phpunit
```

Tests cover RetryService (4 tests) and CircuitBreaker (4 tests), verifying retry behavior, max attempts, circuit state transitions, and failure threshold.

## Project Structure

```
resilient-order-processor/
├── bin/
│   ├── relay.php                  # Outbox relay process (LISTEN/NOTIFY)
│   └── worker.php                 # Payment consumer (graceful shutdown)
├── config/
│   └── database.php               # DB connection config (from env vars)
├── database/
│   ├── 001_initial_schema.sql     # Tables: products, orders, outbox
│   └── 002_outbox_notify.sql      # Trigger for LISTEN/NOTIFY
├── docker/
│   └── php/
│       └── Dockerfile             # PHP 8.2 + extensions + Composer
├── public/
│   ├── index.php                  # Entry point: routes and middleware
│   └── dashboard.html             # Web dashboard for testing/monitoring
├── src/
│   ├── Controller/
│   │   └── OrderController.php    # HTTP request handling + idempotency
│   ├── Middleware/
│   │   └── RateLimitMiddleware.php # Sliding window rate limiter (PSR-15)
│   └── Service/
│       ├── CircuitBreaker.php     # Circuit breaker with file persistence
│       ├── CircuitBreakerOpenException.php
│       ├── Database.php           # PDO singleton connection
│       ├── ExternalAvailabilityService.php  # Simulated external service
│       ├── ExternalServiceException.php
│       ├── InventoryService.php   # Atomic stock + order + outbox
│       ├── Logger.php             # Monolog factory (PSR-3, JSON)
│       ├── Metrics.php            # Counter, gauge, timing metrics
│       ├── OutboxRelay.php        # Outbox → RabbitMQ (LISTEN/NOTIFY)
│       ├── PaymentProcessor.php   # Consumer + DLQ + idempotency
│       ├── QueueService.php       # RabbitMQ client + DLQ
│       └── RetryService.php       # Generic retry with backoff
├── tests/
│   ├── RetryServiceTest.php       # 4 unit tests
│   └── CircuitBreakerTest.php     # 4 unit tests
├── composer.json
├── docker-compose.yml
├── phpunit.xml
└── README.md
```