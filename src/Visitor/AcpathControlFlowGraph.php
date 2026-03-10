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

use function array_any;
use function array_pop;
use function array_slice;
use function count;
use function implode;
use function mb_strlen;
use function mb_substr;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Throw_ as ExprThrow_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Break_;
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

final class AcpathControlFlowGraph
{
    private int $entryId;
    private int $exitId;

    /** @var list<array{id: int, label: string, shape: string}> */
    private array $nodes = [];

    /** @var list<array{from: int, to: int, label: string, style: string}> */
    private array $edges = [];
    private int $nextId  = 0;
    private Standard $printer;

    /** @var list<array{breakTarget: int, continueTarget: int}> */
    private array $loopStack = [];

    /**
     * @param Stmt[] $statements
     */
    public static function fromStatements(array $statements): self
    {
        $cfg = new self;

        $cfg->entryId = $cfg->addNode('ENTRY', 'point');
        $cfg->exitId  = $cfg->addNode('EXIT', 'point');

        $exitNodeId = $cfg->buildStatements($statements, $cfg->entryId);

        if ($exitNodeId !== null) {
            $cfg->addEdge($exitNodeId, $cfg->exitId, '', 'solid');
        }

        return $cfg;
    }

    private function __construct()
    {
        $this->printer = new Standard;
    }

    /**
     * @return list<array{id: int, label: string, shape: string}>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<array{from: int, to: int, label: string, style: string}>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    public function entryId(): int
    {
        return $this->entryId;
    }

    public function exitId(): int
    {
        return $this->exitId;
    }

    /**
     * @param Stmt[] $statements
     */
    private function buildStatements(array $statements, int $entryId): ?int
    {
        $currentId = $entryId;

        foreach ($statements as $stmt) {
            $currentId = $this->buildStatement($stmt, $currentId);

            if ($currentId === null) {
                return null;
            }
        }

        return $currentId;
    }

    private function buildStatement(Stmt $stmt, int $entryId): ?int
    {
        if ($stmt instanceof Expression) {
            if ($stmt->expr instanceof ExprThrow_) {
                $nodeId = $this->addNode('throw ' . $this->prettyPrint($stmt->expr->expr), 'box');
                $this->addEdge($entryId, $nodeId, '', 'solid');
                $this->addEdge($nodeId, $this->exitId, 'throw', 'solid');

                return null;
            }

            $nodeId = $this->addNode($this->prettyPrint($stmt->expr), 'box');
            $this->addEdge($entryId, $nodeId, '', 'solid');

            return $nodeId;
        }

        if ($stmt instanceof Return_) {
            $label  = $stmt->expr !== null ? 'return ' . $this->prettyPrint($stmt->expr) : 'return';
            $nodeId = $this->addNode($label, 'box');
            $this->addEdge($entryId, $nodeId, '', 'solid');
            $this->addEdge($nodeId, $this->exitId, 'return', 'solid');

            return null;
        }

        if ($stmt instanceof If_) {
            return $this->buildIf($stmt, $entryId);
        }

        if ($stmt instanceof While_) {
            return $this->buildWhile($stmt, $entryId);
        }

        if ($stmt instanceof Do_) {
            return $this->buildDoWhile($stmt, $entryId);
        }

        if ($stmt instanceof For_) {
            return $this->buildFor($stmt, $entryId);
        }

        if ($stmt instanceof Foreach_) {
            return $this->buildForeach($stmt, $entryId);
        }

        if ($stmt instanceof Switch_) {
            return $this->buildSwitch($stmt, $entryId);
        }

        if ($stmt instanceof TryCatch) {
            return $this->buildTryCatch($stmt, $entryId);
        }

        if ($stmt instanceof Break_) {
            if ($this->loopStack !== []) {
                $target = $this->loopStack[count($this->loopStack) - 1]['breakTarget'];
                $this->addEdge($entryId, $target, 'break', 'solid');
            }

            return null;
        }

        if ($stmt instanceof Continue_) {
            if ($this->loopStack !== []) {
                $target = $this->loopStack[count($this->loopStack) - 1]['continueTarget'];
                $this->addEdge($entryId, $target, 'continue', 'dashed');
            }

            return null;
        }

        if ($stmt instanceof Block) {
            return $this->buildStatements($stmt->stmts, $entryId);
        }

        // Other statements (Echo, Noop, etc.)
        $nodeId = $this->addNode($this->prettyPrintStmt($stmt), 'box');
        $this->addEdge($entryId, $nodeId, '', 'solid');

        return $nodeId;
    }

