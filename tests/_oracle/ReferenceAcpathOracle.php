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

use function array_pop;
use function array_reverse;
use function array_slice;
use function assert;
use function count;
use function is_array;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Goto_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Label;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;

/**
 * Test oracle for the ACPATH metric.
 *
 * Implements, as literally as practical, the semantics of
 *
 *   Bagnara, Bagnara, Benedetti, Hill:
 *   "The ACPATH Metric: Precise Estimation of the Number of Acyclic Paths
 *   in C-like Languages", arXiv:1610.07914v4 (2024)
 *
 * It builds the reference control flow graph of Definition 3 at optimization
 * level 0 (no branch removal for constants) and then counts acyclic paths by
 * brute force according to Definition 2: a path from the entry node to an
 * exit node that does not traverse any arc more than once. By Theorem 2, for
 * controlled function bodies this count equals ACPATH, so any disagreement
 * with AcpathCalculator is a defect in one of the two implementations.
 *
 * The counting is exponential in the worst case (Theorem 1); this class is a
 * test oracle for small functions, not a production calculator.
 *
 * PHP constructs that do not exist in the paper's C subset are modeled with
 * the following documented decisions:
 *
 * - `??` and nullsafe operators branch like `||` / `?:` (short circuit).
 * - `match` evaluates the subject, then the arm conditions in order as
 *   decision nodes; a missing `default` arm adds an implicit exceptional
 *   exit (UnhandledMatchError).
 * - `throw` and `exit` terminate the function: they are exit nodes.
 * - `foreach` is a while loop with a fresh single-node guard; the iterable
 *   expression is evaluated once before the loop.
 * - try/catch: control branches at the top of `try` to the try block and to
 *   every catch block; a `finally` block is on every branch's continuation.
 *   (Which statements can throw is not tracked, matching AcpathCalculator.)
 * - `break n` / `continue n` honor their level; `continue` directly inside
 *   `switch` behaves like `break`, as it does in PHP.
 * - `continue` inside `for` jumps to the loop expressions, as it does in
 *   PHP (the paper's desugaring in eq. (26) would skip them).
 * - Closures and arrow functions are leaves: their bodies are separate
 *   code units and do not contribute paths to the enclosing function.
 * - Expressions without dedicated CFG rules (calls, `new`, array access,
 *   non-short-circuit binary operators, ...) follow the "other operators"
 *   rules (9) and (14): operands are evaluated in sequence, followed by a
 *   fresh branch node.
 * - `switch` uses the paper's jump-table model (eq. 23): case expressions
 *   are labels and are not evaluated. This differs from PHP's sequential
 *   comparison semantics only when a case expression itself branches.
 *
 * Not supported (throws RuntimeException): goto and labels.
 */
final class ReferenceAcpathOracle
{
    private int $nextNode = 0;

    /**
     * @var array<int, list<int>>
     */
    private array $successors = [];

    /**
     * @var array<string, true>
     */
    private array $arcs = [];

    /**
     * Innermost frame last. `continue` in PHP treats `switch` as a breakable
     * structure, hence one frame per loop or switch.
     *
     * @var list<array{break: int, continue: int}>
     */
    private array $structureStack = [];

    /**
     * @param Stmt[] $statements
     *
     * @return positive-int
     */
    public function countAcyclicPaths(array $statements): int
    {
        $this->nextNode       = 0;
        $this->successors     = [];
        $this->arcs           = [];
        $this->structureStack = [];

        $exit  = $this->node();
        $entry = $this->statementSequence($statements, $exit);

        $paths = $this->acyclicPathsFrom($entry, []);

        assert($paths >= 1);

        return $paths;
    }

    private function node(): int
    {
        $id                    = $this->nextNode++;
        $this->successors[$id] = [];

        return $id;
    }

