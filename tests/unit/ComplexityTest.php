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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(Complexity::class)]
#[Small]
final class ComplexityTest extends TestCase
{
    public function testHasName(): void
    {
        $this->assertSame('Foo::bar', $this->complexityForMethod()->name());
    }

    public function testHasCyclomaticComplexity(): void
    {
        $this->assertSame(1, $this->complexityForMethod()->cyclomaticComplexity());
    }

    public function testCanBeFunction(): void
    {
        $this->assertTrue($this->complexityForFunction()->isFunction());
        $this->assertFalse($this->complexityForFunction()->isMethod());
    }

    public function testCanBeMethod(): void
    {
        $this->assertTrue($this->complexityForMethod()->isMethod());
        $this->assertFalse($this->complexityForMethod()->isFunction());
    }

    private function complexityForFunction(): Complexity
    {
        return new Complexity('foo', 1);
    }

    private function complexityForMethod(): Complexity
    {
        return new Complexity('Foo::bar', 1);
    }
}
