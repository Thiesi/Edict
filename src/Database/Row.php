<?php

declare(strict_types=1);

namespace Edict\Database;

/**
 * Typed access to a fetched database row. A missing column or an unexpected
 * type is a programming error and throws instead of propagating bad data.
 */
final class Row
{
    /**
     * @param array<mixed> $row
     */
    public static function str(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Column $column is not a string");
        }

        return $value;
    }

    /**
     * @param array<mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new \UnexpectedValueException("Column $column is not an integer");
    }

    /**
     * @param array<mixed> $row
     */
    public static function bool(array $row, string $column): bool
    {
        $value = $row[$column] ?? null;
        if (!is_bool($value)) {
            throw new \UnexpectedValueException("Column $column is not a boolean");
        }

        return $value;
    }
}
