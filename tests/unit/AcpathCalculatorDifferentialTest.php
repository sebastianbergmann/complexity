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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Differential test: AcpathCalculator against ReferenceAcpathOracle.
 *
 * By Theorem 2 of arXiv:1610.07914v4, ACPATH equals the number of acyclic
 * paths in the reference CFG for controlled function bodies, so calculator
 * and oracle must agree wherever the calculator follows the paper and the
 * paper is internally consistent.
 *
 * Divergences are pinned in their own data provider with both current
 * values, so that a change to either implementation surfaces here and the
 * list of known differences stays explicit.
 */
#[CoversClass(AcpathCalculator::class)]
#[UsesClass(ExpressionPathAnalyzer::class)]
#[Small]
final class AcpathCalculatorDifferentialTest extends TestCase
{
    /**
     * All cases of AcpathCalculatorTest on which calculator and oracle agree,
     * plus additional agreement cases for jump levels and switch semantics.
     *
     * @return array<string, array{0: string}>
     */
    public static function agreementProvider(): array
    {
        $sources = [
            'linear code'                      => '<?php function f() { $a = 1; $b = 2; }',
            'single if'                        => '<?php function f($x) { if ($x) { $a = 1; } }',
            'if/else'                          => '<?php function f($x) { if ($x) { $a = 1; } else { $a = 2; } }',
            'if with && condition'             => '<?php function f($x, $y) { if ($x && $y) { $a = 1; } }',
            'if with || condition'             => '<?php function f($x, $y) { if ($x || $y) { $a = 1; } }',
            'sequential ifs'                   => '<?php function f($x, $y) { if ($x) { $a = 1; } if ($y) { $b = 1; } }',
            'three sequential ifs'             => '<?php function f($a, $b, $c) { if ($a) { $x = 1; } if ($b) { $y = 1; } if ($c) { $z = 1; } }',
            'nested ifs'                       => '<?php function f($x, $y) { if ($x) { if ($y) { $a = 1; } } }',
            'elseif'                           => '<?php function f($x, $y) { if ($x) { $a = 1; } elseif ($y) { $a = 2; } else { $a = 3; } }',
            'while loop'                       => '<?php function f($x) { while ($x) { $a = 1; } }',
            'do-while loop'                    => '<?php function f($x) { do { $a = 1; } while ($x); }',
            'foreach loop'                     => '<?php function f($arr) { foreach ($arr as $v) { $a = 1; } }',
            'return in if'                     => '<?php function f($x) { if ($x) { return; } $a = 1; }',
            'return with expression'           => '<?php function f($x) { if ($x) { return 1; } return 2; }',
            'ternary'                          => '<?php function f($x) { $a = $x ? 1 : 2; }',
            'switch with break'                => '<?php function f($x) { switch ($x) { case 1: $a = 1; break; case 2: $a = 2; break; default: $a = 3; } }',
            'try/catch'                        => '<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } }',
            'break in while'                   => '<?php function f($x, $y) { while ($x) { if ($y) { break; } $a = 1; } }',
            'continue in while'                => '<?php function f($x, $y) { while ($x) { if ($y) { continue; } $a = 1; } }',
            'empty function'                   => '<?php function f() { }',
            'boolean not in if'                => '<?php function f($x) { if (!$x) { $a = 1; } }',
            'elvis operator'                   => '<?php function f($x, $y) { $a = $x ?: $y; }',
            'null coalesce'                    => '<?php function f($x, $y) { $a = $x ?? $y; }',
            'cast around ternary'              => '<?php function f($x) { $a = (int) ($x ? 1 : 2); }',
            'unary minus around ternary'       => '<?php function f($x) { $a = -($x ? 1 : 2); }',
            'binary op with ternary operand'   => '<?php function f($x) { $a = ($x ? 1 : 2) + 3; }',
            'assign op with ternary operand'   => '<?php function f($x) { $a = 0; $a += ($x ? 1 : 2); }',
            'for with empty condition'         => '<?php function f() { for (;;) { break; } }',
            'try/catch/finally'                => '<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } finally { $b = 3; } }',
            'while with && condition'          => '<?php function f($x, $y) { while ($x && $y) { $a = 1; } }',
            'while with || condition'          => '<?php function f($x, $y) { while ($x || $y) { $a = 1; } }',
            'while with ! condition'           => '<?php function f($x) { while (!$x) { $a = 1; } }',
            'while with ternary condition'     => '<?php function f($x, $y, $z) { while ($x ? $y : $z) { $a = 1; } }',
            'while with elvis condition'       => '<?php function f($x, $y) { while ($x ?: $y) { $a = 1; } }',
            'echo statement'                   => '<?php function f() { echo "hello"; }',
            'switch without default'           => '<?php function f($x) { switch ($x) { case 1: $a = 1; break; case 2: $a = 2; break; } }',
            'block statement'                  => '<?php function f() { { $a = 1; } }',
            'break with level'                 => '<?php function f($x, $y, $z) { while ($x) { while ($y) { if ($z) { break 2; } $a = 1; } $b = 1; } }',
            'continue in switch acts as break' => '<?php function f($x, $y) { while ($x) { switch ($y) { case 1: continue; } $a = 1; } }',
            'do-while with branching body'     => '<?php function f($b, $c) { do { if ($b) { $x = 1; } else { $x = 2; } } while ($c); }',
            'nested loops'                     => '<?php function f($x, $y) { while ($x) { while ($y) { $a = 1; } } }',
            'loop containing if/else'          => '<?php function f($c, $d) { while ($c) { if ($d) { $x = 1; } else { $y = 1; } } }',
        ];

        $cases = [];

        foreach ($sources as $name => $source) {
            $cases[$name] = [$source];
        }

        return $cases;
    }

