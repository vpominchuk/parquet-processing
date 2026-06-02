<?php

declare(strict_types=1);

namespace App;

/**
 * Maps the original data.json field names onto generic parquet column names
 * (field_1, field_2, ...), preserving their original order.
 */
final class FieldMapper
{
    /** @var array<string, string> generic name => original name */
    private array $map = [];

    /**
     * @param string[] $originalFields original field names, in order
     */
    public function __construct(array $originalFields)
    {
        $position = 1;
        foreach ($originalFields as $original) {
            $this->map['field_' . $position] = $original;
            $position++;
        }
    }

    /**
     * @return array<string, string> generic name => original name
     */
    public function map(): array
    {
        return $this->map;
    }

    /**
     * The schema written to disk. Only the public field mapping is exposed; no
     * internal/technical fields, since the file is served publicly.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'fields' => $this->map,
        ];
    }
}
