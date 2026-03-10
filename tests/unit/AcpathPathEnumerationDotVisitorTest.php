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

use function array_unique;
use function assert;
use function count;
use function preg_match_all;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcpathPathEnumerationDotVisitor::class)]
#[CoversClass(AcpathControlFlowGraph::class)]
#[CoversClass(AcpathCalculator::class)]
#[Small]
final class AcpathPathEnumerationDotVisitorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function provider(): array
    {
        return [
            'linear code' => [
                '<?php function f() { $a = 1; $b = 2; }',
                1,
            ],
            'single if' => [
                '<?php function f($x) { if ($x) { $a = 1; } }',
                2,
            ],
            'if/else' => [
                '<?php function f($x) { if ($x) { $a = 1; } else { $a = 2; } }',
                2,
            ],
            'sequential ifs' => [
                '<?php function f($x, $y) { if ($x) { $a = 1; } if ($y) { $b = 1; } }',
                4,
            ],
            'return in if' => [
                '<?php function f($x) { if ($x) { return; } $a = 1; }',
                2,
            ],
            'nested if' => [
                '<?php function f($x, $y) { if ($x) { if ($y) { $a = 1; } } }',
                3,
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testPathCountMatchesAcpath(string $source, int $expectedAcpath): void
    {
        $stmts = $this->parseFunction($source);

        $calculator = new AcpathCalculator;
        $acpath     = $calculator->calculate($stmts);

        $this->assertSame($expectedAcpath, $acpath);

        $dot = (new AcpathPathEnumerationDotVisitor)->generate($stmts);

        // Count paths in legend
        preg_match_all('/Path \d+:/', $dot, $matches);
        $pathCount = count($matches[0]);

        $this->assertSame($expectedAcpath, $pathCount);
    }

    public function testGeneratesValidDotWithLegend(): void
    {
        $stmts = $this->parseFunction('<?php function f($x) { if ($x) { $a = 1; } else { $a = 2; } }');
        $dot   = (new AcpathPathEnumerationDotVisitor)->generate($stmts);

        $this->assertStringContainsString('digraph cfg {', $dot);
        $this->assertStringContainsString('cluster_legend', $dot);
        $this->assertStringContainsString('label="Paths"', $dot);
    }

    public function testEdgesHaveColors(): void
    {
        $stmts = $this->parseFunction('<?php function f($x) { if ($x) { $a = 1; } }');
        $dot   = (new AcpathPathEnumerationDotVisitor)->generate($stmts);

        $this->assertStringContainsString('color=', $dot);
        $this->assertStringContainsString('penwidth=', $dot);
    }

    public function testWhileLoopSkipsDashedBackEdges(): void
    {
        $stmts = $this->parseFunction('<?php function f($x) { while ($x) { $a = 1; } }');
        $dot   = (new AcpathPathEnumerationDotVisitor)->generate($stmts);

        $this->assertStringContainsString('shape=diamond', $dot);
        $this->assertStringContainsString('style=dashed', $dot);
        $this->assertStringContainsString('Path 1:', $dot);
    }

    public function testAllPathsAreEnumerated(): void
    {
        // 7 sequential ifs = 2^7 = 128 paths
        $source = '<?php function f($a,$b,$c,$d,$e,$f,$g) { '
            . 'if ($a) { $x=1; } if ($b) { $x=1; } if ($c) { $x=1; } '
            . 'if ($d) { $x=1; } if ($e) { $x=1; } if ($f) { $x=1; } '
            . 'if ($g) { $x=1; } }';
        $stmts = $this->parseFunction($source);
        $dot   = (new AcpathPathEnumerationDotVisitor)->generate($stmts);

        $this->assertStringContainsString('ACPATH paths: 128', $dot);

        preg_match_all('/Path \d+:/', $dot, $matches);
        $this->assertSame(128, count($matches[0]));
    }

    public function testTitleShowsPathCount(): void
    {
        $stmts = $this->parseFunction('<?php function f($x, $y) { if ($x) { $a = 1; } if ($y) { $b = 1; } }');
        $dot   = (new AcpathPathEnumerationDotVisitor)->generate($stmts);

        $this->assertStringContainsString('ACPATH paths: 4', $dot);
    }

    public function testEachPathGetsDistinctColor(): void
    {
        // 4 paths from 2 sequential ifs — each should get a unique color
        $stmts = $this->parseFunction('<?php function f($x, $y) { if ($x) { $a = 1; } if ($y) { $b = 1; } }');
        $dot   = (new AcpathPathEnumerationDotVisitor)->generate($stmts);

        preg_match_all('/FONT COLOR="(#[0-9a-f]{6})"/', $dot, $matches);

        $this->assertCount(4, $matches[1]);
        $this->assertCount(4, array_unique($matches[1]));
    }

    /**
     * @return Stmt[]
     */
    private function parseFunction(string $source): array
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse($source);

        assert($nodes !== null);

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->traverse($nodes);

        foreach ($nodes as $node) {
            if ($node instanceof Function_) {
                return $node->getStmts();
            }
        }

        return [];
    }
}
