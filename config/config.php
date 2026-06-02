<?php

declare(strict_types=1);

// Shared configuration for the create → import → query pipeline.
// Paths are anchored to the project root (the parent of this config/ dir).

define('ROOT_DIR', dirname(__DIR__));

// Generated NDJSON: written by create-ndjson.php, read by the importer.
const DATA_FILE = ROOT_DIR . '/data.json';

// Parquet dataset produced by the importer.
const OUTPUT_DIR = ROOT_DIR . '/data';
const SCHEMA_FILE = OUTPUT_DIR . '/schema.json';
const TEMP_DIR = OUTPUT_DIR . '/.tmp';
const PARQUET_GLOB = OUTPUT_DIR . '/**/*.parquet';

// Generation.
const NDJSON_BATCH_SIZE = 10000;

// Import.
const DEFAULT_CHUNK_SIZE = 50000;

// Query.
const DEFAULT_ROWS_PER_PAGE = 10;
const DEFAULT_PAGE = 1;

// Per-process resource limits, so multiple pipeline instances can share one VM
// without contending for all of its cores and memory. Import sorts and writes
// the whole dataset, so it is capped tightly and spills to TEMP_DIR; queries
// read in parallel, so they get more threads under a bounded memory ceiling.
const IMPORT_THREADS = 1;
const IMPORT_MEMORY_LIMIT = '256MB';
const QUERY_THREADS = 2;
const QUERY_MEMORY_LIMIT = '512MB';