    /**
     * Arcs form a set (Definition 1): adding an arc twice is a no-op. This is
     * load-bearing: expressions built with identical true- and false-targets
     * collapse their outcome arcs into one, exactly as in the paper.
     */
    private function arc(int $from, int $to): void
    {
        $key = $from . ':' . $to;

        if (isset($this->arcs[$key])) {
            return;
        }

        $this->arcs[$key]          = true;
        $this->successors[$from][] = $to;
    }

    /**
     * Sequential composition, eq. (18): the last statement is built first,
     * each earlier statement continues into its successor.
     *
     * @param Stmt[] $statements
     */
    private function statementSequence(array $statements, int $t): int
    {
        for ($i = count($statements) - 1; $i >= 0; $i--) {
            $t = $this->statement($statements[$i], $t);
        }

        return $t;
    }

    /**
     * Builds the CFG for a statement with continuation $t and returns the
     * statement's entry node.
     */
    private function statement(Stmt $stmt, int $t): int
    {
        if ($stmt instanceof Expression) {
            // eq. (17)
            return $this->expression($stmt->expr, $t, $t);
        }

        if ($stmt instanceof Return_) {
            // eq. (19) and (20): a return node has no successors
            $return = $this->node();

            if ($stmt->expr === null) {
                return $return;
            }

            return $this->expression($stmt->expr, $return, $return);
        }

        if ($stmt instanceof If_) {
            return $this->buildIf($stmt, $t);
        }

        if ($stmt instanceof Switch_) {
            return $this->buildSwitch($stmt, $t);
        }

        if ($stmt instanceof While_) {
            return $this->buildWhile($stmt->cond, $stmt->stmts, $t);
        }

        if ($stmt instanceof Do_) {
            return $this->buildDoWhile($stmt, $t);
        }

        if ($stmt instanceof For_) {
            return $this->buildFor($stmt, $t);
        }

        if ($stmt instanceof Foreach_) {
            return $this->buildForeach($stmt, $t);
        }

        if ($stmt instanceof Break_) {
            // eq. (27)
            $node = $this->node();

            $this->arc($node, $this->jumpTarget($stmt->num, 'break'));

            return $node;
        }

        if ($stmt instanceof Continue_) {
            // eq. (28)
            $node = $this->node();

            $this->arc($node, $this->jumpTarget($stmt->num, 'continue'));

            return $node;
        }

        if ($stmt instanceof TryCatch) {
            return $this->buildTryCatch($stmt, $t);
        }

        if ($stmt instanceof Block) {
            // eq. (31)
            return $this->statementSequence($stmt->stmts, $t);
        }

        if ($stmt instanceof Echo_) {
            return $this->expressionSequence($stmt->exprs, $t);
        }

        if ($stmt instanceof Goto_ || $stmt instanceof Label) {
            throw new RuntimeException('goto and labels are not supported by the ACPATH oracle');
        }

        // eq. (32): other statements are transparent
        return $t;
    }

    private function buildIf(If_ $stmt, int $t): int
    {
        $elseStart = null;

        if ($stmt->elseifs !== []) {
            // elseif chains are syntactic sugar for nested if/else
            $inner          = new If_($stmt->elseifs[0]->cond);
            $inner->stmts   = $stmt->elseifs[0]->stmts;
            $inner->elseifs = array_slice($stmt->elseifs, 1);
            $inner->else    = $stmt->else;

            $elseStart = $this->buildIf($inner, $t);
        } elseif ($stmt->else !== null) {
            // eq. (21): each branch continues into its own stub node
            $elseStub = $this->node();

            $this->arc($elseStub, $t);

            $elseStart = $this->statementSequence($stmt->else->stmts, $elseStub);
        }

        $thenStub = $this->node();

        $this->arc($thenStub, $t);

        $thenStart = $this->statementSequence($stmt->stmts, $thenStub);

        if ($elseStart === null) {
            // eq. (22): without else, the false outcome continues directly
            return $this->expression($stmt->cond, $thenStart, $t);
        }

        return $this->expression($stmt->cond, $thenStart, $elseStart);
    }

