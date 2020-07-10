<?php declare(strict_types=1);
/*
 * This file is part of sebastian/complexity.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Complexity;

use PHPUnit\Framework\TestCase;

/**
 * @covers \SebastianBergmann\Complexity\Complexity
 *
 * @small
 */
final class ComplexityTest extends TestCase
{
    public function testHasName(): void
    {
        $this->assertSame('Foo::bar', $this->complexity()->name());
    }

    public function testHasCyclomaticComplexity(): void
    {
        $this->assertSame(1, $this->complexity()->cyclomaticComplexity());
    }

    private function complexity(): Complexity
    {
        return new Complexity('Foo::bar', 1);
    }
}
