<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Superscript\Schema\Operators\BinaryOverloader;
use Superscript\Schema\Operators\DefaultOverloader;
use Superscript\Schema\Operators\OverloaderManager;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(OverloaderManager::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
class OverloaderManagerTest extends TestCase
{
    #[Test]
    public function it_asserts_all_overloaders_are_instance_of_interface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OverloaderManager([new stdClass()]);
    }

    #[Test]
    public function it_evaluates_an_expression_if_an_overloader_is_found(): void
    {
        $manager = new OverloaderManager([
            new DefaultOverloader(),
        ]);

        $this->assertTrue($manager->supportsOverloading(1, 1, '+'));
        $this->assertEquals(2, $manager->evaluate(1, 1, '+'));
    }

    #[Test]
    public function it_returns_an_error_if_no_supported_overloader_is_found(): void
    {
        $manager = new OverloaderManager([]);
        $this->assertFalse($manager->supportsOverloading(1, 1, '+'));
        $this->expectExceptionMessage('No overloader found for [1] + [1]');
        $manager->evaluate(1, 1, '+');
    }
}
