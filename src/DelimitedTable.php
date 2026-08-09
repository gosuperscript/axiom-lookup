<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup;

use InvalidArgumentException;
use LogicException;

/** A serializable description of one typed delimited table. */
final readonly class DelimitedTable
{
    /**
     * @param list<Column> $columns
     */
    public function __construct(
        public string $path,
        public array $columns,
        public string $delimiter = ',',
        public bool $hasHeader = true,
    ) {
        foreach ($columns as $offset => $column) {
            if ($hasHeader && ! is_string($column->identity)) {
                throw new InvalidArgumentException('A table with a header requires named column declarations.');
            }

            if (! $hasHeader && ! is_int($column->identity)) {
                throw new InvalidArgumentException('A table without a header requires positional column declarations.');
            }

            foreach (array_slice($columns, 0, $offset) as $previous) {
                if ($previous->identity === $column->identity) {
                    throw new InvalidArgumentException(sprintf(
                        'Column [%s] is declared more than once.',
                        $column->identity,
                    ));
                }
            }
        }
    }

    public function declaration(string|int $identity): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->identity === $identity) {
                return $column;
            }
        }

        return null;
    }

    /** @internal Compilation guarantees this precondition before evaluation. */
    public function requireDeclaration(string|int $identity): Column
    {
        $column = $this->declaration($identity);

        if ($column === null) {
            throw new LogicException(sprintf('Compiled column [%s] is missing.', $identity));
        }

        return $column;
    }
}
