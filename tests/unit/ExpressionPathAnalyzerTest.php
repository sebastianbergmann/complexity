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

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp\Plus as AssignPlus;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast\Int_ as CastInt;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\MatchArm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionPathAnalyzer::class)]
#[Small]
final class ExpressionPathAnalyzerTest extends TestCase
{
    /**
     * @return array<string, array{0: Expr, 1: int, 2: int, 3: int}>
     */
    public static function expressionPathsProvider(): array
    {
        return [
            // Leaf — base case: {t:1, f:1, p:1}
            'leaf variable' => [
                new Variable('x'),
                1, 1, 1,
            ],

            // BooleanNot — swaps t and f
            'BooleanNot of leaf' => [
                new BooleanNot(self::leaf()),
                1, 1, 1,
            ],
            'BooleanNot of BooleanAnd' => [
                // NOT(x && y): child {t:1, f:2, p:2} → {t:2, f:1, p:2}
                new BooleanNot(new BooleanAnd(self::leaf(), self::leaf())),
                2, 1, 2,
            ],

            // BooleanAnd — t=t1*t2, f=f1+t1*f2, p=f1+t1*p2
            'BooleanAnd of two leaves' => [
                // t=1*1=1, f=1+1*1=2, p=1+1*1=2
                new BooleanAnd(self::leaf(), self::leaf()),
                1, 2, 2,
            ],

            // LogicalAnd — same formula as BooleanAnd
            'LogicalAnd of two leaves' => [
                new LogicalAnd(self::leaf(), self::leaf()),
                1, 2, 2,
            ],

            // BooleanOr — t=t1+f1*t2, f=f1*f2, p=t1+f1*p2
            'BooleanOr of two leaves' => [
                // t=1+1*1=2, f=1*1=1, p=1+1*1=2
                new BooleanOr(self::leaf(), self::leaf()),
                2, 1, 2,
            ],

            // LogicalOr — same formula as BooleanOr
            'LogicalOr of two leaves' => [
                new LogicalOr(self::leaf(), self::leaf()),
                2, 1, 2,
            ],

            // Ternary (full) — t=t1*t2+f1*t3, f=t1*f2+f1*f3, p=t1*p2+f1*p3
            'Ternary full with three leaves' => [
                // t=1*1+1*1=2, f=1*1+1*1=2, p=1*1+1*1=2
                new Ternary(self::leaf(), self::leaf(), self::leaf()),
                2, 2, 2,
            ],

            // Ternary (elvis / ?:) — same as BooleanOr
            'Ternary elvis with two leaves' => [
                // t=2, f=1, p=2
                new Ternary(self::leaf(), null, self::leaf()),
                2, 1, 2,
            ],

            // Coalesce — same as BooleanOr
            'Coalesce of two leaves' => [
                new Coalesce(self::leaf(), self::leaf()),
                2, 1, 2,
            ],

            // Match — totalP = sum of each arm's cond paths + body paths
            'Match with two conditional arms' => [
                // arm1: cond p=1, body p=1; arm2: cond p=1, body p=1 → totalP=4
                new Match_(self::leaf(), [
                    new MatchArm([self::leaf()], self::leaf()),
                    new MatchArm([self::leaf()], self::leaf()),
                ]),
                4, 4, 4,
            ],
            'Match with default arm only' => [
                // conds=null means default — only body p=1 → totalP=1
                new Match_(self::leaf(), [
                    new MatchArm(null, self::leaf()),
                ]),
                1, 1, 1,
            ],
            'Match with arm having multiple conditions' => [
                // arm: [cond1, cond2] + body → 1+1+1=3 totalP
                new Match_(self::leaf(), [
                    new MatchArm([self::leaf(), self::leaf()], self::leaf()),
                ]),
                3, 3, 3,
            ],

            // Assign — p=pVar*pExpr, t=f=p
            'Assign with two leaves' => [
                new Assign(self::leaf(), self::leaf()),
                1, 1, 1,
            ],
            'Assign with ternary rhs' => [
                // pVar=1, pExpr=p(ternary)=2 → p=2
                new Assign(self::leaf(), self::ternaryLeaf()),
                2, 2, 2,
            ],

            // AssignOp — same as Assign
            'AssignOp with two leaves' => [
                new AssignPlus(self::leaf(), self::leaf()),
                1, 1, 1,
            ],

            // BinaryOp (non-boolean) — p=p1*p2, t=f=p
            'BinaryOp Plus of two leaves' => [
                new Plus(self::leaf(), self::leaf()),
                1, 1, 1,
            ],
            'BinaryOp Plus with ternary operand' => [
                // p1=p(ternary)=2, p2=1 → p=2
                new Plus(self::ternaryLeaf(), self::leaf()),
                2, 2, 2,
            ],

            // Cast — p=p(child), t=f=p
            'Cast of leaf' => [
                new CastInt(self::leaf()),
                1, 1, 1,
            ],
            'Cast of ternary' => [
                new CastInt(self::ternaryLeaf()),
                2, 2, 2,
            ],

            // UnaryMinus — same as Cast
            'UnaryMinus of ternary' => [
                new UnaryMinus(self::ternaryLeaf()),
                2, 2, 2,
            ],

            // UnaryPlus — same as Cast
            'UnaryPlus of ternary' => [
                new UnaryPlus(self::ternaryLeaf()),
                2, 2, 2,
            ],
        ];
    }

