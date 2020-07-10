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

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class Calculator
{
    /**
     * @throws RuntimeException
     */
    public function calculate(string $filename): ComplexityCollection
    {
        $parser                       = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        $traverser                    = new NodeTraverser;
        $complexityCalculatingVisitor = new ComplexityCalculatingVisitor;

        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor(new ParentConnectingVisitor);
        $traverser->addVisitor($complexityCalculatingVisitor);

        try {
            $nodes = $parser->parse(file_get_contents($filename));

            assert($nodes !== null);

            /* @noinspection UnusedFunctionResultInspection */
            $traverser->traverse($nodes);
            // @codeCoverageIgnoreStart
        } catch (Error $error) {
            throw new RuntimeException(
                $error->getMessage(),
                (int) $error->getCode(),
                $error
            );
        }
        // @codeCoverageIgnoreEnd

        return $complexityCalculatingVisitor->result();
    }
}
