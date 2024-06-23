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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

#[CoversClass(CyclomaticComplexityCalculatingVisitor::class)]
#[Small]
final class CyclomaticComplexityCalculatingVisitorTest extends TestCase
{
    public function testCalculatesCyclomaticComplexityForAbstractSyntaxTree(): void
    {
        $source = file_get_contents(__DIR__ . '/../_fixture/example_function.php');

        $this->assertIsString($source);

        $nodes = (new ParserFactory)->createForHostVersion()->parse($source);

        $this->assertNotNull($nodes);

        $traverser = new NodeTraverser;

        $visitor = new CyclomaticComplexityCalculatingVisitor;

        $traverser->addVisitor($visitor);

        /* @noinspection UnusedFunctionResultInspection */
        $traverser->traverse($nodes);

        $this->assertSame(16, $visitor->cyclomaticComplexity());
    }
}
