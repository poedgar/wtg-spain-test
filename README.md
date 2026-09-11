# WTG Spain Test — Laravel REST API

A small REST API that:

- Imports supplier offers asynchronously via a queue.
- Returns the cheapest currently-valid offer per property.
- Safely books offers with concurrency protection.

Built with Laravel 12, MySQL 8, PHP 8.2+.

Repository: <your-repo-url>

---

## Requirements

- PHP 8.2+
- Composer 2
- MySQL 8+
- (Optional) Docker, if you prefer to run MySQL in a container

---

## Installation

```bash
git clone <your-repo-url>
cd wtg-spain-test

composer install
cp .env.example .env
php artisan key:generate
```

Create the database:

```sql
CREATE DATABASE wtg_spain_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Adjust `DB_*` in `.env` if your MySQL credentials differ from the defaults.

---

## Running the app

Run migrations and seed the two required suppliers:

```bash
php artisan migrate --seed
```

Start the HTTP server:

```bash
php artisan serve
```

In a separate terminal, start the queue worker (the import job is queued):

```bash
php artisan queue:work
```

---

## Testing

Create a separate test database once:

```sql
CREATE DATABASE wtg_spain_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run the full test suite:

```bash
php artisan test
```

Feature tests live in `tests/Feature/`:

- `ImportTest` — request validation, idempotent re-import, job side effects, status endpoint.
- `PropertyTest` — cheapest-offer selection, filters, pagination.
- `ReservationTest` — creation, idempotency, no-units, expiry, validation.

`phpunit.xml` points tests at `wtg_spain_testing` and sets `QUEUE_CONNECTION=sync` so the job runs inline.

---

## API

### `POST /api/imports`

Accepts a supplier import. Returns `202` immediately and processes offers in the background.

```json
{
    "supplier": "supplier-a",
    "external_import_id": "import-2026-09-01-001",
    "sent_at": "2026-09-01T10:00:00Z",
    "offers": [
        {
            "external_id": "offer-a-10001",
            "property": {
                "code": "BCN-0001",
                "name": "Apartment near Sagrada Familia",
                "city": "Barcelona"
            },
            "check_in": "2026-10-10",
            "check_out": "2026-10-15",
            "max_guests": 4,
            "price": 72500,
            "currency": "EUR",
            "available_units": 2,
            "expires_at": "2026-09-10T23:59:59Z"
        }
    ]
}
```

Response:

```json
{ "data": { "id": 15, "status": "pending" } }
```

### `GET /api/imports/{import}`

Returns the current state of an import.

```json
{
    "data": {
        "id": 15,
        "supplier": "supplier-a",
        "external_import_id": "import-2026-09-01-001",
        "sent_at": "2026-09-01T10:00:00+00:00",
        "status": "completed",
        "total_offers": 20,
        "processed_offers": 20,
        "error": null,
        "created_at": "2026-09-01T10:00:02+00:00",
        "completed_at": "2026-09-01T10:00:04+00:00"
    }
}
```

### `GET /api/properties`

Returns the cheapest valid offer per property.

Query: `city` (optional), `check_in`, `check_out`, `guests`, `page`, `per_page`.

```bash
curl "http://127.0.0.1:8000/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&page=1"
```

A property is included if its offer matches the dates, has `max_guests >= guests`, has `available_units > 0`, and `expires_at > now()`. The cheapest offer is selected in SQL using a `ROW_NUMBER() OVER (PARTITION BY property ORDER BY price)` window, not by fetching all rows into PHP.

Response:

```json
{
    "data": [
        {
            "code": "BCN-0001",
            "name": "Apartment near Sagrada Familia",
            "city": "Barcelona",
            "best_offer": {
                "id": 125,
                "supplier": "supplier-a",
                "price": 72500,
                "currency": "EUR",
                "available_units": 2,
                "expires_at": "2026-09-10T23:59:59+00:00"
            }
        }
    ],
    "next": null,
    "prev": null,
    "per_page": 15
}
```

### `POST /api/offers/{offer}/reservations`

Creates a reservation for a single offer. Returns `201`.

```json
{
    "client_reference": "web-order-9f782b1c",
    "customer_name": "John Smith",
    "customer_email": "john@example.com"
}
```

---

## Idempotency of imports

Two layers, both enforced by database constraints plus application code:

1. **HTTP layer.** `POST /api/imports` uses `Import::firstOrCreate` on the unique pair `(supplier_id, external_import_id)`. A resend returns the existing row with `202` and the same `id`; no new row is created.
2. **Job dispatch.** The controller only dispatches `ProcessImport` when `$import->wasRecentlyCreated` is true, so a resend cannot enqueue a second job. The job itself also early-returns if the import is already `completed`.

Offers are upserted with `Offer::updateOrCreate` on the unique pair `(supplier_id, external_id)`. Re-importing an offer updates its price/dates instead of duplicating it. Properties are upserted on `properties.code`.

Reservations use `client_reference` as the natural idempotency key — the same reference sent twice returns the existing reservation and does not decrement `available_units` again.

---

## Concurrency protection for reservations

`POST /api/offers/{offer}/reservations` runs inside `DB::transaction`. Inside the transaction, the offer row is locked with `SELECT ... FOR UPDATE` via Eloquent's `lockForUpdate()`:

```php
$locked = Offer::whereKey($offer->id)->lockForUpdate()->first();
```

The first concurrent request acquires an exclusive row lock. Any second request attempting to book the same offer blocks on the same `SELECT` until the first commits. Because the stock check (`available_units < 1`) happens _after_ the lock is acquired, the second transaction sees the already-decremented value and correctly rejects the booking with `422`.

Without this lock, both transactions could read `available_units = 1` and both decrement, overselling the last unit. The row lock serializes them.

Note: this guarantee relies on the storage engine supporting row-level locks (InnoDB in MySQL 8). SQLite treats `lockForUpdate()` as a no-op, which is why tests in this project run against MySQL.

---

## Project structure notes

Standard Laravel layering, no artificial abstractions:

- **Migrations** live in `database/migrations` with foreign keys, unique constraints, and composite indexes for the hot queries.
- **Models** in `app/Models` with relationships, casts, and status constants.
- **Form Requests** in `app/Http/Requests` handle validation, including nested offers and search filters.
- **Controllers** in `app/Http/Controllers/Api` stay thin — persistence, dispatch, and response shaping only.
- **Business logic** for import processing lives in the queued `App\Jobs\ProcessImport`.
- **Resources** in `app/Http/Resources` shape the wire format.
- **Factories and seeders** in `database/factories` and `database/seeders` support both local setup and tests.

---

## License

For evaluation purposes only.
