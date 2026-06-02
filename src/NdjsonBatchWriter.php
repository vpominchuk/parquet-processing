<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Writes records as NDJSON, flushing to disk in batches to keep memory flat.
 */
final class NdjsonBatchWriter
{
    /** @var resource */
    private $handle;

    /** @var string[] */
    private array $buffer = [];

    public function __construct(string $path, private readonly int $batchSize = 10000)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open file for writing: {$path}");
        }

        $this->handle = $handle;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function write(array $record): void
    {
        $this->buffer[] = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function close(): void
    {
        $this->flush();
        fclose($this->handle);
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $chunk = implode("\n", $this->buffer) . "\n";
        if (fwrite($this->handle, $chunk) === false) {
            throw new RuntimeException('Failed writing batch to file.');
        }

        $this->buffer = [];
    }
}
