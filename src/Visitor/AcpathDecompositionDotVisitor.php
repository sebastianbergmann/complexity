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
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_replace;
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
use PhpParser\PrettyPrinter\Standard;

final class AcpathDecompositionDotVisitor
{
    private int $nextId = 0;

    /** @var array<int, string> node ID => label */
    private array $nodeLabels = [];

    /** @var list<array{from: int, to: int, label: string}> */
    private array $edges = [];
    private Standard $printer;

    /**
     * @param Stmt[] $statements
     */
    public function generate(array $statements): string
    {
        $this->nextId     = 0;
        $this->nodeLabels = [];
        $this->edges      = [];
        $this->printer    = new Standard;

        $rootId = $this->addNode('');

        ['ft' => $ft, 'bp' => $bp, 'cp' => $cp, 'rp' => $rp] = $this->processStatements($statements, 1, 0, $rootId);

        $acpath = max(1, $ft + $rp);

        $this->setLabel($rootId, sprintf('function body|ft=%d, rp=%d|ACPATH = %d', $ft, $rp, $acpath));

        $dot = "digraph decomposition {\n";
        $dot .= "    rankdir=TB;\n";
        $dot .= "    node [shape=record, fontname=\"monospace\", fontsize=10];\n";

        foreach ($this->nodeLabels as $id => $label) {
            $dot .= sprintf(
                "    n%d [label=\"{%s}\"];\n",
                $id,
                $this->escape($label),
            );
        }

        foreach ($this->edges as $edge) {
            $dot .= sprintf(
                "    n%d -> n%d [label=\"%s\"];\n",
                $edge['from'],
                $edge['to'],
                $this->escape($edge['label']),
            );
        }

        $dot .= "}\n";

        return $dot;
    }

