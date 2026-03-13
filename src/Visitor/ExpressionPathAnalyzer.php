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
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;

final class ExpressionPathAnalyzer
{
    /**
     * Returns {t, f, p} for an expression.
     *
     * t = paths that evaluate to true
     * f = paths that evaluate to false
     * p = total paths (t + f for boolean, or just paths for non-boolean)
     *
     * @return array{t: int, f: int, p: int}
     */
    public static function expressionPaths(Expr $expr): array
    {
        if ($expr instanceof BooleanNot) {
            ['t' => $t, 'f' => $f, 'p' => $p] = self::expressionPaths($expr->expr);

            return ['t' => $f, 'f' => $t, 'p' => $p];
        }

        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = self::expressionPaths($expr->left);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = self::expressionPaths($expr->right);

            return [
                't' => $t1 * $t2,
                'f' => $f1 + $t1 * $f2,
                'p' => $f1 + $t1 * $p2,
            ];
        }

        if ($expr instanceof BooleanOr || $expr instanceof LogicalOr) {
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = self::expressionPaths($expr->left);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = self::expressionPaths($expr->right);

            return [
                't' => $t1 + $f1 * $t2,
                'f' => $f1 * $f2,
                'p' => $t1 + $f1 * $p2,
            ];
        }

        if ($expr instanceof Ternary) {
            if ($expr->if === null) {
                // Elvis operator (E1 ?: E2) - same as ||
                ['t' => $t1, 'f' => $f1, 'p' => $p1] = self::expressionPaths($expr->cond);
                ['t' => $t2, 'f' => $f2, 'p' => $p2] = self::expressionPaths($expr->else);

                return [
                    't' => $t1 + $f1 * $t2,
                    'f' => $f1 * $f2,
                    'p' => $t1 + $f1 * $p2,
                ];
            }

            ['t' => $t1, 'f' => $f1, 'p' => $p1] = self::expressionPaths($expr->cond);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = self::expressionPaths($expr->if);
            ['t' => $t3, 'f' => $f3, 'p' => $p3] = self::expressionPaths($expr->else);

            return [
                't' => $t1 * $t2 + $f1 * $t3,
                'f' => $t1 * $f2 + $f1 * $f3,
                'p' => $t1 * $p2 + $f1 * $p3,
            ];
        }

        if ($expr instanceof Coalesce) {
            // Same as ||
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = self::expressionPaths($expr->left);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = self::expressionPaths($expr->right);

            return [
                't' => $t1 + $f1 * $t2,
                'f' => $f1 * $f2,
                'p' => $t1 + $f1 * $p2,
            ];
        }

        if ($expr instanceof Match_) {
            $totalP = 0;

            foreach ($expr->arms as $arm) {
                if ($arm->conds !== null) {
                    foreach ($arm->conds as $cond) {
                        $totalP += self::expressionPaths($cond)['p'];
                    }
                }

                $totalP += self::expressionPaths($arm->body)['p'];
            }

            return ['t' => $totalP, 'f' => $totalP, 'p' => $totalP];
        }

        if ($expr instanceof Assign || $expr instanceof AssignOp) {
            $pVar  = self::expressionPaths($expr->var)['p'];
            $pExpr = self::expressionPaths($expr->expr)['p'];
            $p     = $pVar * $pExpr;

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        if ($expr instanceof BinaryOp) {
            $p1 = self::expressionPaths($expr->left)['p'];
            $p2 = self::expressionPaths($expr->right)['p'];
            $p  = $p1 * $p2;

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        if ($expr instanceof Cast || $expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            $p = self::expressionPaths($expr->expr)['p'];

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        // Leaf expression
        return ['t' => 1, 'f' => 1, 'p' => 1];
    }

    /**
     * Returns {tt, tf, ff, pp} for double-traversal path counting.
     *
     * Needed for while/do-while/foreach loop conditions.
     *
     * @return array{tt: int, tf: int, ff: int, pp: int}
     */
    public static function expressionPathsDouble(Expr $expr): array
    {
        if ($expr instanceof BooleanNot) {
            ['tt' => $tt, 'tf' => $tf, 'ff' => $ff, 'pp' => $pp] = self::expressionPathsDouble($expr->expr);

            return ['tt' => $ff, 'tf' => $tf, 'ff' => $tt, 'pp' => $pp];
        }

        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = self::expressionPaths($expr->left);
            ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = self::expressionPaths($expr->right);
            ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = self::expressionPathsDouble($expr->left);
            ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = self::expressionPathsDouble($expr->right);

            return [
                'tt' => $tt1 * $tt2,
                'tf' => $tf1 * $t2 + $tt1 * $tf2,
                'ff' => $ff1 + 2 * $tf1 * $f2 + $tt1 * $ff2,
                'pp' => $ff1 + 2 * $tf1 * $p2 + $tt1 * $pp2,
            ];
        }

        if ($expr instanceof BooleanOr || $expr instanceof LogicalOr) {
            ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = self::expressionPaths($expr->left);
            ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = self::expressionPaths($expr->right);
            ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = self::expressionPathsDouble($expr->left);
            ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = self::expressionPathsDouble($expr->right);

            return [
                'tt' => $tt1 + 2 * $tf1 * $t2 + $ff1 * $tt2,
                'tf' => $tf1 * $f2 + $ff1 * $tf2,
                'ff' => $ff1 * $ff2,
                'pp' => $tt1 + 2 * $tf1 * $p2 + $ff1 * $pp2,
            ];
        }

        if ($expr instanceof Ternary) {
            if ($expr->if === null) {
                // Elvis operator - same as ||
                ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = self::expressionPaths($expr->cond);
                ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = self::expressionPaths($expr->else);
                ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = self::expressionPathsDouble($expr->cond);
                ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = self::expressionPathsDouble($expr->else);

                return [
                    'tt' => $tt1 + 2 * $tf1 * $t2 + $ff1 * $tt2,
                    'tf' => $tf1 * $f2 + $ff1 * $tf2,
                    'ff' => $ff1 * $ff2,
                    'pp' => $tt1 + 2 * $tf1 * $p2 + $ff1 * $pp2,
                ];
            }

            ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = self::expressionPaths($expr->cond);
            ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = self::expressionPaths($expr->if);
            ['t'  => $t3, 'f' => $f3, 'p' => $p3]                    = self::expressionPaths($expr->else);
            ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = self::expressionPathsDouble($expr->cond);
            ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = self::expressionPathsDouble($expr->if);
            ['tt' => $tt3, 'tf' => $tf3, 'ff' => $ff3, 'pp' => $pp3] = self::expressionPathsDouble($expr->else);

            return [
                'tt' => $tt1 * $tt2 + 2 * $tf1 * $t2 * $t3 + $ff1 * $tt3,
                'tf' => $tt1 * $tf2 + $ff1 * $tf3 + $tf1 * ($t2 * $f3 + $f2 * $t3),
                'ff' => $tt1 * $ff2 + 2 * $tf1 * $f2 * $f3 + $ff1 * $ff3,
                'pp' => $tt1 * $pp2 + 2 * $tf1 * $p2 * $p3 + $ff1 * $pp3,
            ];
        }

        // For non-boolean expressions (comparisons, arithmetic, assignments, etc.),
        // use single-traversal path count as tf. This correctly handles comparisons
        // like $i < 10 used as while conditions.
        $p = self::expressionPaths($expr)['p'];

        return ['tt' => 0, 'tf' => $p, 'ff' => 0, 'pp' => 0];
    }
}
