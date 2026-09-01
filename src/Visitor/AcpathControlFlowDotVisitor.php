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

use function implode;
use function str_replace;
use PhpParser\Node\Stmt;

final class AcpathControlFlowDotVisitor
{
    /**
     * @param Stmt[] $statements
     */
    public function generate(array $statements): string
    {
        $cfg = AcpathControlFlowGraph::fromStatements($statements);

        $dot = "digraph cfg {\n";
        $dot .= "    rankdir=TB;\n";
        $dot .= "    node [shape=box, fontname=\"monospace\", fontsize=10];\n";

        foreach ($cfg->nodes() as $node) {
            $id = $this->nodeId($node['id'], $cfg);

            if ($node['shape'] === 'point') {
                $dot .= "    {$id} [shape=point];\n";
            } elseif ($node['shape'] === 'diamond') {
                $dot .= '    ' . $id . ' [shape=diamond, label="' . $this->escape($node['label']) . "\"];\n";
            } else {
                $dot .= '    ' . $id . ' [label="' . $this->escape($node['label']) . "\"];\n";
            }
        }

        foreach ($cfg->edges() as $edge) {
            $from  = $this->nodeId($edge['from'], $cfg);
            $to    = $this->nodeId($edge['to'], $cfg);
            $attrs = [];

            if ($edge['label'] !== '') {
                $attrs[] = 'label="' . $this->escape($edge['label']) . '"';
            }

            if ($edge['style'] === 'dashed') {
                $attrs[] = 'style=dashed';
            }

            $attrStr = $attrs !== [] ? ' [' . implode(', ', $attrs) . ']' : '';
            $dot .= "    {$from} -> {$to}{$attrStr};\n";
        }

        $dot .= "}\n";

        return $dot;
    }

    private function nodeId(int $id, AcpathControlFlowGraph $cfg): string
    {
        if ($id === $cfg->entryId()) {
            return 'entry';
        }

        if ($id === $cfg->exitId()) {
            return 'exit';
        }

        return 'n' . $id;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $text);
    }
}
