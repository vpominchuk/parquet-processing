<?php

declare(strict_types=1);

use App\NdjsonBatchWriter;
use App\ProductCardFactory;
use Faker\Factory as FakerFactory;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/config.php';

$options = getopt('', ['count:']);

$count = (int) ($options['count'] ?? 0);
if ($count <= 0) {
    fwrite(STDERR, "Usage: php create-ndjson.php --count <number-of-products>\n");
    exit(1);
}

$factory = new ProductCardFactory(FakerFactory::create());
$writer = new NdjsonBatchWriter(DATA_FILE, NDJSON_BATCH_SIZE);

$start = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $writer->write($factory->create());
}
$writer->close();

printf(
    "Generated %s products into %s in %.1fs\n",
    number_format($count),
    DATA_FILE,
    microtime(true) - $start
);
