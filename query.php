<?php

declare(strict_types=1);

use App\ParquetQuery;
use App\ResourceLimiter;
use Saturio\DuckDB\DuckDB;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/config.php';

$options = getopt('', ['rows-per-page:', 'page:', 'order-by:']);

$rowsPerPage = (int) ($options['rows-per-page'] ?? DEFAULT_ROWS_PER_PAGE);
$page = (int) ($options['page'] ?? DEFAULT_PAGE);
$orderBy = $options['order-by'] ?? null;

if ($rowsPerPage <= 0 || $page <= 0) {
    fwrite(STDERR, "Usage: php query.php [--rows-per-page 10] [--page 1] [--order-by field_1,field_3]\n");
    exit(1);
}

try {
    $started = microtime(true);
    $db = DuckDB::create();
    (new ResourceLimiter(QUERY_THREADS, QUERY_MEMORY_LIMIT))->applyTo($db);
    $query = new ParquetQuery($db, PARQUET_GLOB);

    $countStarted = microtime(true);
    $total = $query->total();
    $countMs = elapsedMs($countStarted);

    $fetchStarted = microtime(true);
    $data = iterator_to_array($query->page($page, $rowsPerPage, $orderBy), false);
    $fetchMs = elapsedMs($fetchStarted);

    $files = $query->fileCount();

    echo json_encode([
        'page' => $page,
        'rows_per_page' => $rowsPerPage,
        'count' => [
            'total_rows' => $total,
            'returned' => count($data),
            'total_pages' => (int) ceil($total / $rowsPerPage),
        ],
        'order_by' => $orderBy ?? 'id',
        'data' => $data,
        'stats' => [
            'elapsed_ms' => elapsedMs($started),
            'count_ms' => $countMs,
            'fetch_ms' => $fetchMs,
            'files_in_dataset' => $files,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Query failed: ' . $e->getMessage() . "\n");
    exit(1);
}

function elapsedMs(float $since): float
{
    return round((microtime(true) - $since) * 1000, 2);
}
