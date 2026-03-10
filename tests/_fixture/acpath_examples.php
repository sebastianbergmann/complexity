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

function acpath_linear(): void
{
    $a = 1;
    $b = 2;
}

function acpath_single_if(bool $x): void
{
    if ($x) {
        $a = 1;
    }
}

function acpath_if_else(bool $x): void
{
    if ($x) {
        $a = 1;
    } else {
        $a = 2;
    }
}

function acpath_and_condition(bool $x, bool $y): void
{
    if ($x && $y) {
        $a = 1;
    }
}

function acpath_or_condition(bool $x, bool $y): void
{
    if ($x || $y) {
        $a = 1;
    }
}

function acpath_sequential_ifs(bool $x, bool $y): void
{
    if ($x) {
        $a = 1;
    }

    if ($y) {
        $b = 1;
    }
}

function acpath_three_sequential_ifs(bool $a, bool $b, bool $c): void
{
    if ($a) {
        $x = 1;
    }

    if ($b) {
        $y = 1;
    }

    if ($c) {
        $z = 1;
    }
}

function acpath_while(bool $x): void
{
    while ($x) {
        $a = 1;
    }
}

function acpath_return_in_if(bool $x): void
{
    if ($x) {
        return;
    }

    $a = 1;
}