    /**
     * eq. (23): the guard reaches a dispatch node which has one arc per case
     * label; case bodies fall through into the next label. Without a default
     * label the dispatch node additionally continues past the switch.
     */
    private function buildSwitch(Switch_ $stmt, int $t): int
    {
        $out = $this->node();

        $this->arc($out, $t);

        $this->structureStack[] = ['break' => $out, 'continue' => $out];

        $labels      = [];
        $hasDefault  = false;
        $fallThrough = $out;

        foreach (array_reverse($stmt->cases) as $case) {
            if ($case->cond === null) {
                $hasDefault = true;
            }

            $body  = $this->statementSequence($case->stmts, $fallThrough);
            $label = $this->node();

            $this->arc($label, $body);

            $labels[]    = $label;
            $fallThrough = $label;
        }

        array_pop($this->structureStack);

        $dispatch = $this->node();

        foreach ($labels as $label) {
            $this->arc($dispatch, $label);
        }

        if (!$hasDefault) {
            $this->arc($dispatch, $out);
        }

        return $this->expression($stmt->cond, $dispatch, $dispatch);
    }

    /**
     * eq. (24). The guard is the loop's entry; its true outcome reaches the
     * body through a stub, the body continues into a stub with a back arc to
     * the guard. The back arc is what allows one guard re-traversal on
     * arc-disjoint paths.
     *
     * @param Stmt[] $body
     */
    private function buildWhile(Expr $cond, array $body, int $t): int
    {
        $guardTrueStub = $this->node();
        $guardStart    = $this->expression($cond, $guardTrueStub, $t);
        $backStub      = $this->node();

        $this->arc($backStub, $guardStart);

        $this->structureStack[] = ['break' => $t, 'continue' => $guardStart];

        $bodyStart = $this->statementSequence($body, $backStub);

        array_pop($this->structureStack);

        $this->arc($guardTrueStub, $bodyStart);

        return $guardStart;
    }

    /**
     * eq. (25): the body is the entry; the guard's true outcome is a back
     * arc to the body which no acyclic path can cross.
     */
    private function buildDoWhile(Do_ $stmt, int $t): int
    {
        $guardTrueStub = $this->node();
        $guardStart    = $this->expression($stmt->cond, $guardTrueStub, $t);
        $bodyStub      = $this->node();

        $this->arc($bodyStub, $guardStart);

        $this->structureStack[] = ['break' => $t, 'continue' => $guardStart];

        $bodyStart = $this->statementSequence($stmt->stmts, $bodyStub);

        array_pop($this->structureStack);

        $this->arc($guardTrueStub, $bodyStart);

        return $bodyStart;
    }

    /**
     * eq. (26): for (E1; E2; E3) S desugars to E1; while (E2) { S E3; }.
     *
     * Deviations from the paper, both following actual PHP semantics:
     * multiple guard expressions are evaluated in sequence with only the
     * last one deciding (comma operator, eq. (12), not `&&`), and `continue`
     * jumps to the loop expressions instead of skipping them.
     *
     * An empty guard is an always-true constant, which at optimization
     * level 0 still is a branch node (Definition 3, eq. (6)).
     */
    private function buildFor(For_ $stmt, int $t): int
    {
        $guard = static function (self $self, int $guardTrue, int $guardFalse) use ($stmt): int {
            if ($stmt->cond === []) {
                $node = $self->node();

                $self->arc($node, $guardTrue);
                $self->arc($node, $guardFalse);

                return $node;
            }

            $conditions = $stmt->cond;
            $last       = array_pop($conditions);
            $start      = $self->expression($last, $guardTrue, $guardFalse);

            foreach (array_reverse($conditions) as $condition) {
                $start = $self->expression($condition, $start, $start);
            }

            return $start;
        };

        // Inline eq. (24) with a custom guard and the loop expressions as
        // the body's continuation (and `continue` target).
        $guardTrueStub = $this->node();
        $backStub      = $this->node();
        $guardStart    = $guard($this, $guardTrueStub, $t);

        $this->arc($backStub, $guardStart);

        $loopExprStart = $this->expressionSequence($stmt->loop, $backStub);

        $this->structureStack[] = ['break' => $t, 'continue' => $loopExprStart];

        $bodyStart = $this->statementSequence($stmt->stmts, $loopExprStart);

        array_pop($this->structureStack);

        $this->arc($guardTrueStub, $bodyStart);

        return $this->expressionSequence($stmt->init, $guardStart);
    }

