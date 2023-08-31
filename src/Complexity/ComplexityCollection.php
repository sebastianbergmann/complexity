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

use function array_filter;
use function array_merge;
use function array_sum;
use function array_values;
use function count;
use function floor;
use function max;
use function min;
use function sort;
use Countable;
use IteratorAggregate;

/**
 * @psalm-immutable
 */
final class ComplexityCollection implements Countable, IteratorAggregate
{
    /**
     * @psalm-var list<Complexity>
     */
    private readonly array $items;

    public static function fromList(Complexity ...$items): self
    {
        return new self($items);
    }

    /**
     * @psalm-param list<Complexity> $items
     */
    private function __construct(array $items)
    {
        $this->items = $items;
    }

    /**
     * @psalm-return list<Complexity>
     */
    public function asArray(): array
    {
        return $this->items;
    }

    public function getIterator(): ComplexityCollectionIterator
    {
        return new ComplexityCollectionIterator($this);
    }

    /**
     * @psalm-return non-negative-int
     */
    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * @psalm-return non-negative-int
     */
    public function cyclomaticComplexity(): int
    {
        $cyclomaticComplexity = 0;

        foreach ($this as $item) {
            $cyclomaticComplexity += $item->cyclomaticComplexity();
        }

        return $cyclomaticComplexity;
    }

    public function cyclomaticComplexityMinimum(): int
    {
        $values = $this->cyclomaticComplexityValues();

        if (empty($values)) {
            return 0;
        }

        return min($values);
    }

    public function cyclomaticComplexityMaximum(): int
    {
        $values = $this->cyclomaticComplexityValues();

        if (empty($values)) {
            return 0;
        }

        return max($values);
    }

    public function cyclomaticComplexityAverage(): float
    {
        if (empty($this->items)) {
            return 0;
        }

        return array_sum($this->cyclomaticComplexityValues()) / count($this->items);
    }

    public function cyclomaticComplexityMedian(): float
    {
        if (empty($this->items)) {
            return 0;
        }

        return $this->median($this->cyclomaticComplexityValues());
    }

    public function isFunction(): self
    {
        return new self(
            array_values(
                array_filter(
                    $this->items,
                    static fn (Complexity $complexity): bool => $complexity->isFunction(),
                ),
            ),
        );
    }

    public function isMethod(): self
    {
        return new self(
            array_values(
                array_filter(
                    $this->items,
                    static fn (Complexity $complexity): bool => $complexity->isMethod(),
                ),
            ),
        );
    }

    public function mergeWith(self $other): self
    {
        return new self(
            array_merge(
                $this->asArray(),
                $other->asArray(),
            ),
        );
    }

    /**
     * @psalm-return list<non-negative-int>
     */
    private function cyclomaticComplexityValues(): array
    {
        $values = [];

        foreach ($this as $item) {
            $values[] = $item->cyclomaticComplexity();
        }

        return $values;
    }

    /**
     * @psalm-param list<float|int> $values
     */
    private function median(array $values): float
    {
        sort($values);

        $count  = count($values);
        $middle = (int) floor(($count - 1) / 2);

        if ($count % 2) {
            return (float) $values[$middle];
        }

        $low  = $values[$middle];
        $high = $values[$middle + 1];

        return (float) ($low + $high) / 2;
    }
}
