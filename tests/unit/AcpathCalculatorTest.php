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
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcpathCalculator::class)]
#[Small]
final class AcpathCalculatorTest extends TestCase
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
            'if with || condition' => [
                '<?php function f($x, $y) { if ($x || $y) { $a = 1; } }',
                3,
            ],
            'sequential ifs (multiplicative)' => [
                '<?php function f($x, $y) { if ($x) { $a = 1; } if ($y) { $b = 1; } }',
                4,
            ],
            'three sequential ifs' => [
                '<?php function f($a, $b, $c) { if ($a) { $x = 1; } if ($b) { $y = 1; } if ($c) { $z = 1; } }',
                8,
            ],
            'nested ifs' => [
                '<?php function f($x, $y) { if ($x) { if ($y) { $a = 1; } } }',
                3,
            ],
            'elseif' => [
                '<?php function f($x, $y) { if ($x) { $a = 1; } elseif ($y) { $a = 2; } else { $a = 3; } }',
                3,
            ],
            'while loop' => [
                '<?php function f($x) { while ($x) { $a = 1; } }',
                2,
            ],
            'do-while loop' => [
                '<?php function f($x) { do { $a = 1; } while ($x); }',
                1,
            ],
            'for loop' => [
                '<?php function f() { for ($i = 0; $i < 10; $i++) { $a = 1; } }',
                2,
            ],
            'foreach loop' => [
                '<?php function f($arr) { foreach ($arr as $v) { $a = 1; } }',
                2,
            ],
            'return in if' => [
                '<?php function f($x) { if ($x) { return; } $a = 1; }',
                2,
            ],
            'return with expression' => [
                '<?php function f($x) { if ($x) { return 1; } return 2; }',
                2,
            ],
            'ternary' => [
                '<?php function f($x) { $a = $x ? 1 : 2; }',
                2,
            ],
            'switch with break' => [
                '<?php function f($x) { switch ($x) { case 1: $a = 1; break; case 2: $a = 2; break; default: $a = 3; } }',
                3,
            ],
            'match expression' => [
                '<?php function f($x) { $a = match ($x) { 1 => "a", 2 => "b" }; }',
                4,
            ],
            'try/catch' => [
                '<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } }',
                2,
            ],
            'break in while' => [
                '<?php function f($x, $y) { while ($x) { if ($y) { break; } $a = 1; } }',
                3,
            ],
            'continue in while' => [
                '<?php function f($x, $y) { while ($x) { if ($y) { continue; } $a = 1; } }',
                3,
            ],
            'empty function' => [
                '<?php function f() { }',
                1,
            ],
            'throw' => [
                '<?php function f($x) { if ($x) { throw new \Exception(); } $a = 1; }',
                2,
            ],
            'boolean not in if' => [
                '<?php function f($x) { if (!$x) { $a = 1; } }',
                2,
            ],
            'elvis operator' => [
                '<?php function f($x, $y) { $a = $x ?: $y; }',
                2,
            ],
            'null coalesce' => [
                '<?php function f($x, $y) { $a = $x ?? $y; }',
                2,
            ],
            'cast expression' => [
                '<?php function f($x) { $a = (int)($x ? 1 : 2); }',
                2,
            ],
            'unary minus' => [
                '<?php function f($x) { $a = -($x ? 1 : 2); }',
                2,
            ],
            'unary plus' => [
                '<?php function f($x) { $a = +($x ? 1 : 2); }',
                2,
            ],
            'binary op non-boolean' => [
                '<?php function f($x) { $a = ($x ? 1 : 2) + 3; }',
                2,
            ],
            'assign op' => [
                '<?php function f($x) { $a = 0; $a += ($x ? 1 : 2); }',
                2,
            ],
            'for with empty condition' => [
                '<?php function f() { for (;;) { break; } }',
                2,
            ],
            'try/catch/finally' => [
                '<?php function f() { try { $a = 1; } catch (\Exception $e) { $a = 2; } finally { $b = 3; } }',
                2,
            ],
            'while with && condition' => [
                '<?php function f($x, $y) { while ($x && $y) { $a = 1; } }',
                3,
            ],
            'while with || condition' => [
                '<?php function f($x, $y) { while ($x || $y) { $a = 1; } }',
                2,
            ],
            'while with ! condition' => [
                '<?php function f($x) { while (!$x) { $a = 1; } }',
                2,
            ],
            'while with ternary condition' => [
                '<?php function f($x, $y, $z) { while ($x ? $y : $z) { $a = 1; } }',
                4,
            ],
            'while with elvis condition' => [
                '<?php function f($x, $y) { while ($x ?: $y) { $a = 1; } }',
                2,
            ],
            'echo statement' => [
                '<?php function f() { echo "hello"; }',
                1,
            ],
            'switch without default' => [
                '<?php function f($x) { switch ($x) { case 1: $a = 1; break; case 2: $a = 2; break; } }',
                3,
            ],
            'for with multiple conditions' => [
                '<?php function f() { for ($i = 0; $i < 10, $i < 20; $i++) { $a = 1; } }',
                3,
            ],
            'block statement' => [
                '<?php function f() { { $a = 1; } }',
                1,
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testCalculatesAcpath(string $source, int $expectedAcpath): void
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse($source);

        assert($nodes !== null);

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->traverse($nodes);

        $functionNode = null;

        foreach ($nodes as $node) {
            if ($node instanceof Function_) {
                $functionNode = $node;

                break;
            }
        }

        assert($functionNode !== null);

        $calculator = new AcpathCalculator;
        $result     = $calculator->calculate($functionNode->getStmts());

        $this->assertSame($expectedAcpath, $result);
    }
}