    /**
     * A while loop with a fresh single-node guard ("are there more
     * elements?"); the iterable expression is evaluated once before the
     * loop. Key and value bindings are linear and add no paths.
     */
    private function buildForeach(Foreach_ $stmt, int $t): int
    {
        $guardTrueStub = $this->node();
        $guard         = $this->node();

        $this->arc($guard, $guardTrueStub);
        $this->arc($guard, $t);

        $backStub = $this->node();

        $this->arc($backStub, $guard);

        $this->structureStack[] = ['break' => $t, 'continue' => $guard];

        $bodyStart = $this->statementSequence($stmt->stmts, $backStub);

        array_pop($this->structureStack);

        $this->arc($guardTrueStub, $bodyStart);

        return $this->expression($stmt->expr, $guard, $guard);
    }

    /**
     * Control branches at the top of `try` to the try block and to every
     * catch block; all branches continue into the finally block (or directly
     * into the continuation). Each branch gets its own stub so that empty
     * blocks keep distinct arcs.
     */
    private function buildTryCatch(TryCatch $stmt, int $t): int
    {
        if ($stmt->finally !== null) {
            $t = $this->statementSequence($stmt->finally->stmts, $t);
        }

        $dispatch = $this->node();

        $branches = [$stmt->stmts];

        foreach ($stmt->catches as $catch) {
            $branches[] = $catch->stmts;
        }

        foreach ($branches as $branch) {
            $stub = $this->node();

            $this->arc($stub, $t);

            $this->arc($dispatch, $this->statementSequence($branch, $stub));
        }

        return $dispatch;
    }

    /**
     * Resolves the target of break/continue with an optional level, per PHP
     * semantics: `switch` counts as one level, and `continue` directly
     * inside a `switch` behaves like `break`.
     */
    private function jumpTarget(?Node $level, string $kind): int
    {
        $n = 1;

        if ($level !== null) {
            if (!$level instanceof Int_) {
                throw new RuntimeException('break/continue with a non-literal level is not supported by the ACPATH oracle');
            }

            $n = $level->value;
        }

        $frame = $this->structureStack[count($this->structureStack) - $n] ?? throw new RuntimeException($kind . ' ' . $n . ' has no matching enclosing structure');

        return $frame[$kind];
    }

    /**
     * Comma-style sequence of expressions, eq. (12): every expression is
     * evaluated, only control flow (not outcome) is propagated.
     *
     * @param list<Expr> $expressions
     */
    private function expressionSequence(array $expressions, int $t): int
    {
        foreach (array_reverse($expressions) as $expression) {
            $t = $this->expression($expression, $t, $t);
        }

        return $t;
    }

    /**
     * Builds the CFG for an expression with true-target $t and false-target
     * $f and returns the expression's entry node, per Definition 3.
     */
    private function expression(Expr $expr, int $t, int $f): int
    {
        if ($expr instanceof BooleanNot) {
            // eq. (7)
            return $this->expression($expr->expr, $f, $t);
        }

        if ($expr instanceof Cast || $expr instanceof UnaryMinus || $expr instanceof UnaryPlus || $expr instanceof Expr\ErrorSuppress) {
            // eq. (8): transparent
            return $this->expression($expr->expr, $t, $f);
        }

        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            // eq. (10)
            $right = $this->expression($expr->right, $t, $f);

            return $this->expression($expr->left, $right, $f);
        }

        if ($expr instanceof BooleanOr || $expr instanceof LogicalOr) {
            // eq. (11)
            $right = $this->expression($expr->right, $t, $f);

            return $this->expression($expr->left, $t, $right);
        }

