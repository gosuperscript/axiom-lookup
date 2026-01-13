<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use function Psl\Iter\first;

/**
 * Value object representing a single CSV record with type-safe accessors
 */
final readonly class CsvRecord
{
    /**
     * @param array<string|int, mixed> $data
     */
    private function __construct(
        private array $data,
    ) {}

    /**
     * @param array<string|int, mixed> $data
     */
    public static function from(array $data): self
    {
        return new self($data);
    }

    /**
     * Get a value as a string, or null if not present/not convertible
     */
    public function getString(string|int $column): ?string
    {
        $value = $this->data[$column] ?? null;
        
        if ($value === null) {
            return null;
        }
        
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Get a value as a float, or null if not present/not numeric
     */
    public function getNumeric(string|int $column): ?float
    {
        $value = $this->data[$column] ?? null;
        
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        
        return (float) $value;
    }

    /**
     * Get a raw value for comparison
     */
    public function get(string|int $column): mixed
    {
        return $this->data[$column] ?? null;
    }

    /**
     * Check if column exists
     */
    public function has(string|int $column): bool
    {
        return isset($this->data[$column]);
    }

    /**
     * Extract specific columns
     * @param array<string|int>|string|int $columns
     */
    public function extract(array|string|int $columns): mixed
    {
        if (empty($columns)) {
            return $this->data;
        }
        
        if (is_string($columns) || is_int($columns)) {
            return $this->data[$columns] ?? null;
        }

        if (count($columns) === 1) {
            return $this->data[first($columns)] ?? null;
        }
        
        $result = [];
        foreach ($columns as $column) {
            $result[$column] = $this->data[$column] ?? null;
        }
        
        return $result;
    }

    /**
     * Get all data
     * @return array<string|int, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
