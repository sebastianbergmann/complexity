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

use function file_get_contents;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SebastianBergmann\Complexity\CyclomaticComplexityCalculatingVisitor
 *
 * @small
 */
final class CyclomaticComplexityCalculatingVisitorTest extends TestCase
{
    public function testCalculatesCyclomaticComplexityForAbstractSyntaxTree(): void
    {
        $nodes = (new ParserFactory)->createForHostVersion()->parse(
            file_get_contents(__DIR__ . '/../_fixture/example_function.php')
        );

        $traverser = new NodeTraverser;

        $visitor = new CyclomaticComplexityCalculatingVisitor;

        $traverser->addVisitor($visitor);

        /* @noinspection UnusedFunctionResultInspection */
        $traverser->traverse($nodes);

        $this->assertSame(14, $visitor->cyclomaticComplexity());
    }
}
