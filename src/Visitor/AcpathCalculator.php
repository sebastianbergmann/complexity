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

use function array_slice;
use function count;
use function max;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Break_;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Continue_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;

final class AcpathCalculator
{
    /**
     * @param Stmt[] $statements
     *
     * @return positive-int
     */
    public function calculate(array $statements): int
    {
        ['ft' => $ft, 'bp' => $bp, 'cp' => $cp, 'rp' => $rp] = $this->statements($statements, 1, 0);

        return max(1, $ft + $rp);
    }

    /**
     * @param Stmt[] $statements
     *
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function statements(array $statements, int $ft, int $st): array
    {
        $bp = 0;
        $cp = 0;
        $rp = 0;

        foreach ($statements as $stmt) {
            ['ft' => $ft, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->statement($stmt, $ft, $st);
            $bp += $bpS;
            $cp += $cpS;
            $rp += $rpS;
        }

        return ['ft' => $ft, 'bp' => $bp, 'cp' => $cp, 'rp' => $rp];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function statement(Stmt $stmt, int $ft, int $st): array
    {
        if ($stmt instanceof Expression) {
            $p = ExpressionPathAnalyzer::expressionPaths($stmt->expr)['p'];

            return ['ft' => $p * $ft, 'bp' => 0, 'cp' => 0, 'rp' => 0];
        }

        if ($stmt instanceof Return_) {
            if ($stmt->expr === null) {
                return ['ft' => 0, 'bp' => 0, 'cp' => 0, 'rp' => $ft];
            }

            $p = ExpressionPathAnalyzer::expressionPaths($stmt->expr)['p'];

            return ['ft' => 0, 'bp' => 0, 'cp' => 0, 'rp' => $p * $ft];
        }

        if ($stmt instanceof If_) {
            return $this->processIf($stmt, $ft, $st);
        }

        if ($stmt instanceof Switch_) {
            return $this->processSwitch($stmt, $ft, $st);
        }

        if ($stmt instanceof While_) {
            return $this->processWhile($stmt, $ft, $st);
        }

        if ($stmt instanceof Do_) {
            return $this->processDo($stmt, $ft, $st);
        }

        if ($stmt instanceof For_) {
            return $this->processFor($stmt, $ft, $st);
        }

        if ($stmt instanceof Foreach_) {
            return $this->processForeach($stmt, $ft, $st);
        }

        if ($stmt instanceof Break_) {
            return ['ft' => 0, 'bp' => $ft, 'cp' => 0, 'rp' => 0];
        }

        if ($stmt instanceof Continue_) {
            return ['ft' => 0, 'bp' => 0, 'cp' => $ft, 'rp' => 0];
        }

        if ($stmt instanceof TryCatch) {
            return $this->processTryCatch($stmt, $ft, $st);
        }

        if ($stmt instanceof Block) {
            return $this->statements($stmt->stmts, $ft, $st);
        }

        // Other statements (Noop, Echo, Unset, Global, Static, etc.)
        return ['ft' => $ft, 'bp' => 0, 'cp' => 0, 'rp' => 0];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processIf(If_ $stmt, int $ft, int $st): array
    {
        ['t' => $t, 'f' => $f, 'p' => $p] = ExpressionPathAnalyzer::expressionPaths($stmt->cond);

        if ($stmt->elseifs !== []) {
            // Desugar elseif chains to nested if/else
            $elseIfNode       = $stmt->elseifs[0];
            $innerIf          = new If_($elseIfNode->cond);
            $innerIf->stmts   = $elseIfNode->stmts;
            $innerIf->elseifs = array_slice($stmt->elseifs, 1);
            $innerIf->else    = $stmt->else;

            $elseStmts = [$innerIf];

            ['ft' => $ft1, 'bp' => $bp1, 'cp' => $cp1, 'rp' => $rp1] = $this->statements($stmt->stmts, $t * $ft, $st);
            ['ft' => $ft2, 'bp' => $bp2, 'cp' => $cp2, 'rp' => $rp2] = $this->statements($elseStmts, $f * $ft, $st);

            return [
                'ft' => $ft1 + $ft2,
                'bp' => $bp1 + $bp2,
                'cp' => $cp1 + $cp2,
                'rp' => $rp1 + $rp2,
            ];
        }

        if ($stmt->else !== null) {
            // if/else
            ['ft' => $ft1, 'bp' => $bp1, 'cp' => $cp1, 'rp' => $rp1] = $this->statements($stmt->stmts, $t * $ft, $st);
            ['ft' => $ft2, 'bp' => $bp2, 'cp' => $cp2, 'rp' => $rp2] = $this->statements($stmt->else->stmts, $f * $ft, $st);

            return [
                'ft' => $ft1 + $ft2,
                'bp' => $bp1 + $bp2,
                'cp' => $cp1 + $cp2,
                'rp' => $rp1 + $rp2,
            ];
        }

        // if without else
        ['ft' => $ft1, 'bp' => $bp1, 'cp' => $cp1, 'rp' => $rp1] = $this->statements($stmt->stmts, $t * $ft, $st);

        return [
            'ft' => $ft1 + $f * $ft,
            'bp' => $bp1,
            'cp' => $cp1,
            'rp' => $rp1,
        ];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processSwitch(Switch_ $stmt, int $ft, int $st): array
    {
        $p          = ExpressionPathAnalyzer::expressionPaths($stmt->cond)['p'];
        $switchSt   = $p * $ft;
        $hasDefault = false;

        foreach ($stmt->cases as $case) {
            if ($case->cond === null) {
                $hasDefault = true;

                break;
            }
        }

        // Process the switch body as sequential case statements
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processSwitchBody($stmt->cases, 0, $switchSt);

        $ftOut = $ftS + $bpS;

        if (!$hasDefault) {
            $ftOut += $p * $ft;
        }

        return ['ft' => $ftOut, 'bp' => 0, 'cp' => $cpS, 'rp' => $rpS];
    }

    /**
     * @param Case_[] $cases
     *
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processSwitchBody(array $cases, int $ft, int $st): array
    {
        $bp = 0;
        $cp = 0;
        $rp = 0;

        foreach ($cases as $case) {
            // Each case label adds st to the current ft (switch-to paths)
            $ft += $st;

            // Process the case body statements
            foreach ($case->stmts as $caseStmt) {
                ['ft' => $ft, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->statement($caseStmt, $ft, $st);
                $bp += $bpS;
                $cp += $cpS;
                $rp += $rpS;
            }
        }

        return ['ft' => $ft, 'bp' => $bp, 'cp' => $cp, 'rp' => $rp];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processWhile(While_ $stmt, int $ft, int $st): array
    {
        ['t'  => $t, 'f' => $f, 'p' => $p]                       = ExpressionPathAnalyzer::expressionPaths($stmt->cond);
        ['tf' => $tf]                                            = ExpressionPathAnalyzer::expressionPathsDouble($stmt->cond);
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->statements($stmt->stmts, $ft, $st);

        $ftOut = $f * $ft + $bpS * $t + ($ftS + $cpS) * $tf;

        return ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processDo(Do_ $stmt, int $ft, int $st): array
    {
        ['f' => $f]                                              = ExpressionPathAnalyzer::expressionPaths($stmt->cond);
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->statements($stmt->stmts, $ft, $st);

        $ftOut = $f * $ftS + $bpS;

        return ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processFor(For_ $stmt, int $ft, int $st): array
    {
        // Desugar: for(E1; E2; E3) S  =>  E1; while(E2) { S; E3; }
        // Process init expressions
        foreach ($stmt->init as $initExpr) {
            $ft = ExpressionPathAnalyzer::expressionPaths($initExpr)['p'] * $ft;
        }

        // Build the condition: use first cond expression, or treat as leaf if empty
        if ($stmt->cond !== []) {
            $condExpr = $stmt->cond[0];

            for ($i = 1; $i < count($stmt->cond); $i++) {
                $condExpr = new BooleanAnd($condExpr, $stmt->cond[$i]);
            }
        } else {
            // Empty condition means always true - use a leaf
            $condExpr = new Expr\ConstFetch(new Name('true'));
        }

        // Body = S + E3 expressions as statements
        $bodyStmts = $stmt->stmts;

        foreach ($stmt->loop as $loopExpr) {
            $bodyStmts[] = new Expression($loopExpr);
        }

        ['t'  => $t, 'f' => $f, 'p' => $p]                       = ExpressionPathAnalyzer::expressionPaths($condExpr);
        ['tf' => $tf]                                            = ExpressionPathAnalyzer::expressionPathsDouble($condExpr);
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->statements($bodyStmts, $ft, $st);

        $ftOut = $f * $ft + $bpS * $t + ($ftS + $cpS) * $tf;

        return ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processForeach(Foreach_ $stmt, int $ft, int $st): array
    {
        // Treat like while with leaf condition (t=1, f=1, tf=1)
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->statements($stmt->stmts, $ft, $st);

        $ftOut = 1 * $ft + $bpS * 1 + ($ftS + $cpS) * 1;

        return ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processTryCatch(TryCatch $stmt, int $ft, int $st): array
    {
        ['ft' => $ftTry, 'bp' => $bpTry, 'cp' => $cpTry, 'rp' => $rpTry] = $this->statements($stmt->stmts, $ft, $st);

        $ftOut = $ftTry;
        $bp    = $bpTry;
        $cp    = $cpTry;
        $rp    = $rpTry;

        foreach ($stmt->catches as $catch) {
            ['ft' => $ftCatch, 'bp' => $bpCatch, 'cp' => $cpCatch, 'rp' => $rpCatch] = $this->statements($catch->stmts, $ft, $st);

            $ftOut += $ftCatch;
            $bp    += $bpCatch;
            $cp    += $cpCatch;
            $rp    += $rpCatch;
        }

        if ($stmt->finally !== null) {
            // Finally block always executes, so thread ft through it
            ['ft' => $ftOut, 'bp' => $bpF, 'cp' => $cpF, 'rp' => $rpF] = $this->statements($stmt->finally->stmts, $ftOut, $st);

            $bp += $bpF;
            $cp += $cpF;
            $rp += $rpF;
        }

        return ['ft' => $ftOut, 'bp' => $bp, 'cp' => $cp, 'rp' => $rp];
    }
}
