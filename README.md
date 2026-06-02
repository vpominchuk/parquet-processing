# Product Card Pipeline

A small PHP pipeline that **generates** synthetic online-store product cards,
**imports** them into partitioned Parquet via DuckDB, and **queries** the result
with paging and ordering.

```
create-ndjson.php  ──▶  data.json        (NDJSON, one product per line)
        │
to-parquet-partitioned.php  ──▶  data/chunk=<n>/data_0.parquet  +  data/schema.json
        │
   query.php  ──▶  JSON page + query stats
```

## Requirements

- **PHP 8.1+** with the **FFI** extension enabled (`ext-ffi`) — DuckDB is loaded via FFI.
- **Composer**

## Install

```bash
composer install
```

This pulls two dependencies and, via the `post-install-cmd` hook, downloads the
native DuckDB library for your platform:

- `fakerphp/faker` — generates the product data
- `satur.io/duckdb-auto` — DuckDB FFI binding + native lib installer

## The flow

### 1. Generate — `create-ndjson.php`

Writes `data.json` (NDJSON) with the requested number of product cards. Rows are
batched in memory and flushed in groups, so memory stays flat even at tens of
millions of rows.

```bash
php create-ndjson.php --count 3000000
```

| Option | Description | Default |
| --- | --- | --- |
| `--count <n>` | Number of products to generate | required |

Each line is a product card: `id` (UUID), `sku`, `title`, `brand`, `category`,
`description`, `price`, `sale_price`, `currency`, `color`, `condition`,
`in_stock`, `stock_quantity`, `rating`, `review_count`, `image_url`,
`created_at`.

### 2. Import — `to-parquet-partitioned.php`

Reads `data.json` and writes partitioned Parquet under `data/` in a single
streaming DuckDB `COPY` pass (no intermediate staging table). It:

- adds a synthetic auto-increment **`id`** column (`BIGINT`, `1..N`),
- renames the original fields to generic **`field_1 … field_N`**,
- partitions rows into fixed-size chunks (`chunk` column / hive directories),
- compresses output with **ZSTD**,
- writes the field mapping to `data/schema.json`.

```bash
php to-parquet-partitioned.php --chunk-size 50000
```

| Option | Description | Default |
| --- | --- | --- |
| `--chunk-size <n>` | Rows per partition | 50000 |

Output layout:

```
data/
├── chunk=0/data_0.parquet     # ids 1 .. chunk_size
├── chunk=1/data_0.parquet     # ids chunk_size+1 .. 2*chunk_size
├── ...
└── schema.json                # { "fields": { "field_1": "id", ... } }
```

Final Parquet columns: `id`, `field_1 … field_N`, `chunk`.

### 3. Query — `query.php`

Reads the Parquet dataset (`data/**/*.parquet`) with paging and ordering, and
prints a JSON envelope plus query statistics.

```bash
php query.php --rows-per-page 10 --page 1 --order-by field_1,field_3
```

| Option | Description | Default |
| --- | --- | --- |
| `--rows-per-page <n>` | Page size | 10 |
| `--page <n>` | 1-based page number | 1 |
| `--order-by <cols>` | Comma-separated columns, optional `:desc` (e.g. `field_7:desc`) | `id` |

Output (truncated):

```json
{
    "page": 1,
    "rows_per_page": 10,
    "count": {
        "total_rows": 3000000,
        "returned": 10,
        "total_pages": 300000
    },
    "order_by": "field_1,field_3",
    "data": [ { "id": 1, "field_1": "…", ... } ],
    "stats": {
        "elapsed_ms": 115.16,
        "count_ms": 9.42,
        "fetch_ms": 47.59,
        "files_in_dataset": 60,
        "peak_memory_mb": 2
    }
}
```

`--order-by` columns are validated against the actual Parquet columns, so unknown
columns fail fast (`Query failed: Unknown order-by column: …`).

## Resource limits

Both the importer and the query CLI cap DuckDB's threads and memory per process,
so multiple instances can share one VM without each grabbing all of its cores
and memory. Limits live in `config/config.php` and are applied to the connection
by `App\ResourceLimiter`:

| Constant | Default | Applies to |
| --- | --- | --- |
| `IMPORT_THREADS` | `1` | importer |
| `IMPORT_MEMORY_LIMIT` | `256MB` | importer (spills to `data/.tmp`) |
| `QUERY_THREADS` | `2` | query CLI |
| `QUERY_MEMORY_LIMIT` | `512MB` | query CLI |

The import is sort/write-heavy, so it is capped tightly and spills to disk; the
query path reads in parallel, so it gets more threads under a bounded ceiling.
If you hit out-of-memory errors on very deep `--order-by` paging, raise
`QUERY_MEMORY_LIMIT` (or pass a temp directory to `ResourceLimiter` so big sorts
can spill).

## End-to-end example

```bash
composer install
php create-ndjson.php --count 3000000
php to-parquet-partitioned.php --chunk-size 50000
php query.php --rows-per-page 10 --page 1 --order-by field_7:desc
```
