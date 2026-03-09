# Gymsdata API (second database)

The gymsdata site (https://gymdues.com/gymsdata/) can use a **second database** (or a second table in the same DB) for the list page: states, locations, cities, and paginated gym list. The gymsdata connection uses **PostgreSQL**.

## Backend setup

### 1. Database config

In `config/database.php` a connection `gymsdata` is defined (driver: **pgsql**). In `.env` set:

- **Same database, different table** (e.g. table `gyms_data` in main DB):  
  Leave `GYMSDATA_DB_*` unset or same as `DB_*`. Set only:
  - `GYMSDATA_DB_TABLE=gyms_data` (or your table name)

- **Different database**: set at least:
  - `GYMSDATA_DB_HOST`
  - `GYMSDATA_DB_DATABASE`
  - `GYMSDATA_DB_USERNAME`
  - `GYMSDATA_DB_PASSWORD`
  - `GYMSDATA_DB_PORT=5432` (PostgreSQL default)
  - `GYMSDATA_DB_TABLE=gyms_data` (optional; default `gyms_data`)

Optional: `GYMSDATA_DB_SSLMODE=prefer` or `GYMSDATA_DATABASE_URL=postgresql://user:pass@host:5432/dbname`

### 2. Table schema (expected columns)

The table should have at least these columns (matching the sample JSON):

| Column           | Type         | Notes                    |
|------------------|--------------|--------------------------|
| id               | int/bigint   | Primary key              |
| type             | varchar      | e.g. `Gym`; API filters `type = 'Gym'` |
| business_name    | varchar      | Gym name                 |
| city             | varchar      |                          |
| state            | varchar(2)   | e.g. CA, NY              |
| postal_code      | varchar      | Optional                  |
| street           | varchar      | Optional                  |
| full_address     | varchar      | Optional                  |
| business_phone   | varchar      | Optional                  |
| email_1          | varchar      | Optional                  |
| business_website | varchar      | Optional                  |
| latitude         | decimal/float | Optional                  |
| longitude        | decimal/float | Optional                  |
| total_reviews    | int          | Optional                  |
| average_rating   | decimal      | Optional                  |

Other columns (e.g. `google_id`, `review_url`) are ignored by the API but can exist.

**Optional columns for industry trends** (used by `GET /api/v1/gymsdata/industry-trends` when present):

| Column           | Type        | Notes |
|------------------|-------------|--------|
| created_at       | timestamp   | Enables “New Gyms Opened (Last 12 Months)” from DB. |
| category         | varchar     | Enables “Fastest Growing Categories” from DB (e.g. Traditional, Specialty, Boutique). |
| ownership_type   | varchar     | Values `franchise` / `franchisee` vs other: enables “Franchise vs Independent” by quarter. |

### 3. API endpoints (all under `GET /api/v1/gymsdata/`)

All endpoints require the same API key / middleware as the rest of `/api/v1`.

**Quick reference**

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `gymsdata/list-page` | US list page: total gyms, states, types, contact stats, sample rows; includes price + count per state/type and full-dataset price |
| GET | `gymsdata/state-comparison` | All states’ metrics (frontend compares any subset) |
| GET | `gymsdata/state-page/{state}` | Single state: stats, top cities (state = lowercase, hyphens e.g. `california`) |
| GET | `gymsdata/city-page/{state}/{city}` | Single city: stats, top areas, nearby (state/city = lowercase, hyphens e.g. `california`/`costa-mesa`) |
| GET | `gymsdata/industry-trends` | Dashboard: new gyms by month, growing cities, categories, franchise vs independent |
| GET | `gymsdata/chain-comparison` | Static chain comparison data |
| GET | `gymsdata/testimonials` | Static testimonial cards |
| GET | `gymsdata/top-cities` | Top N locations by gym count |
| GET | `gymsdata/states` | States with gym counts and pct |
| GET | `gymsdata/locations` | City/state/postal_code with counts (autocomplete) |
| GET | `gymsdata/cities-and-states` | States + cities with counts (one-shot) |
| GET | `gymsdata/cities` | Cities in a state (with optional filters) |
| GET | `gymsdata` | Paginated gym list (index) |
| POST | `gymsdata/sample-download` | Submit name + email; optional **state**, **city** (with state), **type**, or none (full); returns Excel + email copy |
| POST | `gymsdata/checkout` | Create Stripe Checkout; **amount calculated from scope** (row count, same as list/state/city price). Body: name, email; optional state, city, type. Returns session URL. |
| POST | `gymsdata/resend-purchase-email` | Resend data email for a paid purchase (by id; optional token) |
| POST | `webhooks/stripe/gymsdata-purchase` | Stripe webhook (no API key) for `checkout.session.completed` |

**Response summary**

| Endpoint | Response (200) |
|----------|----------------|
| `states` | Array of `{ state, stateName, stateSlug, count, pct, imageUrl }` |
| `locations` | Array of `{ label, city, state, stateName, postal_code, count }` (max 50) |
| `cities-and-states` | Object `{ states: [...], cities: [...] }` |
| `cities` | Array of `{ label, city, state, stateName, postal_code, count }` |
| `top-cities` | Object `{ cities: [{ rank, city, state, stateName, postal_code, label, count }] }` |
| `chain-comparison` | Object `{ chains: [...] }` |
| `testimonials` | Object `{ testimonials: [...] }` |
| `industry-trends` | Object `{ newGymsByMonth, mostGrowingCities, categories, franchiseVsIndependent }` |
| `state-comparison` | Object `{ states: [{ state, stateName, stateSlug, totalGyms, withEmail, withPhone, avgRating, densityPer100k, imageUrl }] }` |
| `state-page` | Object `{ state, stateName, stateSlug, totalGyms, price, formattedPrice, citiesCount, ... topCities, cities?, nearbyCities? }` — state and each city have **price**, **formattedPrice** (row-count based) |
| `city-page` | Object `{ state, stateName, stateSlug, city, totalGyms, price, formattedPrice, ... topAreas, nearbyCities }` — city and each nearby city have **price**, **formattedPrice** |
| `list-page` | Object `{ totalGyms, price, formattedPrice, statesCovered, states, typesCovered, types, ... sample }` — each **state** and **type** has `count`, `price`, `formattedPrice`; full-dataset `price`/`formattedPrice` at root |
| `gymsdata` (index) | Paginated `{ data, current_page, per_page, total, from, to, first_page_url, last_page_url, next_page_url, prev_page_url, path }` |

---

#### `GET /api/v1/gymsdata/states`

**Endpoint:** `GET /api/v1/gymsdata/states`

States with gym counts (for “Browse by state” list).

**Request:** No query parameters.

**Response:** `200` — array of objects:

```json
[
  {
    "state": "CA",
    "stateName": "California",
    "stateSlug": "california",
    "count": 12500,
    "pct": 18.5,
    "imageUrl": "https://example.com/images/california.jpg"
  }
]
```

---

#### `GET /api/v1/gymsdata/locations`

**Endpoint:** `GET /api/v1/gymsdata/locations`

Locations (city + state + postal_code) with counts; for autocomplete/search.

**Request:**

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `q`       | string | No       | Filter by city, state, or postal_code (partial match). |

**Response:** `200` — array of objects (max 50):

```json
[
  {
    "label": "Costa Mesa, CA 92626",
    "city": "Costa Mesa",
    "state": "CA",
    "stateName": "California",
    "postal_code": "92626",
    "count": 31
  }
]
```

---

#### `GET /api/v1/gymsdata/cities-and-states`

**Endpoint:** `GET /api/v1/gymsdata/cities-and-states`

States and cities with counts (one-shot for list + filters).

**Request:** No query parameters.

**Response:** `200` — object:

```json
{
  "states": [
    {
      "state": "CA",
      "stateName": "California",
      "stateSlug": "california",
      "count": 12500,
      "pct": 18.5,
      "imageUrl": "https://example.com/images/california.jpg"
    }
  ],
  "cities": [
    { "city": "Los Angeles", "count": 1200 },
    { "city": "Costa Mesa", "count": 31 }
  ]
}
```

---

#### `GET /api/v1/gymsdata/cities`

**Endpoint:** `GET /api/v1/gymsdata/cities?state={code}`

Cities (or city+postal_code locations) in a state with counts.

**Request:**

| Parameter   | Type   | Required | Description |
|-------------|--------|----------|-------------|
| `state`    | string | Yes      | State code or name (e.g. `CA` or `California`). |
| `sort`     | string | No       | `count` (default) or `name`. |
| `group_by` | string | No       | `location` (default: per city+postal_code) or `city` (one row per city). |
| `limit`    | int    | No       | Max rows (1–500). |
| `offset`   | int    | No       | Skip N rows (default 0). |
| `min_count`| int    | No       | Only rows with count ≥ this. |
| `max_count`| int    | No       | Only rows with count ≤ this. |

**Response:** `200` — array of objects:

```json
[
  {
    "label": "Costa Mesa, CA 92626",
    "city": "Costa Mesa",
    "state": "CA",
    "stateName": "California",
    "postal_code": "92626",
    "count": 31
  }
]
```

---

#### `GET /api/v1/gymsdata/top-cities`

**Endpoint:** `GET /api/v1/gymsdata/top-cities?limit={n}`

Top N locations (city + state + postal_code) by gym count.

**Request:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `limit`   | int  | No       | Number of results (1–50, default 10). |

**Response:** `200` — object:

```json
{
  "cities": [
    {
      "rank": 1,
      "city": "Carmel",
      "state": "IN",
      "stateName": "Indiana",
      "postal_code": "46032",
      "label": "Carmel, Indiana 46032",
      "count": 45
    }
  ]
}
```

---

#### `GET /api/v1/gymsdata/chain-comparison`

**Endpoint:** `GET /api/v1/gymsdata/chain-comparison`

Static gym chain comparison (curated reference data).

**Request:** No query parameters.

**Response:** `200` — object:

```json
{
  "chains": [
    {
      "chainName": "LA Fitness",
      "locations": 700,
      "locationsLabel": "700+",
      "avgPrice": 35,
      "avgPriceLabel": "$35/mo",
      "amenitiesScore": 8.5,
      "amenitiesScoreLabel": "8.5/10",
      "userRating": 4.2,
      "path": "la-fitness"
    }
  ]
}
```

---

#### `GET /api/v1/gymsdata/testimonials`

**Endpoint:** `GET /api/v1/gymsdata/testimonials`

Static “What Our Users Say” testimonial cards.

**Request:** No query parameters.

**Response:** `200` — object:

```json
{
  "testimonials": [
    {
      "quote": "...",
      "rating": 5,
      "authorName": "Jordan Lee",
      "authorTitle": "Growth Lead, FitStack Analytics",
      "initials": "JL"
    }
  ]
}
```

---

#### `GET /api/v1/gymsdata/industry-trends`

**Endpoint:** `GET /api/v1/gymsdata/industry-trends`

One-shot data for the **Gym Industry Trends** dashboard: new gyms by month, most growing cities, fastest growing categories (donut), and franchise vs independent over time (quarterly). Uses DB when optional columns exist (`created_at`, `category`, `ownership_type`); otherwise returns curated fallbacks so the dashboard always has data.

**Request:** No query parameters.

**Response:** `200` — object with four blocks:

```json
{
  "newGymsByMonth": [
    { "month": "Mar 2024", "monthKey": "2024-03", "count": 412 },
    { "month": "Apr 2024", "monthKey": "2024-04", "count": 508 }
  ],
  "mostGrowingCities": [
    {
      "rank": 1,
      "city": "Houston",
      "state": "TX",
      "stateName": "Texas",
      "label": "Houston, TX",
      "growth": 127,
      "count": 127,
      "period": "12 mo"
    }
  ],
  "categories": [
    { "category": "Traditional / Full-service", "count": 28000, "percentage": 41.5 },
    { "category": "Specialty (Yoga, Pilates)", "count": 12000, "percentage": 17.8 },
    { "category": "24/7 Low-cost", "count": 11500, "percentage": 17.0 },
    { "category": "CrossFit / Functional", "count": 8500, "percentage": 12.6 },
    { "category": "Boutique / Studio", "count": 7500, "percentage": 11.1 }
  ],
  "franchiseVsIndependent": [
    { "quarter": "Q1 2023", "quarterKey": "2023-Q1", "franchise": 15000, "independent": 37500 },
    { "quarter": "Q2 2023", "quarterKey": "2023-Q2", "franchise": 15100, "independent": 37600 }
  ]
}
```

- **newGymsByMonth**: Last 12 months; from DB if `created_at` exists, else curated monthly counts.
- **mostGrowingCities**: Top 10 cities by **growth** (new gyms in the last 12 months) when `created_at` exists; otherwise top 10 by total gym count. Each item has `growth`, `count` (same as growth when from DB), and `period` (`"12 mo"` when growth is used, `null` when total count). Frontend can show e.g. “Growth: +127 gyms (12 mo)” using `growth` and `period`.
- **categories**: From DB if table has multiple `type` or a `category` column; else static “Fastest Growing Categories” (Traditional, Specialty, 24/7 Low-cost, CrossFit, Boutique) with counts/percentages.
- **franchiseVsIndependent**: Quarterly series; from DB if `ownership_type` (and `created_at`) exist, else static Q1 2023–Q1 2025.

Optional table columns used when present: `created_at`, `category`, `ownership_type` (values `franchise`/`franchisee` = franchise).

---

#### `GET /api/v1/gymsdata/state-comparison`

**Endpoint:** `GET /api/v1/gymsdata/state-comparison`

Returns metrics for states so the frontend can compare any subset (e.g. pick 3). Optimized for speed: **all states** are cached (default 1 hour); **?states=CA,TX,FL** returns only those states with a single `WHERE state IN (...)` query (no cache, fast with index on `state`). If the request takes too long, the backend returns **504 Gateway Timeout** with a “Took too long” message.

**Request:**

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `states`  | string | No       | Comma-separated state codes (e.g. `CA,TX,FL`). When provided, only those states are returned (faster). Omit for all states (cached). |

**Env (optional):** `GYMSDATA_STATE_COMPARISON_TIMEOUT_MS` (default 12000), `GYMSDATA_STATE_COMPARISON_CACHE_TTL` (seconds, default 3600). Ensure index on `state` exists for the gymsdata table (migration 1.0.47 or `CREATE INDEX idx_gyms_data_state ON gyms_data (state)`).

**Response:** `200` — object with one entry per state (or per requested state). `504` if the query times out.

```json
{
  "states": [
    {
      "state": "CA",
      "stateName": "California",
      "stateSlug": "california",
      "totalGyms": 12500,
      "withEmail": 9100,
      "withPhone": 9000,
      "avgRating": 4.3,
      "densityPer100k": 31.6,
      "imageUrl": "https://example.com/images/california.jpg"
    }
  ]
}
```

Frontend can either request **?states=CA,TX,FL** for a faster response or request all states and filter client-side.

---

#### `GET /api/v1/gymsdata/state-page`

**Endpoint (path):** `GET /api/v1/gymsdata/state-page/{state}` — state = **lowercase, hyphens** (e.g. `california`, `new-york`).

**Endpoint (query):** `GET /api/v1/gymsdata/state-page?state={state}` — state = slug or code/name.

Data for “List of Gyms in [State]” page: stats, top cities, optional full cities list.

**Request:**

| Parameter       | Type   | Required | Description |
|-----------------|--------|----------|-------------|
| `state`        | string | Yes      | Path: lowercase slug with hyphens (e.g. `california`). Query: same or state code/name. |
| `include_cities`| flag   | No       | `1` or `true` to add `cities` and `nearbyCities`. |
| `cities_sort`  | string | No       | `count` (default) or `name`. |
| `cities_limit` | int    | No       | Max cities when `include_cities=1` (1–500, default 200). |

**Response:** `200` — object includes **price**, **formattedPrice** for the state (row-count based); **topCities** and **cities** items include `count`, **price**, **formattedPrice**:

```json
{
  "state": "CA",
  "stateName": "California",
  "stateSlug": "california",
  "totalGyms": 12500,
  "price": 99,
  "formattedPrice": "$99",
  "citiesCount": 450,
  "pctWithEmail": 73,
  "pctWithPhone": 72,
  "pctWithSocial": 50,
  "avgRating": 4.3,
  "topCities": [
    { "city": "Los Angeles", "count": 1200, "price": 99, "formattedPrice": "$99", "label": "Los Angeles, California" }
  ],
  "imageUrl": "https://example.com/images/california.jpg",
  "cities": [],
  "nearbyCities": []
}
```

`cities` and `nearbyCities` are only present when `include_cities=1`. Each city item: `label`, `city`, `state`, `stateName`, `postal_code` (empty when grouped by city), `count`, **price**, **formattedPrice**.

---

#### `GET /api/v1/gymsdata/city-page`

**Endpoint (path):** `GET /api/v1/gymsdata/city-page/{state}/{city}` — state and city = **lowercase, hyphens** (e.g. `california`, `costa-mesa`).

**Endpoint (query):** `GET /api/v1/gymsdata/city-page?state={state}&city={city}` — same slug format or code/name.

Data for “List of Gyms in [City], [State]” page: stats, top areas by postal code, nearby cities.

**Request:**

| Parameter | Type   | Required | Description |
|-----------|--------|----------|-------------|
| `state`   | string | Yes      | Path: lowercase slug (e.g. `california`). Query: same or state code/name. |
| `city`    | string | Yes      | Path: lowercase slug with hyphens (e.g. `costa-mesa`). Query: same or city name. |

**Response:** `200` — object includes **price**, **formattedPrice** for the city; **nearbyCities** items include `count`, **price**, **formattedPrice**:

```json
{
  "state": "CA",
  "stateName": "California",
  "stateSlug": "california",
  "city": "Costa Mesa",
  "totalGyms": 31,
  "price": 29,
  "formattedPrice": "$29",
  "pctWithEmail": 73,
  "pctWithPhone": 72,
  "pctWithSocial": 50,
  "avgRating": 4.3,
  "topAreas": [
    { "area": "92626", "count": 12, "label": "ZIP 92626" },
    { "area": "92627", "count": 11, "label": "ZIP 92627" },
    { "area": "", "count": 8, "label": "Other" }
  ],
  "nearbyCities": [
    { "city": "Irvine", "count": 28, "price": 29, "formattedPrice": "$29", "label": "Irvine, California" }
  ],
  "imageUrl": "https://example.com/images/california.jpg"
}
```

---

#### `GET /api/v1/gymsdata/list-page`

**Endpoint:** `GET /api/v1/gymsdata/list-page`

One-shot data for “List of Gyms in United States”: summary stats, **states** and **types** with counts (same shape), sample rows.

**Request:**

| Parameter    | Type | Required | Description |
|--------------|------|----------|-------------|
| `sample_size`| int  | No       | Number of sample rows (1–20, default 5). |

**Response:** `200` — object with root `totalGyms`, `price`, `formattedPrice` (full-dataset); `states` and `types` each include `count`, `pct`, **price**, **formattedPrice** (row-count based). Use for "Buy data" and **POST gymsdata/checkout** `amount`.

```json
{
  "totalGyms": 67500,
  "price": 249,
  "formattedPrice": "$249",
  "statesCovered": 51,
  "states": [
    {
      "state": "CA",
      "stateName": "California",
      "stateSlug": "california",
      "count": 12500,
      "pct": 18.5,
      "price": 99,
      "formattedPrice": "$99",
      "imageUrl": "https://example.com/images/california.jpg"
    }
  ],
  "typesCovered": 3,
  "types": [
    {
      "type": "Gym",
      "typeSlug": "gym",
      "count": 60000,
      "pct": 88.9,
      "price": 249,
      "formattedPrice": "$249"
    }
  ],
  "withEmail": 49275,
  "withPhone": 48600,
  "withPhoneAndEmail": 45000,
  "withWebsite": 40500,
  "withFacebook": 33750,
  "withInstagram": 30375,
  "withTwitter": 16875,
  "withLinkedin": 6750,
  "withYoutube": 6750,
  "ratedCount": 54000,
  "sample": [
    {
      "name": "Fit Life Gym",
      "address": "123 Main St",
      "city": "Costa Mesa",
      "state": "CA",
      "stateName": "California",
      "email": "info@example.com",
      "phone": "+1 555 123 4567",
      "website": "https://example.com"
    }
  ]
}
```

---

#### `GET /api/v1/gymsdata`

**Endpoint:** `GET /api/v1/gymsdata?state=&city=&search=&page=1&per_page=12`

Paginated list of gyms (table view / listing).

**Request:**

| Parameter  | Type   | Required | Description |
|------------|--------|----------|-------------|
| `state`    | string | No       | Filter by state (code or name). |
| `city`     | string | No       | Filter by city (partial match). |
| `search`   | string | No       | Search in name, address, city, postal_code. |
| `page`     | int    | No       | Page number (default 1). |
| `per_page` | int    | No       | Items per page (1–100, default 12). |

**Response:** `200` — paginated object (no `links` key):

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 12345,
      "name": "Fit Life Gym",
      "slug": null,
      "phone": "+1 555 123 4567",
      "email": "info@example.com",
      "website": "https://example.com",
      "address": {
        "full_address": "123 Main St",
        "street": "123 Main St",
        "city": "Costa Mesa",
        "state": "CA",
        "stateName": "California",
        "postal_code": "92626",
        "latitude": 33.6411,
        "longitude": -117.9187
      },
      "reviewCount": 120,
      "average_rating": 4.3,
      "total_reviews": 120
    }
  ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 5,
  "last_page_url": "...",
  "next_page_url": "...",
  "path": "...",
  "per_page": 12,
  "prev_page_url": null,
  "to": 12,
  "total": 58
}
```

---

**Error responses:** Endpoints may return `400` (e.g. missing required `state`/`city`) or `500` with body `{ "error": "Internal server error", "message": "..." }`.

## Frontend setup

In the Next.js app (gymdues-fe), set:

```env
NEXT_PUBLIC_USE_GYMSDATA_API=true
```

When this is set, the list page and location autocomplete use the gymsdata endpoints (`/api/v1/gymsdata/*`) instead of `/api/v1/gyms/*`. Leave unset or `false` to use the main CRM database.

Optionally turn off mock data so the real API is used:

```env
USE_LIST_PAGE_MOCK=false
```

## Creating the table (PostgreSQL)

If you import from the sample JSON, ensure the table has the columns above. Example:

```sql
CREATE TABLE gyms_data (
  id BIGINT PRIMARY KEY,
  type VARCHAR(50) DEFAULT 'Gym',
  business_name VARCHAR(255),
  city VARCHAR(255),
  state VARCHAR(10),
  postal_code VARCHAR(20),
  street VARCHAR(255),
  full_address TEXT,
  business_phone VARCHAR(50),
  email_1 VARCHAR(255),
  business_website VARCHAR(500),
  latitude DECIMAL(10,8),
  longitude DECIMAL(11,8),
  total_reviews INT,
  average_rating DECIMAL(3,2)
  -- optional: google_id, review_url, created_at, category, ownership_type, etc.
);

CREATE INDEX idx_gyms_data_state ON gyms_data (state);
CREATE INDEX idx_gyms_data_city_state ON gyms_data (city, state);
```

If `id` should auto-increment (e.g. for new inserts), use `id BIGSERIAL PRIMARY KEY` instead of `id BIGINT PRIMARY KEY`. Then load your JSON/CSV into `gyms_data` (e.g. via import script or DB tool).

### Downloads table (same PostgreSQL database)

Generic table for **sample download** (free) and **purchase** (buy all data). Create in the **same database** as your gyms table. Table name: `downloads`.

| Column      | Type           | Description |
|-------------|----------------|-------------|
| id          | BIGSERIAL      | Primary key |
| name        | VARCHAR(255)   | Contact name |
| email       | VARCHAR(255)   | Contact email |
| type        | VARCHAR(50)    | `sample` (default) or `purchase` |
| amount      | DECIMAL(10,2)  | Optional; for purchases |
| stripe_checkout_session_id | VARCHAR(255) | Stripe Checkout session id (for webhook lookup) |
| payment_status | VARCHAR(50) | `pending` (default), `paid`, `failed`, `refunded` |
| email_sent_at | TIMESTAMP   | When data was sent to customer; **NULL = not sent** (allows resend if failed) |
| data_state    | VARCHAR(10) | State code when from state or city page (e.g. CA) |
| data_city     | VARCHAR(100)| City slug/name when from city page (state required) |
| data_type     | VARCHAR(50) | Business type when from type page (e.g. Gym) |
| created_at  | TIMESTAMP      | |
| updated_at  | TIMESTAMP      | |

**Sample download and checkout** — front can send:
- **State + city** (city page): `state` and `city` (e.g. `CA`, `costa-mesa`)
- **State only** (state page): `state`
- **Type only** (type page): `type`
- **Neither** (home): full data. If `city` is sent, `state` is required.

```sql
CREATE TABLE downloads (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  type VARCHAR(50) NOT NULL DEFAULT 'sample',
  amount DECIMAL(10,2) NULL,
  stripe_checkout_session_id VARCHAR(255) NULL,
  payment_status VARCHAR(50) NOT NULL DEFAULT 'pending',
  email_sent_at TIMESTAMP NULL,
  data_state VARCHAR(10) NULL,
  data_city VARCHAR(100) NULL,
  data_type VARCHAR(50) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);
```

Or run the plugin migration: `php artisan winter:up`.

**Stripe checkout and callback**

- **Checkout:** Send **name**, **email** and optional **state**, **city** (with state), **type**, or none. **Amount is calculated from row count** for that scope (same tiered pricing as list-page / state-page / city-page). Backend stores `data_state` / `data_city` / `data_type` and creates a Stripe Checkout Session. Response returns the session URL for redirect. **Return URLs:** `success_url` = `{GYMSDATA_FRONTEND_URL}/gymsdata/checkout/success?session_id={CHECKOUT_SESSION_ID}`, `cancel_url` = `{GYMSDATA_FRONTEND_URL}/gymsdata/checkout/cancel`. Set `GYMSDATA_FRONTEND_URL` (e.g. `https://gymdues.com`) in env; falls back to `APP_URL` if unset.
- **Callback:** On `checkout.session.completed`, backend finds the row, sets `payment_status = 'paid'`, then emails an **Excel file** filtered by state+city, state only, type only, or full data, and sets `email_sent_at`. If sending fails, leave `email_sent_at` NULL so you can resend.
- **Resend:** Use `POST /api/v1/gymsdata/resend-purchase-email` with the record `id` (and optional secret) to retry sending for rows where `payment_status = 'paid'` and `email_sent_at` is NULL (or force resend). On success, set `email_sent_at`.