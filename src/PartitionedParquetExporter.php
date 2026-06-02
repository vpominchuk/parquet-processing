<?php

declare(strict_types=1);

namespace App;

use Saturio\DuckDB\DuckDB;

/**
 * Single-pass variant: streams data.json straight to partitioned parquet in one
 * COPY, with no intermediate staging table. DuckDB routes rows into fixed-size
 * row chunks via a computed `chunk` partition column, so the dataset is written
 * once (lower disk + spill pressure than the staging approach).
 *
 * Output layout is hive-style: <outputDir>/chunk=<n>/data_0.parquet
 */
final class PartitionedParquetExporter
{
    public function __construct(
        private readonly DuckDB $db,
        private readonly FieldMapper $mapper,
        private readonly string $sourceFile,
        private readonly string $outputDir,
        private readonly int $chunkSize,
    ) {
    }

    /**
     * @return array{rows: int, chunks: int}
     */
    public function export(): array
    {
        $rows = $this->copy();

        return ['rows' => $rows, 'chunks' => $this->countPartitions()];
    }

    /**
     * @return int number of rows written, as reported by COPY
     */
    private function copy(): int
    {
        $result = $this->db->query(sprintf(
            'COPY (%s) TO %s (FORMAT PARQUET, PARTITION_BY (chunk), COMPRESSION ZSTD, OVERWRITE_OR_IGNORE)',
            $this->select(),
            $this->literal($this->outputDir),
        ));

        foreach ($result->rows(true) as $row) {
            return (int) ($row['Count'] ?? 0);
        }

        return 0;
    }

    /**
     * Assign the auto-increment id and generic column names, then derive the
     * zero-based chunk each row belongs to from that id.
     */
    private function select(): string
    {
        $columns = ['CAST(row_number() OVER () AS BIGINT) AS id'];
        foreach ($this->mapper->map() as $generic => $original) {
            $columns[] = sprintf('%s AS %s', $this->quote($original), $generic);
        }

        // Integer division (//): DuckDB's "/" is float division and CAST(...AS
        // INTEGER) rounds, which would smear rows across the wrong partitions.
        return sprintf(
            'SELECT *, CAST((id - 1) // %d AS INTEGER) AS chunk FROM (SELECT %s FROM read_json_auto(%s))',
            $this->chunkSize,
            implode(', ', $columns),
            $this->literal($this->sourceFile),
        );
    }

    private function countPartitions(): int
    {
        return count(glob($this->outputDir . '/chunk=*', GLOB_ONLYDIR) ?: []);
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
