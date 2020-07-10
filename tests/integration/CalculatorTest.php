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
 * @covers \SebastianBergmann\Complexity\Calculator
 * @covers \SebastianBergmann\Complexity\ComplexityCalculatingVisitor
 * @covers \SebastianBergmann\Complexity\ParentConnectingVisitor
 *
 * @uses \SebastianBergmann\Complexity\Complexity
 * @uses \SebastianBergmann\Complexity\ComplexityCollection
 * @uses \SebastianBergmann\Complexity\ComplexityCollectionIterator
 *
 * @medium
 */
final class CalculatorTest extends TestCase
{
    public function testOne(): void
    {
        $calculator = new Calculator;

        $result = $calculator->calculate(__DIR__ . '/../_fixture/Example.php')->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\Example::method', $result[0]->name());
        $this->assertSame(1, $result[0]->cyclomaticComplexity());
    }
}