    /**
     * @return array<string, array{0: Expr, 1: int, 2: int, 3: int, 4: int}>
     */
    public static function expressionPathsDoubleProvider(): array
    {
        return [
            // Non-boolean leaf — falls through to single-traversal fallback: {tt:0, tf:p, ff:0, pp:0}
            'leaf variable' => [
                // p=1 → {tt:0, tf:1, ff:0, pp:0}
                self::leaf(),
                0, 1, 0, 0,
            ],

            // BooleanNot — swaps tt and ff, keeps tf and pp
            'BooleanNot of leaf' => [
                // child: {tt:0, tf:1, ff:0, pp:0} → {tt:0, tf:1, ff:0, pp:0}
                new BooleanNot(self::leaf()),
                0, 1, 0, 0,
            ],
            'BooleanNot of BooleanAnd' => [
                // BooleanAnd double: {tt:0, tf:1, ff:2, pp:2}
                // NOT: {tt:2, tf:1, ff:0, pp:2}
                new BooleanNot(new BooleanAnd(self::leaf(), self::leaf())),
                2, 1, 0, 2,
            ],

            // BooleanAnd — tt=tt1*tt2, tf=tf1*t2+tt1*tf2, ff=ff1+2*tf1*f2+tt1*ff2, pp=ff1+2*tf1*p2+tt1*pp2
            'BooleanAnd of two leaves' => [
                // singles: t1=f1=1; doubles of leaves: tt1=ff1=0, tf1=1
                // tt=0, tf=1*1+0*1=1, ff=0+2*1*1+0=2, pp=0+2*1*1+0=2
                new BooleanAnd(self::leaf(), self::leaf()),
                0, 1, 2, 2,
            ],

            // LogicalAnd — same as BooleanAnd
            'LogicalAnd of two leaves' => [
                new LogicalAnd(self::leaf(), self::leaf()),
                0, 1, 2, 2,
            ],

            // BooleanOr — tt=tt1+2*tf1*t2+ff1*tt2, tf=tf1*f2+ff1*tf2, ff=ff1*ff2, pp=tt1+2*tf1*p2+ff1*pp2
            'BooleanOr of two leaves' => [
                // tt=0+2*1*1+0=2, tf=1*1+0=1, ff=0*0=0, pp=0+2*1*1+0=2
                new BooleanOr(self::leaf(), self::leaf()),
                2, 1, 0, 2,
            ],

            // LogicalOr — same as BooleanOr
            'LogicalOr of two leaves' => [
                new LogicalOr(self::leaf(), self::leaf()),
                2, 1, 0, 2,
            ],

            // Ternary (full) — tt=tt1*tt2+2*tf1*t2*t3+ff1*tt3, tf=tt1*tf2+ff1*tf3+tf1*(t2*f3+f2*t3),
            //                  ff=tt1*ff2+2*tf1*f2*f3+ff1*ff3, pp=tt1*pp2+2*tf1*p2*p3+ff1*pp3
            'Ternary full with three leaves' => [
                // tt=0+2*1*1*1+0=2, tf=0+0+1*(1*1+1*1)=2, ff=0+2*1*1*1+0=2, pp=0+2*1*1*1+0=2
                new Ternary(self::leaf(), self::leaf(), self::leaf()),
                2, 2, 2, 2,
            ],

            // Ternary (elvis) — same formula as BooleanOr
            'Ternary elvis with two leaves' => [
                new Ternary(self::leaf(), null, self::leaf()),
                2, 1, 0, 2,
            ],

            // Non-boolean fallback — tf=p(expr), rest zero
            'non-boolean BinaryOp Plus of leaves' => [
                // p=1 → {tt:0, tf:1, ff:0, pp:0}
                new Plus(self::leaf(), self::leaf()),
                0, 1, 0, 0,
            ],
            'non-boolean Assign with ternary rhs' => [
                // p(Assign(leaf, ternary))=2 → {tt:0, tf:2, ff:0, pp:0}
                new Assign(self::leaf(), self::ternaryLeaf()),
                0, 2, 0, 0,
            ],
        ];
    }

    #[DataProvider('expressionPathsProvider')]
    public function testExpressionPaths(Expr $expr, int $expectedT, int $expectedF, int $expectedP): void
    {
        $result = ExpressionPathAnalyzer::expressionPaths($expr);

        $this->assertSame($expectedT, $result['t'], 't');
        $this->assertSame($expectedF, $result['f'], 'f');
        $this->assertSame($expectedP, $result['p'], 'p');
    }

    #[DataProvider('expressionPathsDoubleProvider')]
    public function testExpressionPathsDouble(Expr $expr, int $expectedTt, int $expectedTf, int $expectedFf, int $expectedPp): void
    {
        $result = ExpressionPathAnalyzer::expressionPathsDouble($expr);

        $this->assertSame($expectedTt, $result['tt'], 'tt');
        $this->assertSame($expectedTf, $result['tf'], 'tf');
        $this->assertSame($expectedFf, $result['ff'], 'ff');
        $this->assertSame($expectedPp, $result['pp'], 'pp');
    }

    private static function leaf(): Variable
    {
        return new Variable('x');
    }

    /**
     * A ternary with three leaf operands: {t:2, f:2, p:2}.
     */
    private static function ternaryLeaf(): Ternary
    {
        return new Ternary(self::leaf(), self::leaf(), self::leaf());
    }
}
