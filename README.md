# Flash Sale API ⚡

A high-performance, concurrency-safe REST API built with **Laravel 12** and **MySQL**, designed to handle flash sales with zero overselling.

![Laravel](https://img.shields.io/badge/Laravel-12.0-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![Status](https://img.shields.io/badge/Status-Production%20Ready-success?style=for-the-badge)

## 🚀 Key Features

- **Concurrency Control**: Uses pessimistic locking (`SELECT ... FOR UPDATE`) to guarantee **zero overselling** even under extreme load.
- **Temporary Holds**: Implements a 2-minute reservation system. Stock is held temporarily and auto-released if not purchased.
- **Idempotent Webhooks**: Robust payment webhook processing that handles duplicate events and out-of-order delivery.
- **Automated Expiry**: Background job automatically cleans up expired holds and restores stock.
- **Performance Optimized**: Database-level caching for stock queries to minimize load.

---

## 🛠️ Tech Stack

- **Framework**: Laravel 12
- **Database**: MySQL 8.0 (InnoDB)
- **Cache**: Database Driver (configurable to Redis)
- **Queue**: Database Driver (configurable to Redis/SQS)

---

## ⚙️ Installation & Setup

### Prerequisites
- PHP 8.2+
- MySQL 8.0+
- Composer

### 1. Clone & Install
```bash
git clone <repository-url>
cd flash-sale-api
composer install
```

### 2. Environment Configuration
Copy the example environment file and configure your database:
```bash
cp .env.example .env
```

Update `.env` with your MySQL credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flash_sale
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 3. Database Setup
Run migrations and seed the initial product:
```bash
php artisan key:generate
php artisan migrate --seed
```

### 4. Start the Server
```bash
php artisan serve
```
API will be available at `http://127.0.0.1:8000`.

---

## 📖 API Documentation

### 1. Get Product Details
Retrieve product information and real-time available stock.

- **Endpoint**: `GET /api/products/{id}`
- **Response**:
```json
{
    "id": 1,
    "name": "Flash Sale Product",
    "price": "99.99",
    "stock": 100,
    "available_stock": 98
}
```

### 2. Create Hold (Reserve Stock)
Temporarily reserve stock for a user. Expires in 2 minutes.

- **Endpoint**: `POST /api/holds`
- **Body**:
```json
{
    "product_id": 1,
    "qty": 2
}
```
- **Response**:
```json
{
    "hold_id": 123,
    "expires_at": "2025-12-01T15:30:00Z"
}
```

### 3. Create Order

**Success Response (201)**:
```json
{
  "order_id": 456,
  "product_id": 1,
  "quantity": 2,
  "total_price": "199.98",
  "status": "pending",
  "created_at": "2024-01-01T12:01:30+00:00"
}
```

### 4. Payment Webhook
```http
POST /api/payments/webhook
Content-Type: application/json

{
  "idempotency_key": "unique-payment-123",
  "order_id": 456,
  "status": "success"
}
```

**Response (200)**:
```json
{
  "processed": true,
  "duplicate": false,
  "order_id": 456,
  "status": "success"
}
```

For out-of-order webhooks (arriving before order creation), include:
```json
{
  "idempotency_key": "unique-key",
  "order_id": 999,
  "status": "success",
  "product_id": 1,
  "quantity": 2,
  "hold_id": 123
}
```

## Architecture & Invariants

### Concurrency Control

**Pessimistic Locking**: All stock operations use `SELECT ... FOR UPDATE` within database transactions to prevent race conditions.

**Invariants Enforced**:
- Stock never goes negative
- Sum of holds ≤ initial stock
- Each hold can be consumed exactly once
- Webhook idempotency keys are unique

### Hold Expiry

**Background Processing**: A scheduled job runs every minute to:
1. Find expired, unconsumed holds
2. Mark them as consumed (prevent double-release)
3. Increment stock back to product
4. Log metrics

**Cache Invalidation**: Product cache is invalidated on every stock change to ensure accuracy.

### Webhook Idempotency

**Deduplication**: `webhook_events` table tracks processed webhooks by `idempotency_key`.

**Out-of-Order Safety**: Webhooks can arrive before order creation. The handler creates the order using payload data if it doesn't exist.

**Failure Handling**: Failed payments cancel the order and release stock back to the product.

## Running Tests

Run all tests:
```bash
php artisan test
```

Run specific test suites:
```bash
php artisan test --filter=HoldConcurrencyTest
php artisan test --filter=HoldExpiryTest
php artisan test --filter=WebhookIdempotencyTest
```

### Test Coverage

1. **HoldConcurrencyTest**:
   - Parallel holds at stock boundary (prevents overselling)
   - Stock never goes negative
   - Multiple concurrent small holds

2. **HoldExpiryTest**:
   - Expired holds release stock
   - Available stock excludes expired holds
   - Expiry job prevents double-release
   - Consumed holds not processed

3. **WebhookIdempotencyTest**:
   - Same idempotency key returns same result
   - Webhook arriving before order creation
   - Payment failure cancels order and releases stock
   - Multiple different webhooks processed

## Logs & Metrics

Logs are written to `storage/logs/laravel.log` in structured JSON format.

### Key Metrics

**hold.created**: When a hold is successfully created
```json
{
  "metric": "hold.created",
  "product_id": 1,
  "quantity": 2,
  "timestamp": "2024-01-01T12:00:00+00:00"
}
```

**hold.expired**: When a hold expires and stock is released
```json
{
  "metric": "hold.expired",
  "hold_id": 123,
  "timestamp": "2024-01-01T12:02:00+00:00"
}
```

**webhook.processed**: When a webhook is processed
```json
{
  "metric": "webhook.processed",
  "idempotency_key": "unique-key-123",
  "is_duplicate": false,
  "timestamp": "2024-01-01T12:01:00+00:00"
}
```

**stock.contention**: When pessimistic lock fails (high concurrency)
```json
{
  "metric": "stock.contention",
  "product_id": 1,
  "attempts": 1,
  "timestamp": "2024-01-01T12:00:00+00:00"
}
```

**expiry.batch**: Summary of expiry job execution
```json
{
  "metric": "expiry.batch",
  "expired_count": 5,
  "duration_ms": 120.5,
  "timestamp": "2024-01-01T12:00:00+00:00"
}
```

## Manual Testing

```bash
# Get product info
curl http://localhost:8000/api/products/1

# Create a hold
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id":1,"qty":2}'

# Create an order
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id":1}'

# Send payment webhook (success)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"idempotency_key":"test-123","order_id":1,"status":"success"}'

# Send same webhook again (should return duplicate=true)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"idempotency_key":"test-123","order_id":1,"status":"success"}'
```

## Design Decisions

### Why Pessimistic Locking?

- **Correctness over performance**: Guarantees no overselling
- **Simple reasoning**: Easier to understand than optimistic locking
- **Good enough for flash sales**: Brief lock duration acceptable

### Why Database Cache Driver?

- **Zero dependencies**: Works out of the box
- **Consistent with transactions**: Cache operations use same DB connection
- **Easy to upgrade**: Swap to Redis/Memcached when needed

### Why Background Expiry Job?

- **Proactive cleanup**: Doesn't rely on reads
- **Predictable performance**: Runs on schedule, not blocking requests
- **Metrics visibility**: Centralized logging of expiry stats

## License

MIT
