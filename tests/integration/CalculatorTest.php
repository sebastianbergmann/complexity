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
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SebastianBergmann\Complexity\Calculator
 * @covers \SebastianBergmann\Complexity\ComplexityCalculatingVisitor
 * @covers \SebastianBergmann\Complexity\CyclomaticComplexityCalculatingVisitor
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

    public function testCalculatesCyclomaticComplexityInSourceString(): void
    {
        $result = (new Calculator)->calculateForSourceString(file_get_contents(__DIR__ . '/../_fixture/ExampleClass.php'))->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\ExampleClass::method', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }

    public function testCalculatesCyclomaticComplexityInAbstractSyntaxTree(): void
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse(file_get_contents(__DIR__ . '/../_fixture/ExampleClass.php'));

        assert($nodes !== null);

        $result = (new Calculator)->calculateForAbstractSyntaxTree($nodes)->asArray();

        $this->assertSame('SebastianBergmann\Complexity\TestFixture\ExampleClass::method', $result[0]->name());
        $this->assertSame(14, $result[0]->cyclomaticComplexity());
    }
}