    /**
     * Known divergences between AcpathCalculator and the reference CFG
     * semantics of the paper. Both current values are pinned so that any
     * change to either side is surfaced by this test. Categories:
     *
     * (A) Potential paper defect inherited by the calculator: equation (45)
     *     seems to drop continue paths and body re-entry in do-while loops
     *     (the paper's Example 4 appears inconsistent with its own
     *     Definitions 2 and 3).
     * (B) Deliberate calculator deviation: double-traversal of loop guards
     *     that are comparisons/other operators uses tf = p instead of the
     *     paper's Table 5 (which yields 0 and, e.g., ACPATH 1 for
     *     `while ($i < 10)`).
     * (C) Calculator deviation from Table 3: casts and unary plus/minus
     *     should be transparent for t/f (eq. (8)) but collapse to t = f = p.
     * (D) Calculator does not look into expressions without a dedicated
     *     rule: branching inside call arguments, echo, foreach iterables,
     *     and the nullsafe short-circuit are not counted.
     * (E) PHP constructs without a counterpart in the paper where the two
     *     implementations chose different models: match, throw/exit as
     *     fall-through vs. exceptional exit, comma-separated for guards.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function knownDivergenceProvider(): array
    {
        return [
            '(A) do-while with break, Example 4' => [
                '<?php function f($a, $b, $c) { do { if ($a) { break; } if ($b) { $x = 1; } else { $x = 2; } } while ($c); }',
                3,
                5,
            ],
            '(A) do-while with continue' => [
                '<?php function f($a, $c) { do { if ($a) { continue; } } while ($c); }',
                1,
                4,
            ],
            '(B) while with comparison guard' => [
                '<?php function f($i) { while ($i < 10) { $a = 1; } }',
                2,
                1,
            ],
            '(B) for loop with comparison guard' => [
                '<?php function f() { for ($i = 0; $i < 10; $i++) { $a = 1; } }',
                2,
                1,
            ],
            '(C) cast around boolean condition' => [
                '<?php function f($x, $y) { if ((bool) ($x && $y)) { $a = 1; } }',
                4,
                3,
            ],
            '(D) echo with ternary' => [
                '<?php function f($x) { echo $x ? 1 : 2; }',
                1,
                2,
            ],
            '(D) call argument with ternary' => [
                '<?php function f($x) { g($x ? 1 : 2); }',
                1,
                2,
            ],
            '(D) foreach over branching iterable' => [
                '<?php function f($x, $y) { foreach (($x ?: $y) as $v) { $a = 1; } }',
                2,
                4,
            ],
            '(D) nullsafe operator' => [
                '<?php function f($x) { $a = $x?->y; }',
                1,
                2,
            ],
            '(E) match without default' => [
                '<?php function f($x) { $a = match ($x) { 1 => "a", 2 => "b" }; }',
                4,
                3,
            ],
            '(E) match with default' => [
                '<?php function f($x) { $a = match ($x) { 1 => "a", default => "b" }; }',
                3,
                2,
            ],
            '(E) throw followed by branching' => [
                '<?php function f($x, $y) { if ($x) { throw new \Exception; } $a = $y ? 1 : 2; }',
                4,
                3,
            ],
            '(E) for with comma-separated guards' => [
                '<?php function f() { for ($i = 0; $i < 10, $i < 20; $i++) { $a = 1; } }',
                3,
                1,
            ],
        ];
    }

    #[DataProvider('agreementProvider')]
    public function testAgreesWithReferenceOracle(string $source): void
    {
        $statements = $this->statements($source);

        $this->assertSame(
            (new ReferenceAcpathOracle)->countAcyclicPaths($statements),
            (new AcpathCalculator)->calculate($statements),
        );
    }

    #[DataProvider('knownDivergenceProvider')]
    public function testKnownDivergenceIsUnchanged(string $source, int $calculator, int $oracle): void
    {
        $statements = $this->statements($source);

        $this->assertSame($calculator, (new AcpathCalculator)->calculate($statements));
        $this->assertSame($oracle, (new ReferenceAcpathOracle)->countAcyclicPaths($statements));
        $this->assertNotSame($calculator, $oracle);
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
