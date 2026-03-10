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
use PHPUnit\Framework\TestCase;

#[CoversClass(AcpathControlFlowDotVisitor::class)]
#[CoversClass(AcpathControlFlowGraph::class)]
#[Small]
final class AcpathControlFlowDotVisitorTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validSourceProvider(): array
    {
        return [
            'linear code' => [
                '<?php function f() { $a = 1; $b = 2; }',
            ],
            'if/else' => [
                '<?php function f($x) { if ($x) { $a = 1; } else { $a = 2; } }',
            ],
            'while loop' => [
                '<?php function f($x) { while ($x) { $a = 1; } }',
            ],
            'return in if' => [
                '<?php function f($x) { if ($x) { return 1; } return 2; }',
            ],
            'for loop' => [
                '<?php function f() { for ($i = 0; $i < 10; $i++) { $a = 1; } }',
            ],
            'foreach loop' => [
                '<?php function f($arr) { foreach ($arr as $v) { $a = 1; } }',
            ],
            'switch' => [
                '<?php function f($x) { switch ($x) { case 1: $a = 1; break; default: $a = 2; } }',
            ],
            'try/catch' => [
                '<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } }',
            ],
            'do-while' => [
                '<?php function f($x) { do { $a = 1; } while ($x); }',
            ],
        ];
    }

    #[DataProvider('validSourceProvider')]
    public function testGeneratesValidDot(string $source): void
    {
        $dot = $this->generateDot($source);

        $this->assertStringContainsString('digraph cfg {', $dot);
        $this->assertStringContainsString('entry [shape=point]', $dot);
        $this->assertStringContainsString('exit [shape=point]', $dot);
        $this->assertStringContainsString('entry ->', $dot);
    }

    public function testLinearCodeHasNodiamondNodes(): void
    {
        $dot = $this->generateDot('<?php function f() { $a = 1; $b = 2; }');

        $this->assertStringNotContainsString('shape=diamond', $dot);
    }

    public function testIfElseHasDiamondAndBranchLabels(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if ($x) { $a = 1; } else { $a = 2; } }');

        $this->assertStringContainsString('shape=diamond', $dot);
        $this->assertStringContainsString('label="true"', $dot);
        $this->assertStringContainsString('label="false"', $dot);
    }

    public function testWhileLoopHasDashedBackEdge(): void
    {
        $dot = $this->generateDot('<?php function f($x) { while ($x) { $a = 1; } }');

        $this->assertStringContainsString('style=dashed', $dot);
        $this->assertStringContainsString('shape=diamond', $dot);
    }

    public function testReturnCreatesEdgeToExit(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if ($x) { return 1; } return 2; }');

        $this->assertStringContainsString('label="return"', $dot);
        $this->assertStringContainsString('-> exit', $dot);
    }

    public function testThrowCreatesEdgeToExit(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if ($x) { throw new \Exception(); } }');

        $this->assertStringContainsString('label="throw"', $dot);
    }

    public function testContinueInLoopCreatesDashedEdge(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { while ($x) { if ($y) { continue; } $a = 1; } }');

        $this->assertStringContainsString('label="continue"', $dot);
        $this->assertStringContainsString('style=dashed', $dot);
    }

    public function testElseifChain(): void
    {
        $dot = $this->generateDot('<?php function f($x, $y) { if ($x) { $a = 1; } elseif ($y) { $a = 2; } else { $a = 3; } }');

        $this->assertStringContainsString('shape=diamond', $dot);
        $this->assertStringContainsString('label="true"', $dot);
        $this->assertStringContainsString('label="false"', $dot);
    }

    public function testForeachWithKey(): void
    {
        $dot = $this->generateDot('<?php function f($arr) { foreach ($arr as $k => $v) { $a = 1; } }');

        $this->assertStringContainsString('=>', $dot);
        $this->assertStringContainsString('shape=diamond', $dot);
    }

    public function testTryFinally(): void
    {
        $dot = $this->generateDot('<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } finally { $b = 3; } }');

        $this->assertStringContainsString('finally', $dot);
    }

    public function testLongLabelIsTruncated(): void
    {
        $dot = $this->generateDot('<?php function f() { $very_long_variable_name_that_exceeds_sixty_characters_limit = 1; }');

        $this->assertStringContainsString('...', $dot);
    }

    public function testOtherStatementRendersAsBox(): void
    {
        $dot = $this->generateDot('<?php function f() { echo "hello"; }');

        $this->assertStringContainsString('echo', $dot);
    }

    public function testSwitchWithoutDefault(): void
    {
        $dot = $this->generateDot('<?php function f($x) { switch ($x) { case 1: $a = 1; break; case 2: $a = 2; break; } }');

        $this->assertStringContainsString('no match', $dot);
    }

    public function testSwitchFallthrough(): void
    {
        $dot = $this->generateDot('<?php function f($x) { switch ($x) { case 1: case 2: $a = 1; break; default: $a = 2; } }');

        $this->assertStringContainsString('fallthrough', $dot);
    }

    public function testForWithEmptyCondition(): void
    {
        $dot = $this->generateDot('<?php function f() { for (;;) { break; } }');

        $this->assertStringContainsString('shape=diamond', $dot);
        $this->assertStringContainsString('true', $dot);
    }

    public function testForWithMultipleConditions(): void
    {
        $dot = $this->generateDot('<?php function f() { for ($i = 0; $i < 10, $i < 20; $i++) { $a = 1; } }');

        $this->assertStringContainsString(',', $dot);
    }

    public function testAllBranchesReturn(): void
    {
        $dot = $this->generateDot('<?php function f($x) { if ($x) { return 1; } else { return 2; } }');

        $this->assertStringContainsString('label="return"', $dot);
    }

    public function testLongStatementLabelIsTruncated(): void
    {
        $dot = $this->generateDot('<?php function f() { echo "this is a very long string that will definitely exceed the sixty character limit for labels"; }');

        $this->assertStringContainsString('...', $dot);
    }

    public function testBlockStatement(): void
    {
        $dot = $this->generateDot('<?php function f() { { $a = 1; } }');

        $this->assertStringContainsString('digraph cfg {', $dot);
    }

    public function testTryFinallyWithAllTerminating(): void
    {
        $dot = $this->generateDot('<?php function f() { try { return 1; } catch (\Exception $e) { return 2; } finally { $a = 1; } }');

        $this->assertStringContainsString('finally', $dot);
    }

    private function generateDot(string $source): string
    {
        $stmts = $this->parseFunction($source);

        return (new AcpathControlFlowDotVisitor)->generate($stmts);
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