    private function buildIf(If_ $stmt, int $entryId): ?int
    {
        $condId = $this->addNode($this->prettyPrint($stmt->cond), 'diamond');
        $this->addEdge($entryId, $condId, '', 'solid');

        $mergeId = $this->addNode('', 'point');

        // Handle elseif chains by desugaring
        if ($stmt->elseifs !== []) {
            $elseIfNode       = $stmt->elseifs[0];
            $innerIf          = new If_($elseIfNode->cond);
            $innerIf->stmts   = $elseIfNode->stmts;
            $innerIf->elseifs = array_slice($stmt->elseifs, 1);
            $innerIf->else    = $stmt->else;

            $thenExit = $this->buildStatements($stmt->stmts, $condId);

            if ($thenExit !== null) {
                $this->addEdge($thenExit, $mergeId, '', 'solid');
            }

            // Label the edge from condition to then-branch
            $this->labelLastEdgeFrom($condId, $stmt->stmts, 'true');

            $elseExit = $this->buildStatement($innerIf, $condId);

            if ($elseExit !== null) {
                $this->addEdge($elseExit, $mergeId, '', 'solid');
            }

            // Label the edge from condition to else-branch
            $this->labelLastEdgeFrom($condId, [$innerIf], 'false');

            return $this->hasIncomingEdges($mergeId) ? $mergeId : null;
        }

        // Then branch
        $thenExit = $this->buildStatements($stmt->stmts, $condId);

        if ($thenExit !== null) {
            $this->addEdge($thenExit, $mergeId, '', 'solid');
        }

        $this->labelLastEdgeFrom($condId, $stmt->stmts, 'true');

        if ($stmt->else !== null) {
            $elseExit = $this->buildStatements($stmt->else->stmts, $condId);

            if ($elseExit !== null) {
                $this->addEdge($elseExit, $mergeId, '', 'solid');
            }

            $this->labelLastEdgeFrom($condId, $stmt->else->stmts, 'false');
        } else {
            $this->addEdge($condId, $mergeId, 'false', 'solid');
        }

        return $this->hasIncomingEdges($mergeId) ? $mergeId : null;
    }

    private function buildWhile(While_ $stmt, int $entryId): int
    {
        $condId  = $this->addNode($this->prettyPrint($stmt->cond), 'diamond');
        $mergeId = $this->addNode('', 'point');

        $this->addEdge($entryId, $condId, '', 'solid');
        $this->addEdge($condId, $mergeId, 'false', 'solid');

        $this->loopStack[] = ['breakTarget' => $mergeId, 'continueTarget' => $condId];

        $bodyExit = $this->buildStatements($stmt->stmts, $condId);

        if ($bodyExit !== null) {
            $this->addEdge($bodyExit, $condId, '', 'dashed');
        }

        $this->labelLastEdgeFrom($condId, $stmt->stmts, 'true');

        array_pop($this->loopStack);

        return $mergeId;
    }

    private function buildDoWhile(Do_ $stmt, int $entryId): int
    {
        $bodyEntryId = $this->addNode('', 'point');
        $condId      = $this->addNode($this->prettyPrint($stmt->cond), 'diamond');
        $mergeId     = $this->addNode('', 'point');

        $this->addEdge($entryId, $bodyEntryId, '', 'solid');

        $this->loopStack[] = ['breakTarget' => $mergeId, 'continueTarget' => $condId];

        $bodyExit = $this->buildStatements($stmt->stmts, $bodyEntryId);

        if ($bodyExit !== null) {
            $this->addEdge($bodyExit, $condId, '', 'solid');
        }

        $this->addEdge($condId, $bodyEntryId, 'true', 'dashed');
        $this->addEdge($condId, $mergeId, 'false', 'solid');

        array_pop($this->loopStack);

        return $mergeId;
    }

    private function buildFor(For_ $stmt, int $entryId): int
    {
        $currentId = $entryId;

        // Init expressions
        foreach ($stmt->init as $initExpr) {
            $nodeId = $this->addNode($this->prettyPrint($initExpr), 'box');
            $this->addEdge($currentId, $nodeId, '', 'solid');
            $currentId = $nodeId;
        }

        // Condition
        if ($stmt->cond !== []) {
            $condLabel = $this->prettyPrint($stmt->cond[0]);

            for ($i = 1; $i < count($stmt->cond); $i++) {
                $condLabel .= ', ' . $this->prettyPrint($stmt->cond[$i]);
            }
        } else {
            $condLabel = 'true';
        }

        $condId  = $this->addNode($condLabel, 'diamond');
        $mergeId = $this->addNode('', 'point');

        $this->addEdge($currentId, $condId, '', 'solid');
        $this->addEdge($condId, $mergeId, 'false', 'solid');

        $this->loopStack[] = ['breakTarget' => $mergeId, 'continueTarget' => $condId];

        $bodyExit = $this->buildStatements($stmt->stmts, $condId);

        $this->labelLastEdgeFrom($condId, $stmt->stmts, 'true');

        // Loop expressions
        if ($bodyExit !== null) {
            foreach ($stmt->loop as $loopExpr) {
                $nodeId = $this->addNode($this->prettyPrint($loopExpr), 'box');
                $this->addEdge($bodyExit, $nodeId, '', 'solid');
                $bodyExit = $nodeId;
            }

            $this->addEdge($bodyExit, $condId, '', 'dashed');
        }

        array_pop($this->loopStack);

        return $mergeId;
    }

