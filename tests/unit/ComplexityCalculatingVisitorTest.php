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
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComplexityCalculatingVisitor::class)]
#[UsesClass(Complexity::class)]
#[UsesClass(ComplexityCollection::class)]
#[UsesClass(ComplexityCollectionIterator::class)]
#[UsesClass(CyclomaticComplexityCalculatingVisitor::class)]
#[Small]
final class ComplexityCalculatingVisitorTest extends TestCase
{
    public static function shortCircuitTraversalProvider(): array
    {
        return [
            'short-circuit traversal'    => [true],
            'no short-circuit traversal' => [false],
        ];
    }

    #[DataProvider('shortCircuitTraversalProvider')]
    public function testCalculatesComplexityForAbstractSyntaxTree(bool $shortCircuitTraversal): void
    {
        $nodes = $this->parser()->parse(
            file_get_contents(__DIR__ . '/../_fixture/ExampleClass.php')
        );

        $traverser = new NodeTraverser;

        $complexityCalculatingVisitor = new ComplexityCalculatingVisitor($shortCircuitTraversal);

        $shortCircuitVisitor = new class extends NodeVisitorAbstract
        {
            private int $numberOfNodesVisited = 0;

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

    #[DataProvider('shortCircuitTraversalProvider')]
    public function testCalculatesComplexityForAbstractSyntaxTreeInterface(bool $shortCircuitTraversal): void
    {
        $nodes = $this->parser()->parse(
            file_get_contents(__DIR__ . '/../_fixture/ExampleInterface.php')
        );

        $traverser = new NodeTraverser;

        $complexityCalculatingVisitor = new ComplexityCalculatingVisitor($shortCircuitTraversal);

        $shortCircuitVisitor = new class extends NodeVisitorAbstract
        {
            private int $numberOfNodesVisited = 0;

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

        $this->assertSame(0, $complexityCalculatingVisitor->result()->cyclomaticComplexity());
        $this->assertSame(11, $shortCircuitVisitor->numberOfNodesVisited());
    }

    private function parser(): Parser
    {
        return (new ParserFactory)->create(ParserFactory::PREFER_PHP7, new Lexer);
    }
}
