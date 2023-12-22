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
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SebastianBergmann\Complexity\ComplexityCalculatingVisitor
 *
 * @uses \SebastianBergmann\Complexity\Complexity
 * @uses \SebastianBergmann\Complexity\ComplexityCollection
 * @uses \SebastianBergmann\Complexity\ComplexityCollectionIterator
 * @uses \SebastianBergmann\Complexity\CyclomaticComplexityCalculatingVisitor
 *
 * @small
 */
final class ComplexityCalculatingVisitorTest extends TestCase
{
    /**
     * @dataProvider shortCircuitTraversalProvider
     */
    public function testCalculatesComplexityForAbstractSyntaxTree(bool $shortCircuitTraversal): void
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse(
            file_get_contents(__DIR__ . '/../_fixture/ExampleClass.php')
        );

        $traverser = new NodeTraverser;

        $complexityCalculatingVisitor = new ComplexityCalculatingVisitor($shortCircuitTraversal);

        $shortCircuitVisitor              = new class extends NodeVisitorAbstract {
            private $numberOfNodesVisited = 0;

            public function enterNode(Node $node): void
            {
                $this->numberOfNodesVisited++;
            }

            public function numberOfNodesVisited(): int
            {
                return $this->numberOfNodesVisited;
            }
        };

        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor(new ParentConnectingVisitor);
        $traverser->addVisitor($complexityCalculatingVisitor);
        $traverser->addVisitor($shortCircuitVisitor);

        /* @noinspection UnusedFunctionResultInspection */
        $traverser->traverse($nodes);

        $this->assertSame(14, $complexityCalculatingVisitor->result()->cyclomaticComplexity());

        if ($shortCircuitTraversal) {
            $this->assertSame(9, $shortCircuitVisitor->numberOfNodesVisited());
        } else {
            $this->assertSame(70, $shortCircuitVisitor->numberOfNodesVisited());
        }
    }

    public function shortCircuitTraversalProvider(): array
    {
        return [
            'short-circuit traversal'    => [true],
            'no short-circuit traversal' => [false],
        ];
    }
}
