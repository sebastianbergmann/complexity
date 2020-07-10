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

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class NpathComplexityCalculatingVisitor extends NodeVisitorAbstract
{
    /**
     * @var int
     */
    private $npathComplexity = 1;

    public function enterNode(Node $node): void
    {
    }

    public function npathComplexity(): int
    {
        return $this->npathComplexity;
    }
}
