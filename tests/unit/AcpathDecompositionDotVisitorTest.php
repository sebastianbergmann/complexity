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

#[CoversClass(AcpathDecompositionDotVisitor::class)]
#[CoversClass(AcpathCalculator::class)]
#[UsesClass(ExpressionPathAnalyzer::class)]
#[Small]
final class AcpathDecompositionDotVisitorTest extends TestCase
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
            'if with && condition' => [
                '<?php function f($x, $y) { if ($x && $y) { $a = 1; } }',
                3,
            ],
            'sequential ifs' => [
                '<?php function f($x, $y) { if ($x) { $a = 1; } if ($y) { $b = 1; } }',
                4,
            ],
            'while loop' => [
                '<?php function f($x) { while ($x) { $a = 1; } }',
                2,
            ],
            'return in if' => [
                '<?php function f($x) { if ($x) { return; } $a = 1; }',
                2,
            ],
            'ternary' => [
                '<?php function f($x) { $a = $x ? 1 : 2; }',
                2,
            ],
            'try/catch' => [
                '<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } }',
                2,
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testRootNodeShowsCorrectAcpath(string $source, int $expectedAcpath): void
    {
        $dot = $this->generateDot($source);

        $this->assertStringContainsString('ACPATH = ' . $expectedAcpath, $dot);
    }

    public function testGeneratesValidDotFormat(): void
    {
        $dot = $this->generateDot('<?php function f() { $a = 1; }');

        $this->assertStringContainsString('digraph decomposition {', $dot);
        $this->assertStringContainsString('shape=record', $dot);
        $this->assertStringContainsString('function body', $dot);
    }

    public function testSequentialStatementsShowMultiplication(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if ($x) { $a = 1; } $b = 2; }');

        $this->assertStringContainsString('sequential', $dot);
    }

    public function testBranchesShowAddition(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if ($x) { $a = 1; } else { $a = 2; } }');

        $this->assertStringContainsString('+ (true branch)', $dot);
        $this->assertStringContainsString('+ (false branch)', $dot);
    }

    public function testLeafExpressionsShowTfp(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { if ($x && $y) { $a = 1; } }');

        $this->assertStringContainsString('t=1, f=1, p=1', $dot);
    }

    public function testAcpathMatchesCalculator(): void
    {
        $sources = [
            '<?php function f($x) { if ($x) { $a = 1; } }',
            '<?php function f($x, $y) { if ($x) { $a = 1; } if ($y) { $b = 1; } }',
            '<?php function f($x, $y) { if ($x && $y) { $a = 1; } }',
            '<?php function f($x) { while ($x) { $a = 1; } }',
            '<?php function f($x) { if ($x) { return; } $a = 1; }',
            '<?php function f($x) { switch ($x) { case 1: $a = 1; break; default: $a = 2; } }',
        ];

        $calculator = new AcpathCalculator;
        $visitor    = new AcpathDecompositionDotVisitor;

        foreach ($sources as $source) {
            $stmts  = $this->parseFunction($source);
            $acpath = $calculator->calculate($stmts);
            $dot    = $visitor->generate($stmts);

            $this->assertStringContainsString('ACPATH = ' . $acpath, $dot, 'ACPATH mismatch for: ' . $source);
        }
    }

    public function testReturnWithExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if ($x) { return $x; } return 0; }');

        $this->assertStringContainsString('return', $dot);
        $this->assertStringContainsString('ACPATH = 2', $dot);
    }

    public function testDoWhileLoop(): void
    {
        $dot = $this->generateDot('<?php function f($x) { do { $a = 1; } while ($x); }');

        $this->assertStringContainsString('do-while', $dot);
        $this->assertStringContainsString('do body', $dot);
        $this->assertStringContainsString('ACPATH = 1', $dot);
    }

    public function testForLoop(): void
    {
        $dot = $this->generateDot('<?php function f() { for ($i = 0; $i < 10; $i++) { $a = 1; } }');

        $this->assertStringContainsString('for\\|', $dot);
        $this->assertStringContainsString('for body', $dot);
        $this->assertStringContainsString('ACPATH = 2', $dot);
    }

    public function testForeachLoop(): void
    {
        $dot = $this->generateDot('<?php function f($arr) { foreach ($arr as $v) { $a = 1; } }');

        $this->assertStringContainsString('foreach\\|', $dot);
        $this->assertStringContainsString('foreach body', $dot);
        $this->assertStringContainsString('ACPATH = 2', $dot);
    }

    public function testContinueStatement(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { while ($x) { if ($y) { continue; } $a = 1; } }');

        $this->assertStringContainsString('continue\\|', $dot);
    }

    public function testElseifChain(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { if ($x) { $a = 1; } elseif ($y) { $a = 2; } else { $a = 3; } }');

        $this->assertStringContainsString('elseif\\|', $dot);
        $this->assertStringContainsString('ACPATH = 3', $dot);
    }

    public function testTryFinally(): void
    {
        $dot = $this->generateDot('<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } finally { $b = 3; } }');

        $this->assertStringContainsString('finally\\|', $dot);
        $this->assertStringContainsString('(finally)', $dot);
    }

    public function testOtherStatement(): void
    {
        $dot = $this->generateDot('<?php function f() { echo "hello"; }');

        $this->assertStringContainsString('echo', $dot);
    }

    public function testBooleanNotExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if (!$x) { $a = 1; } }');

        $this->assertStringContainsString('NOT', $dot);
    }

    public function testBooleanOrExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { if ($x || $y) { $a = 1; } }');

        $this->assertStringContainsString('right (\\|\\|)', $dot);
    }

    public function testTernaryExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x) { $a = $x ? 1 : 2; }');

        $this->assertStringContainsString('+ (true)', $dot);
        $this->assertStringContainsString('+ (false)', $dot);
        $this->assertStringContainsString('ACPATH = 2', $dot);
    }

    public function testElvisExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { $a = $x ?: $y; }');

        $this->assertStringContainsString('right (?:)', $dot);
    }

    public function testCoalesceExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { $a = $x ?? $y; }');

        $this->assertStringContainsString('right (??)', $dot);
    }

    public function testMatchExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x) { $a = match ($x) { 1 => "a", 2 => "b" }; }');

        $this->assertStringContainsString('+ (match cond)', $dot);
        $this->assertStringContainsString('+ (match arm)', $dot);
    }

    public function testAssignExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x) { $a = $x ? 1 : 2; }');

        $this->assertStringContainsString('× (assign)', $dot);
    }

    public function testBinaryOpExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x) { $a = ($x ? 1 : 2) + 3; }');

        $this->assertStringContainsString('× (binary op)', $dot);
    }

    public function testCastExpression(): void
    {
        $dot = $this->generateDot('<?php function f($x) { $a = (int)($x ? 1 : 2); }');

        $this->assertStringContainsString('operand', $dot);
    }

    public function testWhileWithAndExercisesHelpers(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { while ($x && $y) { $a = 1; } }');

        $this->assertStringContainsString('ACPATH = 3', $dot);
    }

    public function testWhileWithOrExercisesHelpers(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { while ($x || $y) { $a = 1; } }');

        $this->assertStringContainsString('ACPATH = 2', $dot);
    }

    public function testWhileWithNotExercisesHelpers(): void
    {
        $dot = $this->generateDot('<?php function f($x) { while (!$x) { $a = 1; } }');

        $this->assertStringContainsString('ACPATH = 2', $dot);
    }

    public function testWhileWithTernaryExercisesHelpers(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y, $z) { while ($x ? $y : $z) { $a = 1; } }');

        $this->assertStringContainsString('ACPATH = 4', $dot);
    }

    public function testWhileWithElvisExercisesHelpers(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { while ($x ?: $y) { $a = 1; } }');

        $this->assertStringContainsString('ACPATH = 2', $dot);
    }

    public function testWhileWithCoalesceExercisesHelpers(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { while ($x ?? $y) { $a = 1; } }');

        $this->assertStringContainsString('ACPATH = 3', $dot);
    }

    public function testForWithEmptyCondition(): void
    {
        $dot = $this->generateDot('<?php function f() { for (;;) { break; } }');

        $this->assertStringContainsString('for\\|', $dot);
    }

    public function testForWithMultipleConditions(): void
    {
        $dot = $this->generateDot('<?php function f() { for ($i = 0; $i < 10, $i < 20; $i++) { $a = 1; } }');

        $this->assertStringContainsString('for\\|', $dot);
    }

    public function testSwitchWithoutDefault(): void
    {
        $dot = $this->generateDot('<?php function f($x) { switch ($x) { case 1: $a = 1; break; case 2: $a = 2; break; } }');

        $this->assertStringContainsString('switch\\|', $dot);
        $this->assertStringContainsString('ACPATH = 3', $dot);
    }

    public function testWhileWithMatchCondition(): void
    {
        $dot = $this->generateDot('<?php function f($x) { while (match($x) { 1 => true, default => false }) { $a = 1; } }');

        $this->assertStringContainsString('while\\|', $dot);
    }

    public function testWhileWithCastCondition(): void
    {
        $dot = $this->generateDot('<?php function f($x) { while ((bool)$x) { $a = 1; } }');

        $this->assertStringContainsString('while\\|', $dot);
    }

    public function testLongExpressionLabelTruncated(): void
    {
        $dot = $this->generateDot('<?php function f() { $very_long_variable_name_that_exceeds_sixty_characters_limit = 1; }');

        $this->assertStringContainsString('...', $dot);
    }

    public function testLongStatementLabelTruncated(): void
    {
        $dot = $this->generateDot('<?php function f() { echo "this is a very long string that will definitely exceed the sixty character truncation limit"; }');

        $this->assertStringContainsString('...', $dot);
    }

    public function testBlockStatement(): void
    {
        $dot = $this->generateDot('<?php function f() { { $a = 1; } }');

        $this->assertStringContainsString('block', $dot);
    }

    public function testLongExpressionInConditionTruncated(): void
    {
        $dot = $this->generateDot('<?php function f($very_long_variable_name_that_exceeds_sixty_chars_for_truncation) { if ($very_long_variable_name_that_exceeds_sixty_chars_for_truncation) { $a = 1; } }');

        $this->assertStringContainsString('...', $dot);
    }

    private function generateDot(string $source): string
    {
        $stmts = $this->parseFunction($source);

        return (new AcpathDecompositionDotVisitor)->generate($stmts);
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
