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

use function assert;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * Validates the test oracle against the worked examples published in
 * arXiv:1610.07914v4 by the authors of the ACPATH metric. These expected
 * values were not produced by this project and therefore validate the
 * oracle independently.
 *
 * The figures of Appendix B were "obtained automatically from an executable
 * version of Definition 3" and are matched exactly.
 */
#[CoversNothing]
#[Small]
final class ReferenceAcpathOracleTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function paperProvider(): array
    {
        return [
            'Example 1 / Figure 4: if (a && b && c) return d ? 0 : 1; else return e ? 0 : 1;' => [
                '<?php function f($a, $b, $c, $d, $e) { if ($a && $b && $c) { return $d ? 0 : 1; } else { return $e ? 0 : 1; } }',
                8,
            ],
            'Example 2: while (a || (b && c && d)) with empty body' => [
                '<?php function f($a, $b, $c, $d) { while ($a || ($b && $c && $d)) { } }',
                6,
            ],
            'Example 3: switch with case falling through into a returning default' => [
                '<?php function f($a, $b, $c) { switch ($a) { case 1: $b ? 0 : 1; default: return $c ? 0 : 1; } }',
                6,
            ],
            'Example 5: do { ... } while (0)' => [
                '<?php function f() { do { $x = 1; } while (0); }',
                1,
            ],
            'Figure 5: while (c1) if (c2) break; else continue;' => [
                '<?php function f($c1, $c2) { while ($c1) { if ($c2) { break; } else { continue; } } }',
                3,
            ],
            'Figure 6: while ((a || b) && (c || d))' => [
                '<?php function f($a, $b, $c, $d) { while (($a || $b) && ($c || $d)) { $x = 1; } }',
                7,
            ],
            'Figure 7: do ... while ((a || b) && (c || d))' => [
                '<?php function f($a, $b, $c, $d) { do { $x = 1; } while (($a || $b) && ($c || $d)); }',
                3,
            ],
            'Figure 8: switch with break, conditional break, and fall-through into default' => [
                '<?php function f($x, $c) { switch ($x) { case 1: $y = 1; break; case 2: if ($c) { $y = 2; } else { $y = 3; break; } default: $y = 4; } }',
                4,
            ],
        ];
    }

    /**
     * The paper's (hand-written) Example 4 claims 3, 2, and 3 acyclic paths
     * for these do-while variants. Those claims appear to contradict the
     * paper's own Definitions 2 and 3: counting arc-distinct paths on the
     * reference CFG yields 5, 7, and 5, because a path may fall through the
     * body, evaluate the guard to true, and traverse the body a second time
     * on the arcs the first traversal did not use. For the continue variant
     * the paper's value even seems to contradict a semantic reading:
     * `do { if ($a) continue; ... } while ($c)` with $a true and $c false
     * terminates without looping, a path Example 4 does not list. Equation
     * (45) drops continue paths and body re-entry accordingly, so ACPATH as
     * defined appears to undercount these. See ReferenceAcpathOracle for the
     * discussion.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function doWhileProvider(): array
    {
        return [
            'Example 4, break variant, per Definition 2' => [
                '<?php function f($a, $b, $c) { do { if ($a) { break; } if ($b) { $x = 1; } else { $x = 2; } } while ($c); }',
                5,
            ],
            'Example 4, continue variant, per Definition 2' => [
                '<?php function f($a, $b, $c) { do { if ($a) { continue; } if ($b) { $x = 1; } else { $x = 2; } } while ($c); }',
                7,
            ],
            'Example 4, return variant, per Definition 2' => [
                '<?php function f($a, $b, $c) { do { if ($a) { return; } if ($b) { $x = 1; } else { $x = 2; } } while ($c); }',
                5,
            ],
            'do-while with branching body and no jumps: re-entry is arc-blocked' => [
                '<?php function f($b, $c) { do { if ($b) { $x = 1; } else { $x = 2; } } while ($c); }',
                2,
            ],
        ];
    }

    #[DataProvider('paperProvider')]
    #[DataProvider('doWhileProvider')]
    public function testCountsAcyclicPaths(string $source, int $expected): void
    {
        $this->assertSame(
            $expected,
            (new ReferenceAcpathOracle)->countAcyclicPaths($this->statements($source)),
        );
    }

    /**
     * @return array<Stmt>
     */
    private function statements(string $source): array
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

        $this->fail('No function found in source');
    }
}