    private function buildForeach(Foreach_ $stmt, int $entryId): int
    {
        $label = 'foreach (' . $this->prettyPrint($stmt->expr);

        if ($stmt->keyVar !== null) {
            $label .= ' as ' . $this->prettyPrint($stmt->keyVar) . ' => ' . $this->prettyPrint($stmt->valueVar);
        } else {
            $label .= ' as ' . $this->prettyPrint($stmt->valueVar);
        }

        $label .= ')';

        $condId  = $this->addNode($label, 'diamond');
        $mergeId = $this->addNode('', 'point');

        $this->addEdge($entryId, $condId, '', 'solid');
        $this->addEdge($condId, $mergeId, 'done', 'solid');

        $this->loopStack[] = ['breakTarget' => $mergeId, 'continueTarget' => $condId];

        $bodyExit = $this->buildStatements($stmt->stmts, $condId);

        $this->labelLastEdgeFrom($condId, $stmt->stmts, 'next');

        if ($bodyExit !== null) {
            $this->addEdge($bodyExit, $condId, '', 'dashed');
        }

        array_pop($this->loopStack);

        return $mergeId;
    }

    private function buildSwitch(Switch_ $stmt, int $entryId): int
    {
        $condId  = $this->addNode('switch (' . $this->prettyPrint($stmt->cond) . ')', 'diamond');
        $mergeId = $this->addNode('', 'point');

        $this->addEdge($entryId, $condId, '', 'solid');

        $this->loopStack[] = ['breakTarget' => $mergeId, 'continueTarget' => $mergeId];

        $hasDefault  = false;
        $fallthrough = null;

        foreach ($stmt->cases as $case) {
            $caseLabel = $case->cond === null ? 'default' : 'case ' . $this->prettyPrint($case->cond);

            if ($case->cond === null) {
                $hasDefault = true;
            }

            $caseEntryId = $this->addNode('', 'point');
            $this->addEdge($condId, $caseEntryId, $caseLabel, 'solid');

            if ($fallthrough !== null) {
                $this->addEdge($fallthrough, $caseEntryId, 'fallthrough', 'solid');
            }

            $caseExit    = $this->buildStatements($case->stmts, $caseEntryId);
            $fallthrough = $caseExit;
        }

        if ($fallthrough !== null) {
            $this->addEdge($fallthrough, $mergeId, '', 'solid');
        }

        if (!$hasDefault) {
            $this->addEdge($condId, $mergeId, 'no match', 'solid');
        }

        array_pop($this->loopStack);

        return $mergeId;
    }

    private function buildTryCatch(TryCatch $stmt, int $entryId): ?int
    {
        $mergeId = $this->addNode('', 'point');

        $tryExit = $this->buildStatements($stmt->stmts, $entryId);

        if ($tryExit !== null) {
            $this->addEdge($tryExit, $mergeId, '', 'solid');
        }

        foreach ($stmt->catches as $catch) {
            $types = [];

            foreach ($catch->types as $type) {
                $types[] = $type->toString();
            }

            $catchLabel = 'catch (' . implode('|', $types) . ')';
            $catchEntry = $this->addNode($catchLabel, 'box');
            $this->addEdge($entryId, $catchEntry, 'exception', 'solid');

            $catchExit = $this->buildStatements($catch->stmts, $catchEntry);

            if ($catchExit !== null) {
                $this->addEdge($catchExit, $mergeId, '', 'solid');
            }
        }

        if ($stmt->finally !== null) {
            $finallyEntry = $this->addNode('finally', 'box');

            if ($this->hasIncomingEdges($mergeId)) {
                $this->addEdge($mergeId, $finallyEntry, '', 'solid');

                return $finallyEntry;
            }

            return null;
        }

        return $this->hasIncomingEdges($mergeId) ? $mergeId : null;
    }

    private function addNode(string $label, string $shape): int
    {
        $id            = $this->nextId++;
        $this->nodes[] = ['id' => $id, 'label' => $label, 'shape' => $shape];

        return $id;
    }

    private function addEdge(int $from, int $to, string $label, string $style): void
    {
        $this->edges[] = ['from' => $from, 'to' => $to, 'label' => $label, 'style' => $style];
    }

    private function prettyPrint(Expr $expr): string
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

    /**
     * Labels the first edge going from $fromId that was added after the initial edge.
     *
     * @param Stmt[] $stmts
     */
    private function labelLastEdgeFrom(int $fromId, array $stmts, string $label): void
    {
        // Find the first edge from $fromId that does not already have a label
        // and was added for the given statements block
        for ($i = count($this->edges) - 1; $i >= 0; $i--) {
            if ($this->edges[$i]['from'] === $fromId && $this->edges[$i]['label'] === '') {
                // Check this is going to first statement's node, not to merge
                $this->edges[$i]['label'] = $label;

                return;
            }
        }
    }

    private function hasIncomingEdges(int $nodeId): bool
    {
        return array_any(
            $this->edges,
            static fn (array $edge) => $edge['to'] === $nodeId,
        );
    }
}
