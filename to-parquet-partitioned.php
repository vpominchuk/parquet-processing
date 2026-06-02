<?php

declare(strict_types=1);

use App\FieldMapper;
use App\PartitionedParquetExporter;
use App\ResourceLimiter;
use Saturio\DuckDB\DuckDB;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/config.php';

$options = getopt('', ['chunk-size:']);

$chunkSize = (int) ($options['chunk-size'] ?? DEFAULT_CHUNK_SIZE);
if ($chunkSize <= 0) {
    fwrite(STDERR, "Usage: php to-parquet-partitioned.php [--chunk-size 50000]\n");
    exit(1);
}

if (!is_file(DATA_FILE)) {
    fwrite(STDERR, 'Source file not found: ' . DATA_FILE . "\n");
    exit(1);
}

if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0777, true) && !is_dir(OUTPUT_DIR)) {
    fwrite(STDERR, 'Unable to create output directory: ' . OUTPUT_DIR . "\n");
    exit(1);
}

$mapper = new FieldMapper(readOriginalFields(DATA_FILE));
file_put_contents(SCHEMA_FILE, json_encode($mapper->schema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

@mkdir(TEMP_DIR, 0777, true);
// In-memory database: the single-pass COPY writes the dataset only once, to the
// parquet output, spilling to TEMP_DIR if it exceeds the memory limit.
$db = DuckDB::create();
(new ResourceLimiter(IMPORT_THREADS, IMPORT_MEMORY_LIMIT, TEMP_DIR))->applyTo($db);

$start = microtime(true);
$result = (new PartitionedParquetExporter(
    $db,
    $mapper,
    DATA_FILE,
    OUTPUT_DIR,
    $chunkSize,
))->export();

unset($db);
@rmdir(TEMP_DIR);

printf(
    "Wrote %s rows into %d partition(s) of %s under %s/chunk=*/ (%.1fs)\nSchema mapping: %s\n",
    number_format($result['rows']),
    $result['chunks'],
    number_format($chunkSize),
    basename(OUTPUT_DIR),
    microtime(true) - $start,
    SCHEMA_FILE,
);

/**
 * Read the first NDJSON record to recover the original field names, in order.
 *
 * @return string[]
 */
function readOriginalFields(string $path): array
{
    $handle = fopen($path, 'rb');
    $line = $handle ? fgets($handle) : false;
    if ($handle) {
        fclose($handle);
    }

    if ($line === false) {
        fwrite(STDERR, "Source file is empty.\n");
        exit(1);
    }

    return array_keys((array) json_decode($line, true, flags: JSON_THROW_ON_ERROR));
}
