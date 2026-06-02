<?php

declare(strict_types=1);

namespace App;

use Saturio\DuckDB\DuckDB;

/**
 * Applies per-process resource limits to a DuckDB connection so several
 * pipeline instances can share one VM without each grabbing all of its cores
 * and memory. Any limit left null is untouched, leaving DuckDB's own default.
 */
final class ResourceLimiter
{
    public function __construct(
        private readonly ?int $threads = null,
        private readonly ?string $memoryLimit = null,
        private readonly ?string $tempDirectory = null,
    ) {
    }

    public function applyTo(DuckDB $db): void
    {
        if ($this->threads !== null) {
            $db->query(sprintf('SET threads = %d', $this->threads));
        }
        if ($this->memoryLimit !== null) {
            $db->query(sprintf('SET memory_limit = %s', $this->literal($this->memoryLimit)));
        }
        if ($this->tempDirectory !== null) {
            $db->query(sprintf('SET temp_directory = %s', $this->literal($this->tempDirectory)));
        }
    }

    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