        if ($expr instanceof Coalesce) {
            // PHP: short-circuits like eq. (11)/(13)
            $right = $this->expression($expr->right, $t, $f);

            return $this->expression($expr->left, $t, $right);
        }

        if ($expr instanceof Ternary) {
            if ($expr->if === null) {
                // eq. (13)
                $else = $this->expression($expr->else, $t, $f);

                return $this->expression($expr->cond, $t, $else);
            }

            // eq. (15)
            $then = $this->expression($expr->if, $t, $f);
            $else = $this->expression($expr->else, $t, $f);

            return $this->expression($expr->cond, $then, $else);
        }

        if ($expr instanceof Throw_ || $expr instanceof Exit_) {
            // exceptional exit: a node with no successors
            return $this->node();
        }

        if ($expr instanceof Match_) {
            return $this->buildMatch($expr, $t, $f);
        }

        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            // separate code unit: a leaf, eq. (5)
            $node = $this->node();

            $this->arc($node, $t);
            $this->arc($node, $f);

            return $node;
        }

        if ($expr instanceof Expr\NullsafePropertyFetch || $expr instanceof Expr\NullsafeMethodCall) {
            // short-circuits like ?: on the object being null
            $result = $this->node();

            $this->arc($result, $t);
            $this->arc($result, $f);

            $fetch = $this->node();

            $this->arc($fetch, $result);

            return $this->expression($expr->var, $fetch, $result);
        }

        // eq. (5), (6), (9), (14), generalized to n operands: evaluate all
        // subexpressions in sequence, then branch at a fresh node. With no
        // subexpressions this is exactly the leaf rule.
        $node = $this->node();

        $this->arc($node, $t);
        $this->arc($node, $f);

        return $this->expressionSequence($this->childExpressions($expr), $node);
    }

    /**
     * The subject is evaluated first, then the arm conditions act as
     * decision nodes in order; the first match evaluates that arm's body.
     * Without a default arm, exhausting all conditions is an exceptional
     * exit (UnhandledMatchError).
     */
    private function buildMatch(Match_ $expr, int $t, int $f): int
    {
        $defaultBody = null;
        $conditional = [];

        foreach ($expr->arms as $arm) {
            if ($arm->conds === null) {
                $defaultBody = $arm->body;

                continue;
            }

            $conditional[] = $arm;
        }

        if ($defaultBody !== null) {
            $noMatch = $this->expression($defaultBody, $t, $f);
        } else {
            $noMatch = $this->node();
        }

        foreach (array_reverse($conditional) as $arm) {
            $body = $this->expression($arm->body, $t, $f);

            foreach (array_reverse($arm->conds) as $cond) {
                $noMatch = $this->expression($cond, $body, $noMatch);
            }
        }

        return $this->expression($expr->cond, $noMatch, $noMatch);
    }

    /**
     * @return list<Expr>
     */
    private function childExpressions(Expr $expr): array
    {
        $children = [];

        foreach ($expr->getSubNodeNames() as $name) {
            $value = $expr->{$name};

            if ($value instanceof Expr) {
                $children[] = $value;

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if ($item instanceof Arg || $item instanceof ArrayItem) {
                    $item = $item->value;
                }

                if ($item instanceof Expr) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }

    /**
     * Definition 2: counts paths from $node to any exit node (a node without
     * successors) that use each arc at most once.
     *
     * @param array<string, true> $usedArcs
     */
    private function acyclicPathsFrom(int $node, array $usedArcs): int
    {
        if ($this->successors[$node] === []) {
            return 1;
        }

        $paths = 0;

        foreach ($this->successors[$node] as $successor) {
            $arc = $node . ':' . $successor;

            if (isset($usedArcs[$arc])) {
                continue;
            }

            $usedArcs[$arc] = true;

            $paths += $this->acyclicPathsFrom($successor, $usedArcs);

            unset($usedArcs[$arc]);
        }

        return $paths;
    }
}
