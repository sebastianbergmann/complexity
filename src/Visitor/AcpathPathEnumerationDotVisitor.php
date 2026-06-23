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

use function array_map;
use function array_pop;
use function assert;
use function ceil;
use function count;
use function implode;
use function intdiv;
use function min;
use function round;
use function sprintf;
use function str_replace;
use PhpParser\Node\Stmt;

final class AcpathPathEnumerationDotVisitor
{
    /** @var list<array{light: array{int, int, int}, dark: array{int, int, int}}> */
    private const array TANGO_HUES = [
        ['light' => [0xFC, 0xE9, 0x4F], 'dark' => [0xC4, 0xA0, 0x00]], // Butter
        ['light' => [0xFC, 0xAF, 0x3E], 'dark' => [0xCE, 0x5C, 0x00]], // Orange
        ['light' => [0xE9, 0xB9, 0x6E], 'dark' => [0x8F, 0x59, 0x02]], // Chocolate
        ['light' => [0x8A, 0xE2, 0x34], 'dark' => [0x4E, 0x9A, 0x06]], // Chameleon
        ['light' => [0x72, 0x9F, 0xCF], 'dark' => [0x20, 0x4A, 0x87]], // Sky Blue
        ['light' => [0xAD, 0x7F, 0xA8], 'dark' => [0x5C, 0x35, 0x66]], // Plum
        ['light' => [0xEF, 0x29, 0x29], 'dark' => [0xA4, 0x00, 0x00]], // Scarlet Red
    ];

    /**
     * @param Stmt[] $statements
     */
    public function generate(array $statements): string
    {
        $cfg        = AcpathControlFlowGraph::fromStatements($statements);
        $paths      = $this->enumeratePaths($cfg);
        $totalPaths = count($paths);

        assert($totalPaths > 0);

        $title = sprintf('ACPATH paths: %d', $totalPaths);

        // Build sequential display labels for visible (non-merge-point) nodes
        $nodeLabels = [];
        $counter    = 1;

        foreach ($cfg->nodes() as $node) {
            if ($node['id'] !== $cfg->entryId() && $node['id'] !== $cfg->exitId() && $node['shape'] !== 'point') {
                $nodeLabels[$node['id']] = (string) $counter++;
            }
        }

        // Count how many paths use each edge

        $edgePathMap = array_map(
            static function (array $edge)
            {
                return [];
            },
            $cfg->edges(),
        );

        foreach ($paths as $pathIdx => $pathEdges) {
            foreach ($pathEdges as $edgeIdx) {
                $edgePathMap[$edgeIdx][] = $pathIdx;
            }
        }

        $dot = "digraph cfg {\n";
        $dot .= "    rankdir=TB;\n";
        $dot .= '    label="' . $this->escape($title) . "\";\n";
        $dot .= "    labelloc=t;\n";
        $dot .= "    node [shape=box, fontname=\"monospace\", fontsize=10];\n";

        // Nodes
        foreach ($cfg->nodes() as $node) {
            $id = $this->nodeId($node['id'], $cfg);

            if ($node['shape'] === 'point') {
                $dot .= "    {$id} [shape=point];\n";
            } elseif ($node['shape'] === 'diamond') {
                $dot .= '    ' . $id . ' [shape=diamond, label=' . $this->htmlLabel($nodeLabels[$node['id']], $node['label']) . "];\n";
            } else {
                $dot .= '    ' . $id . ' [label=' . $this->htmlLabel($nodeLabels[$node['id']], $node['label']) . "];\n";
            }
        }

        // Edges with color
        foreach ($cfg->edges() as $edgeIdx => $edge) {
            $from     = $this->nodeId($edge['from'], $cfg);
            $to       = $this->nodeId($edge['to'], $cfg);
            $attrs    = [];
            $pathIdxs = $edgePathMap[$edgeIdx];

            if ($edge['label'] !== '') {
                $attrs[] = 'label="' . $this->escape($edge['label']) . '"';
            }

            if ($edge['style'] === 'dashed') {
                $attrs[] = 'style=dashed';
            }

            if ($pathIdxs !== []) {
                $attrs[]  = 'color="' . $this->pathColor($pathIdxs[0], $totalPaths) . '"';
                $penwidth = min(1.0 + count($pathIdxs) * 0.5, 5.0);
                $attrs[]  = sprintf('penwidth=%.1f', $penwidth);
            }

            $attrStr = $attrs !== [] ? ' [' . implode(', ', $attrs) . ']' : '';
            $dot .= "    {$from} -> {$to}{$attrStr};\n";
        }

        // Legend
        $dot .= "    subgraph cluster_legend {\n";
        $dot .= "        label=\"Paths\";\n";
        $dot .= "        style=dashed;\n";
        $dot .= "        legend [shape=plaintext, label=<\n";
        $dot .= "            <TABLE BORDER=\"0\" CELLBORDER=\"0\" CELLSPACING=\"2\" CELLPADDING=\"0\">\n";

        foreach ($paths as $pathIdx => $pathEdges) {
            $color   = $this->pathColor($pathIdx, $totalPaths);
            $nodeSeq = $this->pathNodeSequence($cfg, $pathEdges, $nodeLabels);
            $label   = $this->escapeHtml(sprintf('Path %d: %s', $pathIdx + 1, implode(' → ', $nodeSeq)));

            $dot .= sprintf(
                "            <TR><TD ALIGN=\"LEFT\"><FONT COLOR=\"%s\" FACE=\"monospace\" POINT-SIZE=\"10\">%s</FONT></TD></TR>\n",
                $color,
                $label,
            );
        }

        $dot .= "            </TABLE>>];\n";
        $dot .= "    }\n";
        $dot .= "}\n";

        return $dot;
    }

