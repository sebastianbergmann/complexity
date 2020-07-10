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

/**
 * @psalm-immutable
 */
final class Complexity
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $cyclomaticComplexity;

    /**
     * @var int
     */
    private $npathComplexity;

    public function __construct(string $name, int $cyclomaticComplexity, int $npathComplexity)
    {
        $this->name                 = $name;
        $this->cyclomaticComplexity = $cyclomaticComplexity;
        $this->npathComplexity      = $npathComplexity;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function cyclomaticComplexity(): int
    {
        return $this->cyclomaticComplexity;
    }

    public function npathComplexity(): int
    {
        return $this->npathComplexity;
    }
}
