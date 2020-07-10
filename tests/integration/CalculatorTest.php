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

use function file_get_contents;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SebastianBergmann\Complexity\Calculator
 * @covers \SebastianBergmann\Complexity\ComplexityCalculatingVisitor
 * @covers \SebastianBergmann\Complexity\CyclomaticComplexityCalculatingVisitor
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
    public function testCalculatesCyclomaticComplexityOfClassMethodInSourceFile(): void
    {
        $result = (new Calculator)->calculateForSourceFile(__DIR__ . '/../_fixture/ExampleClass.php')->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\ExampleClass::method', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }

    public function testCalculatesCyclomaticComplexityOfTraitMethodInSourceFile(): void
    {
        $result = (new Calculator)->calculateForSourceFile(__DIR__ . '/../_fixture/ExampleTrait.php')->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\ExampleTrait::method', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }

    public function testCalculatesCyclomaticComplexityOfFunctionInSourceFile(): void
    {
        $result = (new Calculator)->calculateForSourceFile(__DIR__ . '/../_fixture/example_function.php')->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\example_function', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }

    public function testCalculatesCyclomaticComplexityOfClassMethodInSourceString(): void
    {
        $result = (new Calculator)->calculateForSourceString(file_get_contents(__DIR__ . '/../_fixture/ExampleClass.php'))->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\ExampleClass::method', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }

    public function testCalculatesCyclomaticComplexityOfTraitMethodInSourceString(): void
    {
        $result = (new Calculator)->calculateForSourceString(file_get_contents(__DIR__ . '/../_fixture/ExampleTrait.php'))->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\ExampleTrait::method', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }

    public function testCalculatesCyclomaticComplexityOfFunctionInSourceString(): void
    {
        $result = (new Calculator)->calculateForSourceString(file_get_contents(__DIR__ . '/../_fixture/example_function.php'))->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\example_function', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }
}
