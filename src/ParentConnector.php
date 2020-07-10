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
use function count;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * @see https://github.com/nikic/PHP-Parser/blob/v4.6.0/doc/component/FAQ.markdown#how-can-the-parent-of-a-node-be-obtained
 */
final class ParentConnector extends NodeVisitorAbstract
{
    /**
     * @psalm-var list<Node>
     */
    private $stack = [];

    public function beforeTraverse(array $nodes): void
    {
        $this->stack = [];
    }

    public function enterNode(Node $node): void
    {
        if (!empty($this->stack)) {
            $node->setAttribute('parent', $this->stack[count($this->stack) - 1]);
        }

        $this->stack[] = $node;
    }

    public function leaveNode(Node $node): void
    {
        array_pop($this->stack);
    }
}
