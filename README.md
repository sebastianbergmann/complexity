[![Latest Stable Version](https://poser.pugx.org/sebastian/complexity/v)](https://packagist.org/packages/sebastian/complexity)
[![CI Status](https://github.com/sebastianbergmann/complexity/workflows/CI/badge.svg)](https://github.com/sebastianbergmann/complexity/actions)
[![codecov](https://codecov.io/gh/sebastianbergmann/complexity/branch/main/graph/badge.svg)](https://codecov.io/gh/sebastianbergmann/complexity)

# sebastian/complexity

Library for calculating the complexity of PHP code units.

## Installation

You can add this library as a local, per-project dependency to your project using [Composer](https://getcomposer.org/):

```
composer require sebastian/complexity
```

If you only need this library during development, for instance to run your project's test suite, then you should add it as a development-time dependency:

```
composer require --dev sebastian/complexity
```

## Cyclomatic Complexity

Cyclomatic Complexity measures the number of linearly independent paths through a function.
It is calculated by counting decision points and adding 1 for the default path.
A function with no branches has a Cyclomatic Complexity of 1; each additional decision point increases the value by 1.

The function `f()` shown below has a Cyclomatic Complexity of 4.

## Acyclic Execution Path (ACPATH)

This software metric counts the number of unique, non-looping execution paths through a function, in other words: the actual paths from entry to exit.

Sequential decisions multiply the path count: a function with three independent `if` statements has 2 × 2 × 2 = 8 paths.

Consider the following example:

```php
<?php declare(strict_types=1);
function f(bool $a, bool $b, bool $c): int
{
    $x = 0;

    if ($a) {
        $x += 2;
    }

    if ($b) {
        $x -= 1;
    }

    if ($c) {
        $x *= 2;
    }

    return $x;
}
```

This function has an ACPATH value of 8.

### Visualizations

The `acpath-dot` tool generates three [Graphviz](https://graphviz.org/) DOT files for each function:

```
./bin/acpath-dot f.php
```

#### Control Flow Graph

The control flow graph shows the structure of the code: statements (boxes), conditions (diamonds), and how control flows between them.
Dashed edges mark loop back-edges.

![Control Flow Graph](.github/img/f_cfg.dot.svg)

#### Path Enumeration

The path enumeration graph overlays all distinct entry-to-exit paths onto the control flow graph.
Each path is color-coded, and a legend lists the node sequence for each path.

![Path Enumeration](.github/img/f_paths.dot.svg)

#### Complexity Decomposition

The decomposition tree shows how the ACPATH value is calculated by recursively decomposing the function body.
Each node displays its metrics (ft, bp, cp, rp), and edges are labeled with composition operators:
`×` for sequential composition (one statement follows another, so path counts are multiplied),
`+` for branching (an `if`/`else` splits control flow, so path counts are added).

The four metrics track how paths exit each code unit:

- **ft** (fall-through paths): paths that complete the current statement and continue to the next one
- **bp** (break paths): paths that exit via a `break` statement (relevant inside loops and switch cases)
- **cp** (continue paths): paths that loop back via a `continue` statement
- **rp** (return paths): paths that exit the function via a `return` statement

The final ACPATH value is `ft + rp`: the sum of paths that fall through the entire function body and paths that exit early via `return`.

![Complexity Decomposition](.github/img/f_decomposition.dot.svg)

The ACPATH metric is described in [The ACPATH Metric: Precise Estimation of the Number of Acyclic Paths in C-like Languages](https://arxiv.org/abs/1610.07914) by Roberto Bagnara, Abramo Bagnara, Alessandro Benedetti, and Patricia Hill.

### Correctness

`AcpathCalculator` implements the compositional equations (37)-(53) of the paper (arXiv:1610.07914v4).
Because the expected values in a test suite are only as trustworthy as whoever computed them, the implementation is validated against two independent sources of truth:

* The paper's own worked examples:
  The examples of Section 3 and Appendix B, with acyclic path counts published by the authors of the metric, are part of the test suite.
* A reference oracle:
  [`ReferenceAcpathOracle`](tests/_oracle/ReferenceAcpathOracle.php) independently implements the paper's Definition 3 (the reference control flow graph, at optimization level 0) and counts acyclic paths by brute force according to Definition 2: entry-to-exit paths that traverse no arc more than once.
  By Theorem 2 of the paper, this count equals ACPATH for controlled function bodies, so calculator and oracle must agree.
  [`AcpathCalculatorDifferentialTest`](tests/unit/AcpathCalculatorDifferentialTest.php) asserts this agreement over the test corpus.

The oracle reproduces all of the paper's machine-generated examples (Appendix B) exactly.
Where calculator and oracle disagree, the divergence is pinned in the differential test with both values, together with its cause:

* A potential defect in the paper, which the calculator inherits:
  Equation (45) of the paper seems to drop `continue` paths and body re-entry paths in `do-while` loops: for `do { if ($a) continue; } while ($c);` it yields 1, although two executions terminate without traversing any loop back arc, which appears to contradict the paper's Theorem 2.
  The calculator follows the published equation.
* A deliberate deviation from the paper:
  For loop guards such as `while ($i < 10)`, Table 5 of the paper yields ACPATH 1, because the comparison's operand arcs cannot be traversed twice.
  The calculator counts 2 (loop skipped, loop entered once), which better matches the intuition of testing effort.
* PHP constructs the paper does not cover: `match`, `throw`, `foreach`, `try`/`catch`, the nullsafe operator, expressions inside call arguments are cases where calculator and oracle currently model control flow differently.

See the `knownDivergenceProvider()` in the differential test for the complete, executable list.
