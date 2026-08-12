<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Tests\Support\Filters;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Lookup\CsvRecord;
use Superscript\Axiom\Lookup\Support\Filters\ResolvedFilter;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Ok;

/**
 * The probe pairing pinned directly: eligibility (a probe column bound at
 * compile time) and a raw string value must BOTH hold before an indexed
 * reader may seek anything — losing either half would let a probe narrow
 * by a value the dialect never promised byte equality for.
 */
#[CoversClass(ResolvedFilter::class)]
#[UsesClass(CsvRecord::class)]
final class ResolvedFilterTest extends TestCase
{
    private function filter(mixed $value, string|int|null $probeColumn): ResolvedFilter
    {
        return new ResolvedFilter(
            $value,
            fn(CsvRecord $record, mixed $resolved): Result => Ok(true),
            $probeColumn,
        );
    }

    #[Test]
    public function a_probe_column_and_a_string_value_make_a_probe(): void
    {
        $this->assertSame(['name', 'Alice'], $this->filter('Alice', 'name')->probe());
    }

    #[Test]
    public function a_positional_probe_column_keeps_its_position(): void
    {
        $this->assertSame([0, 'Alice'], $this->filter('Alice', 0)->probe());
    }

    #[Test]
    public function no_probe_column_means_no_probe_even_for_a_string_value(): void
    {
        $this->assertNull($this->filter('Alice', null)->probe());
    }

    #[Test]
    public function a_non_string_value_means_no_probe_even_on_an_eligible_column(): void
    {
        $this->assertNull($this->filter(7, 'name')->probe());
        $this->assertNull($this->filter(null, 'name')->probe());
    }

    #[Test]
    public function matching_hands_the_record_and_value_to_the_bound_predicate(): void
    {
        $seen = [];
        $filter = new ResolvedFilter(
            'Alice',
            function (CsvRecord $record, mixed $resolved) use (&$seen): Result {
                $seen = [$record->get('name'), $resolved];

                return Ok(true);
            },
            'name',
        );

        $result = $filter->matches(CsvRecord::from(['name' => 'Bob']));

        $this->assertTrue($result->unwrap());
        $this->assertSame(['Bob', 'Alice'], $seen);
    }
}
