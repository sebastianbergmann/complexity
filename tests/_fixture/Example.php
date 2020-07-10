<?php declare(strict_types=1);
/*
 * This file is part of sebastian/complexity.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\Complexity\TestFixture;

final class Example
{
    public function method(): void
    {
        if (true || false) {
            if (true || false) {
                foreach (range(0, 1) as $i) {
                    switch ($i) {
                        case 0:
                            break;

                        case 1:
                            break;

                        default:
                    }
                }
            }
        } else {
            try {
            } catch (Throwable $t) {
            }
        }
    }
}
