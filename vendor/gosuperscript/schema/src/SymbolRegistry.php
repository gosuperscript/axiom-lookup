<?php

declare(strict_types=1);

namespace Superscript\Schema;

use Superscript\Monads\Option\Option;

use function Psl\Type\dict;
use function Psl\Type\instance_of;
use function Psl\Type\string;

final readonly class SymbolRegistry
{
    /** @var array<string, Source> */
    private array $symbols;

    /**
     * @param array<string, Source|array<string, Source>> $symbols
     */
    public function __construct(array $symbols = [])
    {
        // Transform the array into internal storage format
        $internalSymbols = [];
        
        foreach ($symbols as $key => $value) {
            // If value is a Source, add it without namespace
            if ($value instanceof Source) {
                $internalSymbols[$key] = $value;
            }
            // If value is an array, the key is the namespace
            elseif (is_array($value)) {
                // Validate that the array contains only Sources
                dict(string(), instance_of(Source::class))->assert($value);
                
                foreach ($value as $name => $source) {
                    $namespacedKey = $key . '.' . $name;
                    $internalSymbols[$namespacedKey] = $source;
                }
            } else {
                throw new \InvalidArgumentException(
                    'Symbol values must be either Source instances or arrays of Sources'
                );
            }
        }

        $this->symbols = $internalSymbols;
    }

    /**
     * @return Option<Source>
     */
    public function get(string $name, ?string $namespace = null): Option
    {
        // When namespace is provided, look for it with format "namespace.name"
        if ($namespace !== null) {
            $namespacedKey = $namespace . '.' . $name;
            return Option::from($this->symbols[$namespacedKey] ?? null);
        }

        // When namespace is null, first try exact name match,
        // then fall back to checking if there's a global namespace symbol
        return Option::from($this->symbols[$name] ?? null);
    }
}
