<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use Saturio\DuckDB\DuckDB;

/**
 * Reads the exported parquet dataset with paging and ordering.
 */
final class ParquetQuery
{
    /** @var string[]|null */
    private ?array $columns = null;

    public function __construct(
        private readonly DuckDB $db,
        private readonly string $source,
    ) {
    }

    /**
     * @return string[] available column names
     */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $columns = [];
        $sql = sprintf('DESCRIBE SELECT * FROM read_parquet(%s)', $this->literal($this->source));
        foreach ($this->db->query($sql)->rows(true) as $row) {
            $columns[] = $row['column_name'];
        }

        return $this->columns = $columns;
    }

    public function total(): int
    {
        $sql = sprintf('SELECT count(*) FROM read_parquet(%s)', $this->literal($this->source));
        foreach ($this->db->query($sql)->rows() as $row) {
            return (int) $row[0];
        }

        return 0;
    }

    /**
     * Number of parquet files in the dataset. Uses the glob() table function so
     * it only lists paths, without scanning file contents.
     */
    public function fileCount(): int
    {
        $sql = sprintf('SELECT count(*) FROM glob(%s)', $this->literal($this->source));
        foreach ($this->db->query($sql)->rows() as $row) {
            return (int) $row[0];
        }

        return 0;
    }

    /**
     * @return iterable<array<string, mixed>> rows of the requested page
     */
    public function page(int $page, int $rowsPerPage, ?string $orderBy): iterable
    {
        $offset = ($page - 1) * $rowsPerPage;
        $order = $this->parseOrderBy($orderBy);

        // Ordering by id ascending (or nothing) is equivalent to the natural
        // row sequence, so it can use the cheap id-range path.
        return $this->isIdAscending($order)
            ? $this->pageByIdRange($offset, $rowsPerPage)
            : $this->pageByOrder($offset, $rowsPerPage, $order);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $order
     */
    private function isIdAscending(array $order): bool
    {
        return $order === [] || $order === [['id', 'ASC']];
    }

    /**
     * Cheap default paging: the synthetic id is contiguous (1..N), so a page
     * maps straight to an id range. DuckDB then skips non-overlapping parquet
     * files using their row-group min/max stats, rather than reading and
     * discarding $offset rows as OFFSET would.
     *
     * @return iterable<array<string, mixed>>
     */
    private function pageByIdRange(int $offset, int $rowsPerPage): iterable
    {
        $sql = sprintf(
            'SELECT * FROM read_parquet(%s) WHERE id BETWEEN %d AND %d ORDER BY id',
            $this->literal($this->source),
            $offset + 1,
            $offset + $rowsPerPage,
        );

        yield from $this->db->query($sql)->rows(true);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $order non-empty parsed pairs
     *
     * @return iterable<array<string, mixed>>
     */
    private function pageByOrder(int $offset, int $rowsPerPage, array $order): iterable
    {
        $sql = sprintf(
            'SELECT * FROM read_parquet(%s)%s LIMIT %d OFFSET %d',
            $this->literal($this->source),
            $this->orderClause($order),
            $rowsPerPage,
            $offset,
        );

        yield from $this->db->query($sql)->rows(true);
    }

    /**
     * Parse and validate a comma-separated spec such as "field_1,field_3" or
     * "field_1:desc,field_3" into [column, direction] pairs. Throws on unknown
     * columns or directions.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function parseOrderBy(?string $orderBy): array
    {
        $spec = trim((string) $orderBy);
        if ($spec === '') {
            return [];
        }

        $allowed = array_flip($this->columns());

        $parsed = [];
        foreach (explode(',', $spec) as $item) {
            $tokens = preg_split('/[:\s]+/', trim($item), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === []) {
                continue;
            }

            [$column, $direction] = [$tokens[0], strtoupper($tokens[1] ?? 'ASC')];
            if (!isset($allowed[$column])) {
                throw new InvalidArgumentException("Unknown order-by column: {$column}");
            }
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException("Invalid sort direction: {$direction}");
            }

            $parsed[] = [$column, $direction];
        }

        return $parsed;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $order
     */
    private function orderClause(array $order): string
    {
        if ($order === []) {
            return '';
        }

        $parts = [];
        foreach ($order as [$column, $direction]) {
            $parts[] = $this->quote($column) . ' ' . $direction;
        }

        return ' ORDER BY ' . implode(', ', $parts);
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
