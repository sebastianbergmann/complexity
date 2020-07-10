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
 * @covers \SebastianBergmann\Complexity\ComplexityCollection
 * @covers \SebastianBergmann\Complexity\ComplexityCollectionIterator
 *
 * @uses \SebastianBergmann\Complexity\Complexity
 *
 * @small
 */
final class ComplexityCollectionTest extends TestCase
{
    /**
     * @psalm-var list<Complexity>
     */
    private $array;

    protected function setUp(): void
    {
        $this->array = [
            new Complexity('Class::method', 1),
            new Complexity('function', 2),
        ];
    }

    /**
     * @testdox Can be created from list of Complexity objects
     */
    public function testCanBeCreatedFromListOfObjects(): void
    {
        $collection = ComplexityCollection::fromList($this->array[0], $this->array[1]);

        $this->assertSame($this->array, $collection->asArray());
    }

    public function testCanBeCounted(): void
    {
        $collection = ComplexityCollection::fromList($this->array[0], $this->array[1]);

        $this->assertCount(2, $collection);
        $this->assertFalse($collection->isEmpty());
    }

    public function testCanBeIterated(): void
    {
        $array = [];

        foreach (ComplexityCollection::fromList($this->array[0], $this->array[1]) as $key => $value) {
            $array[$key] = $value;
        }

        $this->assertCount(2, $array);

        $this->assertArrayHasKey(0, $array);
        $this->assertSame($this->array[0], $array[0]);

        $this->assertArrayHasKey(1, $array);
        $this->assertSame($this->array[1], $array[1]);
    }

    public function testHasCyclomaticComplexity(): void
    {
        $collection = ComplexityCollection::fromList($this->array[0], $this->array[1]);

        $this->assertSame(3, $collection->cyclomaticComplexity());
    }
}
