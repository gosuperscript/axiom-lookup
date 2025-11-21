<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Operators;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Operators\InOverloader;

#[CoversClass(InOverloader::class)]
final class InOverloaderTest extends TestCase
{
    #[Test]
    public function it_does_not_support_other_operators(): void
    {
        $overloader = (new InOverloader());

        $this->assertFalse($overloader->supportsOverloading([1, 2, 3], [3, 4, 5], 'has'));
    }
}