    /**
     * @return list<list<int>> Each path is a list of edge indices
     */
    private function enumeratePaths(AcpathControlFlowGraph $cfg): array
    {
        $edges = $cfg->edges();

        /** @var list<list<int>> $paths */
        $paths = [];

        /** @var list<array{int, list<int>}> $stack */
        $stack = [[$cfg->entryId(), []]];

        while ($stack !== []) {
            [$currentNode, $currentPath] = array_pop($stack);

            if ($currentNode === $cfg->exitId()) {
                $paths[] = $currentPath;

                continue;
            }

            foreach ($edges as $edgeIdx => $edge) {
                if ($edge['from'] !== $currentNode) {
                    continue;
                }

                // Skip back-edges (dashed)
                if ($edge['style'] === 'dashed') {
                    continue;
                }

                $newPath   = $currentPath;
                $newPath[] = $edgeIdx;
                $stack[]   = [$edge['to'], $newPath];
            }
        }

        return $paths;
    }

    /**
     * @param list<int>          $pathEdges
     * @param array<int, string> $nodeLabels
     *
     * @return list<string>
     */
    private function pathNodeSequence(AcpathControlFlowGraph $cfg, array $pathEdges, array $nodeLabels): array
    {
        $edges = $cfg->edges();
        $nodes = [];

        if ($pathEdges !== []) {
            $fromId = $edges[$pathEdges[0]]['from'];

            if (isset($nodeLabels[$fromId])) {
                $nodes[] = $nodeLabels[$fromId];
            }

            foreach ($pathEdges as $edgeIdx) {
                $toId = $edges[$edgeIdx]['to'];

                if (isset($nodeLabels[$toId])) {
                    $nodes[] = $nodeLabels[$toId];
                }
            }
        }

        return $nodes;
    }

    /**
     * @param int<0, max>  $pathIdx
     * @param positive-int $totalPaths
     */
    private function pathColor(int $pathIdx, int $totalPaths): string
    {
        $hueCount     = count(self::TANGO_HUES);
        $hueIndex     = $pathIdx % $hueCount;
        $shadeIndex   = intdiv($pathIdx, $hueCount);
        $shadesNeeded = (int) ceil($totalPaths / $hueCount);

        $hue = self::TANGO_HUES[$hueIndex];

        if ($shadesNeeded <= 1) {
            $t = 0.0;
        } else {
            $t = $shadeIndex / ($shadesNeeded - 1);
        }

        $r = (int) round($hue['dark'][0] + ($hue['light'][0] - $hue['dark'][0]) * $t);
        $g = (int) round($hue['dark'][1] + ($hue['light'][1] - $hue['dark'][1]) * $t);
        $b = (int) round($hue['dark'][2] + ($hue['light'][2] - $hue['dark'][2]) * $t);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
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

    private function htmlLabel(string $id, string $text): string
    {
        return sprintf(
            '<<FONT POINT-SIZE="7" COLOR="gray50">%s</FONT><BR/>%s>',
            $this->escapeHtml($id),
            $this->escapeHtml($text),
        );
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $text);
    }

    private function escapeHtml(string $text): string
    {
        return str_replace(['&', '<', '>', "\n"], ['&amp;', '&lt;', '&gt;', '<BR/>'], $text);
    }
}