    /**
     * @param Stmt[] $statements
     *
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processStatements(array $statements, int $ft, int $st, int $parentId): array
    {
        $bp = 0;
        $cp = 0;
        $rp = 0;

        foreach ($statements as $stmt) {
            $stmtId = $this->addNode('');
            $this->addEdge($parentId, $stmtId, '× (sequential)');

            ['ft' => $ft, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processStatement($stmt, $ft, $st, $stmtId);
            $bp += $bpS;
            $cp += $cpS;
            $rp += $rpS;
        }

        return ['ft' => $ft, 'bp' => $bp, 'cp' => $cp, 'rp' => $rp];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processStatement(Stmt $stmt, int $ft, int $st, int $nodeId): array
    {
        if ($stmt instanceof Expression) {
            ['t' => $t, 'f' => $f, 'p' => $p] = $this->processExpressionPaths($stmt->expr, $nodeId);
            $this->setLabel($nodeId, sprintf(
                '%s|ft=%d, bp=0, cp=0, rp=0',
                $this->prettyPrintStmt($stmt),
                $p * $ft,
            ));

            return ['ft' => $p * $ft, 'bp' => 0, 'cp' => 0, 'rp' => 0];
        }

        if ($stmt instanceof Return_) {
            if ($stmt->expr === null) {
                $this->setLabel($nodeId, sprintf(
                    'return|ft=0, bp=0, cp=0, rp=%d',
                    $ft,
                ));

                return ['ft' => 0, 'bp' => 0, 'cp' => 0, 'rp' => $ft];
            }

            ['t' => $t, 'f' => $f, 'p' => $p] = $this->processExpressionPaths($stmt->expr, $nodeId);
            $this->setLabel($nodeId, sprintf(
                'return %s|ft=0, bp=0, cp=0, rp=%d',
                $this->prettyPrintExpr($stmt->expr),
                $p * $ft,
            ));

            return ['ft' => 0, 'bp' => 0, 'cp' => 0, 'rp' => $p * $ft];
        }

        if ($stmt instanceof If_) {
            return $this->processIf($stmt, $ft, $st, $nodeId);
        }

        if ($stmt instanceof Switch_) {
            return $this->processSwitch($stmt, $ft, $st, $nodeId);
        }

        if ($stmt instanceof While_) {
            return $this->processWhile($stmt, $ft, $st, $nodeId);
        }

        if ($stmt instanceof Do_) {
            return $this->processDo($stmt, $ft, $st, $nodeId);
        }

        if ($stmt instanceof For_) {
            return $this->processFor($stmt, $ft, $st, $nodeId);
        }

        if ($stmt instanceof Foreach_) {
            return $this->processForeach($stmt, $ft, $st, $nodeId);
        }

        if ($stmt instanceof Break_) {
            $this->setLabel($nodeId, sprintf(
                'break|ft=0, bp=%d, cp=0, rp=0',
                $ft,
            ));

            return ['ft' => 0, 'bp' => $ft, 'cp' => 0, 'rp' => 0];
        }

        if ($stmt instanceof Continue_) {
            $this->setLabel($nodeId, sprintf(
                'continue|ft=0, bp=0, cp=%d, rp=0',
                $ft,
            ));

            return ['ft' => 0, 'bp' => 0, 'cp' => $ft, 'rp' => 0];
        }

        if ($stmt instanceof TryCatch) {
            return $this->processTryCatch($stmt, $ft, $st, $nodeId);
        }

        if ($stmt instanceof Block) {
            $this->setLabel($nodeId, 'block');

            return $this->processStatements($stmt->stmts, $ft, $st, $nodeId);
        }

        // Other statements
        $this->setLabel($nodeId, sprintf(
            '%s|ft=%d, bp=0, cp=0, rp=0',
            $this->prettyPrintStmt($stmt),
            $ft,
        ));

        return ['ft' => $ft, 'bp' => 0, 'cp' => 0, 'rp' => 0];
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processIf(If_ $stmt, int $ft, int $st, int $nodeId): array
    {
        $condId = $this->addNode('');
        $this->addEdge($nodeId, $condId, 'condition');
        ['t' => $t, 'f' => $f, 'p' => $p] = $this->processExpressionPaths($stmt->cond, $condId);
        $this->setLabel($condId, sprintf(
            'if (%s)|t=%d, f=%d',
            $this->prettyPrintExpr($stmt->cond),
            $t,
            $f,
        ));

        if ($stmt->elseifs !== []) {
            $elseIfNode       = $stmt->elseifs[0];
            $innerIf          = new If_($elseIfNode->cond);
            $innerIf->stmts   = $elseIfNode->stmts;
            $innerIf->elseifs = array_slice($stmt->elseifs, 1);
            $innerIf->else    = $stmt->else;

            $thenId = $this->addNode('');
            $this->addEdge($nodeId, $thenId, '+ (true branch)');
            ['ft' => $ft1, 'bp' => $bp1, 'cp' => $cp1, 'rp' => $rp1] = $this->processStatements($stmt->stmts, $t * $ft, $st, $thenId);
            $this->setLabel($thenId, sprintf(
                'then|ft=%d, bp=%d, cp=%d, rp=%d',
                $ft1,
                $bp1,
                $cp1,
                $rp1,
            ));

            $elseId = $this->addNode('');
            $this->addEdge($nodeId, $elseId, '+ (false branch)');
            ['ft' => $ft2, 'bp' => $bp2, 'cp' => $cp2, 'rp' => $rp2] = $this->processStatements([$innerIf], $f * $ft, $st, $elseId);
            $this->setLabel($elseId, sprintf(
                'elseif|ft=%d, bp=%d, cp=%d, rp=%d',
                $ft2,
                $bp2,
                $cp2,
                $rp2,
            ));

            $result = ['ft' => $ft1 + $ft2, 'bp' => $bp1 + $bp2, 'cp' => $cp1 + $cp2, 'rp' => $rp1 + $rp2];

            $this->setLabel($nodeId, sprintf(
                'if|ft=%d, bp=%d, cp=%d, rp=%d',
                $result['ft'],
                $result['bp'],
                $result['cp'],
                $result['rp'],
            ));

            return $result;
        }

        if ($stmt->else !== null) {
            $thenId = $this->addNode('');
            $this->addEdge($nodeId, $thenId, '+ (true branch)');
            ['ft' => $ft1, 'bp' => $bp1, 'cp' => $cp1, 'rp' => $rp1] = $this->processStatements($stmt->stmts, $t * $ft, $st, $thenId);
            $this->setLabel($thenId, sprintf(
                'then|ft=%d, bp=%d, cp=%d, rp=%d',
                $ft1,
                $bp1,
                $cp1,
                $rp1,
            ));

            $elseId = $this->addNode('');
            $this->addEdge($nodeId, $elseId, '+ (false branch)');
            ['ft' => $ft2, 'bp' => $bp2, 'cp' => $cp2, 'rp' => $rp2] = $this->processStatements($stmt->else->stmts, $f * $ft, $st, $elseId);
            $this->setLabel($elseId, sprintf(
                'else|ft=%d, bp=%d, cp=%d, rp=%d',
                $ft2,
                $bp2,
                $cp2,
                $rp2,
            ));

            $result = ['ft' => $ft1 + $ft2, 'bp' => $bp1 + $bp2, 'cp' => $cp1 + $cp2, 'rp' => $rp1 + $rp2];

            $this->setLabel($nodeId, sprintf(
                'if/else|ft=%d, bp=%d, cp=%d, rp=%d',
                $result['ft'],
                $result['bp'],
                $result['cp'],
                $result['rp'],
            ));

            return $result;
        }

        // if without else
        $thenId = $this->addNode('');
        $this->addEdge($nodeId, $thenId, '+ (true branch)');
        ['ft' => $ft1, 'bp' => $bp1, 'cp' => $cp1, 'rp' => $rp1] = $this->processStatements($stmt->stmts, $t * $ft, $st, $thenId);
        $this->setLabel($thenId, sprintf(
            'then|ft=%d, bp=%d, cp=%d, rp=%d',
            $ft1,
            $bp1,
            $cp1,
            $rp1,
        ));

        $result = ['ft' => $ft1 + $f * $ft, 'bp' => $bp1, 'cp' => $cp1, 'rp' => $rp1];

        $this->setLabel($nodeId, sprintf(
            'if (no else)|ft=%d, bp=%d, cp=%d, rp=%d',
            $result['ft'],
            $result['bp'],
            $result['cp'],
            $result['rp'],
        ));

        return $result;
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processSwitch(Switch_ $stmt, int $ft, int $st, int $nodeId): array
    {
        $p          = $this->processExpressionPaths($stmt->cond, $nodeId)['p'];
        $switchSt   = $p * $ft;
        $hasDefault = false;

        foreach ($stmt->cases as $case) {
            if ($case->cond === null) {
                $hasDefault = true;

                break;
            }
        }

        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processSwitchBody($stmt->cases, 0, $switchSt, $nodeId);

        $ftOut = $ftS + $bpS;

        if (!$hasDefault) {
            $ftOut += $p * $ft;
        }

        $result = ['ft' => $ftOut, 'bp' => 0, 'cp' => $cpS, 'rp' => $rpS];

        $this->setLabel($nodeId, sprintf(
            'switch|ft=%d, bp=0, cp=%d, rp=%d',
            $result['ft'],
            $result['cp'],
            $result['rp'],
        ));

        return $result;
    }

    /**
     * @param Case_[] $cases
     *
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processSwitchBody(array $cases, int $ft, int $st, int $parentId): array
    {
        $bp = 0;
        $cp = 0;
        $rp = 0;

        foreach ($cases as $case) {
            $ft += $st;

            foreach ($case->stmts as $caseStmt) {
                $stmtId = $this->addNode('');
                $this->addEdge($parentId, $stmtId, '+ (case)');

                ['ft' => $ft, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processStatement($caseStmt, $ft, $st, $stmtId);
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
    private function processWhile(While_ $stmt, int $ft, int $st, int $nodeId): array
    {
        $condId = $this->addNode('');
        $this->addEdge($nodeId, $condId, 'condition');
        ['t' => $t, 'f' => $f, 'p' => $p] = $this->processExpressionPaths($stmt->cond, $condId);
        $this->setLabel($condId, sprintf(
            'while (%s)|t=%d, f=%d',
            $this->prettyPrintExpr($stmt->cond),
            $t,
            $f,
        ));

        $bodyId = $this->addNode('');
        $this->addEdge($nodeId, $bodyId, 'body');
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processStatements($stmt->stmts, $ft, $st, $bodyId);
        $this->setLabel($bodyId, sprintf(
            'while body|ft=%d, bp=%d, cp=%d, rp=%d',
            $ftS,
            $bpS,
            $cpS,
            $rpS,
        ));

        // Use the simple formula from AcpathCalculator
        ['t'  => $t2, 'f' => $f2, 'p' => $p2] = $this->expressionPaths($stmt->cond);
        ['tf' => $tf]                         = $this->expressionPathsDouble($stmt->cond);

        $ftOut = $f2 * $ft + $bpS * $t2 + ($ftS + $cpS) * $tf;

        $result = ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];

        $this->setLabel($nodeId, sprintf(
            'while|ft=%d, bp=0, cp=0, rp=%d',
            $result['ft'],
            $result['rp'],
        ));

        return $result;
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processDo(Do_ $stmt, int $ft, int $st, int $nodeId): array
    {
        $bodyId = $this->addNode('');
        $this->addEdge($nodeId, $bodyId, 'body');
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processStatements($stmt->stmts, $ft, $st, $bodyId);
        $this->setLabel($bodyId, sprintf(
            'do body|ft=%d, bp=%d, cp=%d, rp=%d',
            $ftS,
            $bpS,
            $cpS,
            $rpS,
        ));

        ['f' => $f] = $this->expressionPaths($stmt->cond);
        $ftOut      = $f * $ftS + $bpS;

        $result = ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];

        $this->setLabel($nodeId, sprintf(
            'do-while|ft=%d, bp=0, cp=0, rp=%d',
            $result['ft'],
            $result['rp'],
        ));

        return $result;
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processFor(For_ $stmt, int $ft, int $st, int $nodeId): array
    {
        foreach ($stmt->init as $initExpr) {
            $ft = $this->expressionPaths($initExpr)['p'] * $ft;
        }

        if ($stmt->cond !== []) {
            $condExpr = $stmt->cond[0];

            for ($i = 1; $i < count($stmt->cond); $i++) {
                $condExpr = new BooleanAnd($condExpr, $stmt->cond[$i]);
            }
        } else {
            $condExpr = new Expr\ConstFetch(new Name('true'));
        }

        $bodyStmts = $stmt->stmts;

        foreach ($stmt->loop as $loopExpr) {
            $bodyStmts[] = new Expression($loopExpr);
        }

        ['t'  => $t, 'f' => $f, 'p' => $p] = $this->expressionPaths($condExpr);
        ['tf' => $tf]                      = $this->expressionPathsDouble($condExpr);

        $bodyId = $this->addNode('');
        $this->addEdge($nodeId, $bodyId, 'body');
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processStatements($bodyStmts, $ft, $st, $bodyId);
        $this->setLabel($bodyId, sprintf(
            'for body|ft=%d, bp=%d, cp=%d, rp=%d',
            $ftS,
            $bpS,
            $cpS,
            $rpS,
        ));

        $ftOut = $f * $ft + $bpS * $t + ($ftS + $cpS) * $tf;

        $result = ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];

        $this->setLabel($nodeId, sprintf(
            'for|ft=%d, bp=0, cp=0, rp=%d',
            $result['ft'],
            $result['rp'],
        ));

        return $result;
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processForeach(Foreach_ $stmt, int $ft, int $st, int $nodeId): array
    {
        $bodyId = $this->addNode('');
        $this->addEdge($nodeId, $bodyId, 'body');
        ['ft' => $ftS, 'bp' => $bpS, 'cp' => $cpS, 'rp' => $rpS] = $this->processStatements($stmt->stmts, $ft, $st, $bodyId);
        $this->setLabel($bodyId, sprintf(
            'foreach body|ft=%d, bp=%d, cp=%d, rp=%d',
            $ftS,
            $bpS,
            $cpS,
            $rpS,
        ));

        $ftOut = 1 * $ft + $bpS * 1 + ($ftS + $cpS) * 1;

        $result = ['ft' => $ftOut, 'bp' => 0, 'cp' => 0, 'rp' => $rpS];

        $this->setLabel($nodeId, sprintf(
            'foreach|ft=%d, bp=0, cp=0, rp=%d',
            $result['ft'],
            $result['rp'],
        ));

        return $result;
    }

    /**
     * @return array{ft: int, bp: int, cp: int, rp: int}
     */
    private function processTryCatch(TryCatch $stmt, int $ft, int $st, int $nodeId): array
    {
        $tryId = $this->addNode('');
        $this->addEdge($nodeId, $tryId, '+ (try)');
        ['ft' => $ftTry, 'bp' => $bpTry, 'cp' => $cpTry, 'rp' => $rpTry] = $this->processStatements($stmt->stmts, $ft, $st, $tryId);
        $this->setLabel($tryId, sprintf(
            'try|ft=%d, bp=%d, cp=%d, rp=%d',
            $ftTry,
            $bpTry,
            $cpTry,
            $rpTry,
        ));

        $ftOut = $ftTry;
        $bp    = $bpTry;
        $cp    = $cpTry;
        $rp    = $rpTry;

        foreach ($stmt->catches as $catch) {
            $catchId = $this->addNode('');
            $this->addEdge($nodeId, $catchId, '+ (catch)');
            ['ft' => $ftCatch, 'bp' => $bpCatch, 'cp' => $cpCatch, 'rp' => $rpCatch] = $this->processStatements($catch->stmts, $ft, $st, $catchId);
            $this->setLabel($catchId, sprintf(
                'catch|ft=%d, bp=%d, cp=%d, rp=%d',
                $ftCatch,
                $bpCatch,
                $cpCatch,
                $rpCatch,
            ));

            $ftOut += $ftCatch;
            $bp    += $bpCatch;
            $cp    += $cpCatch;
            $rp    += $rpCatch;
        }

        if ($stmt->finally !== null) {
            $finallyId = $this->addNode('');
            $this->addEdge($nodeId, $finallyId, '× (finally)');
            ['ft' => $ftOut, 'bp' => $bpF, 'cp' => $cpF, 'rp' => $rpF] = $this->processStatements($stmt->finally->stmts, $ftOut, $st, $finallyId);
            $this->setLabel($finallyId, sprintf(
                'finally|ft=%d, bp=%d, cp=%d, rp=%d',
                $ftOut,
                $bpF,
                $cpF,
                $rpF,
            ));
            $bp += $bpF;
            $cp += $cpF;
            $rp += $rpF;
        }

        $result = ['ft' => $ftOut, 'bp' => $bp, 'cp' => $cp, 'rp' => $rp];

        $this->setLabel($nodeId, sprintf(
            'try/catch|ft=%d, bp=%d, cp=%d, rp=%d',
            $result['ft'],
            $result['bp'],
            $result['cp'],
            $result['rp'],
        ));

        return $result;
    }

    /**
     * @return array{t: int, f: int, p: int}
     */
    private function processExpressionPaths(Expr $expr, int $parentId): array
    {
        if ($expr instanceof BooleanNot) {
            $childId = $this->addNode('');
            $this->addEdge($parentId, $childId, 'NOT');
            ['t' => $t, 'f' => $f, 'p' => $p] = $this->processExpressionPaths($expr->expr, $childId);
            $result                           = ['t' => $f, 'f' => $t, 'p' => $p];
            $this->setLabel($childId, sprintf(
                '!(%s)|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->expr),
                $result['t'],
                $result['f'],
                $result['p'],
            ));

            return $result;
        }

        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            $leftId = $this->addNode('');
            $this->addEdge($parentId, $leftId, 'left');
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->processExpressionPaths($expr->left, $leftId);
            $this->setLabel($leftId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->left),
                $t1,
                $f1,
                $p1,
            ));

            $rightId = $this->addNode('');
            $this->addEdge($parentId, $rightId, 'right (&&)');
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->processExpressionPaths($expr->right, $rightId);
            $this->setLabel($rightId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->right),
                $t2,
                $f2,
                $p2,
            ));

            return [
                't' => $t1 * $t2,
                'f' => $f1 + $t1 * $f2,
                'p' => $f1 + $t1 * $p2,
            ];
        }

        if ($expr instanceof BooleanOr || $expr instanceof LogicalOr) {
            $leftId = $this->addNode('');
            $this->addEdge($parentId, $leftId, 'left');
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->processExpressionPaths($expr->left, $leftId);
            $this->setLabel($leftId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->left),
                $t1,
                $f1,
                $p1,
            ));

            $rightId = $this->addNode('');
            $this->addEdge($parentId, $rightId, 'right (||)');
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->processExpressionPaths($expr->right, $rightId);
            $this->setLabel($rightId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->right),
                $t2,
                $f2,
                $p2,
            ));

            return [
                't' => $t1 + $f1 * $t2,
                'f' => $f1 * $f2,
                'p' => $t1 + $f1 * $p2,
            ];
        }

        if ($expr instanceof Ternary) {
            if ($expr->if === null) {
                // Elvis
                $condId = $this->addNode('');
                $this->addEdge($parentId, $condId, 'left');
                ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->processExpressionPaths($expr->cond, $condId);
                $this->setLabel($condId, sprintf(
                    '%s|t=%d, f=%d, p=%d',
                    $this->prettyPrintExpr($expr->cond),
                    $t1,
                    $f1,
                    $p1,
                ));

                $elseId = $this->addNode('');
                $this->addEdge($parentId, $elseId, 'right (?:)');
                ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->processExpressionPaths($expr->else, $elseId);
                $this->setLabel($elseId, sprintf(
                    '%s|t=%d, f=%d, p=%d',
                    $this->prettyPrintExpr($expr->else),
                    $t2,
                    $f2,
                    $p2,
                ));

                return [
                    't' => $t1 + $f1 * $t2,
                    'f' => $f1 * $f2,
                    'p' => $t1 + $f1 * $p2,
                ];
            }

            $condId = $this->addNode('');
            $this->addEdge($parentId, $condId, 'condition');
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->processExpressionPaths($expr->cond, $condId);
            $this->setLabel($condId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->cond),
                $t1,
                $f1,
                $p1,
            ));

            $ifId = $this->addNode('');
            $this->addEdge($parentId, $ifId, '+ (true)');
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->processExpressionPaths($expr->if, $ifId);
            $this->setLabel($ifId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->if),
                $t2,
                $f2,
                $p2,
            ));

            $elseId = $this->addNode('');
            $this->addEdge($parentId, $elseId, '+ (false)');
            ['t' => $t3, 'f' => $f3, 'p' => $p3] = $this->processExpressionPaths($expr->else, $elseId);
            $this->setLabel($elseId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->else),
                $t3,
                $f3,
                $p3,
            ));

            return [
                't' => $t1 * $t2 + $f1 * $t3,
                'f' => $t1 * $f2 + $f1 * $f3,
                'p' => $t1 * $p2 + $f1 * $p3,
            ];
        }

        if ($expr instanceof Coalesce) {
            $leftId = $this->addNode('');
            $this->addEdge($parentId, $leftId, 'left');
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->processExpressionPaths($expr->left, $leftId);
            $this->setLabel($leftId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->left),
                $t1,
                $f1,
                $p1,
            ));

            $rightId = $this->addNode('');
            $this->addEdge($parentId, $rightId, 'right (??)');
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->processExpressionPaths($expr->right, $rightId);
            $this->setLabel($rightId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->right),
                $t2,
                $f2,
                $p2,
            ));

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
                        $condNodeId = $this->addNode('');
                        $this->addEdge($parentId, $condNodeId, '+ (match cond)');
                        ['t' => $tc, 'f' => $fc, 'p' => $pc] = $this->processExpressionPaths($cond, $condNodeId);
                        $this->setLabel($condNodeId, sprintf(
                            '%s|t=%d, f=%d, p=%d',
                            $this->prettyPrintExpr($cond),
                            $tc,
                            $fc,
                            $pc,
                        ));
                        $totalP += $pc;
                    }
                }

                $bodyNodeId = $this->addNode('');
                $this->addEdge($parentId, $bodyNodeId, '+ (match arm)');
                ['t' => $tb, 'f' => $fb, 'p' => $pb] = $this->processExpressionPaths($arm->body, $bodyNodeId);
                $this->setLabel($bodyNodeId, sprintf(
                    '%s|t=%d, f=%d, p=%d',
                    $this->prettyPrintExpr($arm->body),
                    $tb,
                    $fb,
                    $pb,
                ));
                $totalP += $pb;
            }

            return ['t' => $totalP, 'f' => $totalP, 'p' => $totalP];
        }

        if ($expr instanceof Assign || $expr instanceof AssignOp) {
            $varId = $this->addNode('');
            $this->addEdge($parentId, $varId, 'var');
            ['t' => $tv, 'f' => $fv, 'p' => $pv] = $this->processExpressionPaths($expr->var, $varId);
            $this->setLabel($varId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->var),
                $tv,
                $fv,
                $pv,
            ));

            $exprId = $this->addNode('');
            $this->addEdge($parentId, $exprId, '× (assign)');
            ['t' => $te, 'f' => $fe, 'p' => $pe] = $this->processExpressionPaths($expr->expr, $exprId);
            $this->setLabel($exprId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->expr),
                $te,
                $fe,
                $pe,
            ));

            $p = $pv * $pe;

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        if ($expr instanceof BinaryOp) {
            $leftId = $this->addNode('');
            $this->addEdge($parentId, $leftId, 'left');
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->processExpressionPaths($expr->left, $leftId);
            $this->setLabel($leftId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->left),
                $t1,
                $f1,
                $p1,
            ));

            $rightId = $this->addNode('');
            $this->addEdge($parentId, $rightId, '× (binary op)');
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->processExpressionPaths($expr->right, $rightId);
            $this->setLabel($rightId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->right),
                $t2,
                $f2,
                $p2,
            ));

            $p = $p1 * $p2;

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        if ($expr instanceof Cast || $expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            $childId = $this->addNode('');
            $this->addEdge($parentId, $childId, 'operand');
            ['t' => $t, 'f' => $f, 'p' => $p] = $this->processExpressionPaths($expr->expr, $childId);
            $this->setLabel($childId, sprintf(
                '%s|t=%d, f=%d, p=%d',
                $this->prettyPrintExpr($expr->expr),
                $t,
                $f,
                $p,
            ));

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        // Leaf expression
        return ['t' => 1, 'f' => 1, 'p' => 1];
    }

    /**
     * Mirrors AcpathCalculator::expressionPaths (no tree building).
     *
     * @return array{t: int, f: int, p: int}
     */
    private function expressionPaths(Expr $expr): array
    {
        if ($expr instanceof BooleanNot) {
            ['t' => $t, 'f' => $f, 'p' => $p] = $this->expressionPaths($expr->expr);

            return ['t' => $f, 'f' => $t, 'p' => $p];
        }

        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->expressionPaths($expr->left);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->expressionPaths($expr->right);

            return ['t' => $t1 * $t2, 'f' => $f1 + $t1 * $f2, 'p' => $f1 + $t1 * $p2];
        }

        if ($expr instanceof BooleanOr || $expr instanceof LogicalOr) {
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->expressionPaths($expr->left);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->expressionPaths($expr->right);

            return ['t' => $t1 + $f1 * $t2, 'f' => $f1 * $f2, 'p' => $t1 + $f1 * $p2];
        }

        if ($expr instanceof Ternary) {
            if ($expr->if === null) {
                ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->expressionPaths($expr->cond);
                ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->expressionPaths($expr->else);

                return ['t' => $t1 + $f1 * $t2, 'f' => $f1 * $f2, 'p' => $t1 + $f1 * $p2];
            }

            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->expressionPaths($expr->cond);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->expressionPaths($expr->if);
            ['t' => $t3, 'f' => $f3, 'p' => $p3] = $this->expressionPaths($expr->else);

            return [
                't' => $t1 * $t2 + $f1 * $t3,
                'f' => $t1 * $f2 + $f1 * $f3,
                'p' => $t1 * $p2 + $f1 * $p3,
            ];
        }

        if ($expr instanceof Coalesce) {
            ['t' => $t1, 'f' => $f1, 'p' => $p1] = $this->expressionPaths($expr->left);
            ['t' => $t2, 'f' => $f2, 'p' => $p2] = $this->expressionPaths($expr->right);

            return ['t' => $t1 + $f1 * $t2, 'f' => $f1 * $f2, 'p' => $t1 + $f1 * $p2];
        }

        if ($expr instanceof Match_) {
            $totalP = 0;

            foreach ($expr->arms as $arm) {
                if ($arm->conds !== null) {
                    foreach ($arm->conds as $cond) {
                        $totalP += $this->expressionPaths($cond)['p'];
                    }
                }

                $totalP += $this->expressionPaths($arm->body)['p'];
            }

            return ['t' => $totalP, 'f' => $totalP, 'p' => $totalP];
        }

        if ($expr instanceof Assign || $expr instanceof AssignOp) {
            $pVar  = $this->expressionPaths($expr->var)['p'];
            $pExpr = $this->expressionPaths($expr->expr)['p'];
            $p     = $pVar * $pExpr;

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        if ($expr instanceof BinaryOp) {
            $p1 = $this->expressionPaths($expr->left)['p'];
            $p2 = $this->expressionPaths($expr->right)['p'];
            $p  = $p1 * $p2;

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        if ($expr instanceof Cast || $expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            $p = $this->expressionPaths($expr->expr)['p'];

            return ['t' => $p, 'f' => $p, 'p' => $p];
        }

        return ['t' => 1, 'f' => 1, 'p' => 1];
    }

    /**
     * Mirrors AcpathCalculator::expressionPathsDouble (no tree building).
     *
     * @return array{tt: int, tf: int, ff: int, pp: int}
     */
    private function expressionPathsDouble(Expr $expr): array
    {
        if ($expr instanceof BooleanNot) {
            ['tt' => $tt, 'tf' => $tf, 'ff' => $ff, 'pp' => $pp] = $this->expressionPathsDouble($expr->expr);

            return ['tt' => $ff, 'tf' => $tf, 'ff' => $tt, 'pp' => $pp];
        }

        if ($expr instanceof BooleanAnd || $expr instanceof LogicalAnd) {
            ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = $this->expressionPaths($expr->left);
            ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = $this->expressionPaths($expr->right);
            ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = $this->expressionPathsDouble($expr->left);
            ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = $this->expressionPathsDouble($expr->right);

            return [
                'tt' => $tt1 * $tt2,
                'tf' => $tf1 * $t2 + $tt1 * $tf2,
                'ff' => $ff1 + 2 * $tf1 * $f2 + $tt1 * $ff2,
                'pp' => $ff1 + 2 * $tf1 * $p2 + $tt1 * $pp2,
            ];
        }

        if ($expr instanceof BooleanOr || $expr instanceof LogicalOr) {
            ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = $this->expressionPaths($expr->left);
            ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = $this->expressionPaths($expr->right);
            ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = $this->expressionPathsDouble($expr->left);
            ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = $this->expressionPathsDouble($expr->right);

            return [
                'tt' => $tt1 + 2 * $tf1 * $t2 + $ff1 * $tt2,
                'tf' => $tf1 * $f2 + $ff1 * $tf2,
                'ff' => $ff1 * $ff2,
                'pp' => $tt1 + 2 * $tf1 * $p2 + $ff1 * $pp2,
            ];
        }

        if ($expr instanceof Ternary) {
            if ($expr->if === null) {
                ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = $this->expressionPaths($expr->cond);
                ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = $this->expressionPaths($expr->else);
                ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = $this->expressionPathsDouble($expr->cond);
                ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = $this->expressionPathsDouble($expr->else);

                return [
                    'tt' => $tt1 + 2 * $tf1 * $t2 + $ff1 * $tt2,
                    'tf' => $tf1 * $f2 + $ff1 * $tf2,
                    'ff' => $ff1 * $ff2,
                    'pp' => $tt1 + 2 * $tf1 * $p2 + $ff1 * $pp2,
                ];
            }

            ['t'  => $t1, 'f' => $f1, 'p' => $p1]                    = $this->expressionPaths($expr->cond);
            ['t'  => $t2, 'f' => $f2, 'p' => $p2]                    = $this->expressionPaths($expr->if);
            ['t'  => $t3, 'f' => $f3, 'p' => $p3]                    = $this->expressionPaths($expr->else);
            ['tt' => $tt1, 'tf' => $tf1, 'ff' => $ff1, 'pp' => $pp1] = $this->expressionPathsDouble($expr->cond);
            ['tt' => $tt2, 'tf' => $tf2, 'ff' => $ff2, 'pp' => $pp2] = $this->expressionPathsDouble($expr->if);
            ['tt' => $tt3, 'tf' => $tf3, 'ff' => $ff3, 'pp' => $pp3] = $this->expressionPathsDouble($expr->else);

            return [
                'tt' => $tt1 * $tt2 + 2 * $tf1 * $t2 * $t3 + $ff1 * $tt3,
                'tf' => $tt1 * $tf2 + $ff1 * $tf3 + $tf1 * ($t2 * $f3 + $f2 * $t3),
                'ff' => $tt1 * $ff2 + 2 * $tf1 * $f2 * $f3 + $ff1 * $ff3,
                'pp' => $tt1 * $pp2 + 2 * $tf1 * $p2 * $p3 + $ff1 * $pp3,
            ];
        }

        $p = $this->expressionPaths($expr)['p'];

        return ['tt' => 0, 'tf' => $p, 'ff' => 0, 'pp' => 0];
    }

    private function addNode(string $label): int
    {
        $id                    = $this->nextId++;
        $this->nodeLabels[$id] = $label;

        return $id;
    }

    private function addEdge(int $from, int $to, string $label): void
    {
        $this->edges[] = ['from' => $from, 'to' => $to, 'label' => $label];
    }

    private function setLabel(int $nodeId, string $label): void
    {
        $this->nodeLabels[$nodeId] = $label;
    }

    private function prettyPrintExpr(Expr $expr): string
    {
        $code = $this->printer->prettyPrintExpr($expr);

        if (mb_strlen($code) > 60) {
            return mb_substr($code, 0, 57) . '...';
        }

        return $code;
    }

    private function prettyPrintStmt(Stmt $stmt): string
    {
        $code = $this->printer->prettyPrint([$stmt]);

        if (mb_strlen($code) > 60) {
            return mb_substr($code, 0, 57) . '...';
        }

        return $code;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '"', "\n", '{', '}', '|', '<', '>'], ['\\\\', '\\"', '\\n', '\\{', '\\}', '\\|', '\\<', '\\>'], $text);
    }
}
