<?php

declare(strict_types=1);

/*
 * Release-only Rector configuration. Modules are written in modern PHP and
 * downgraded to 7.4 for distribution — see
 * docs/adr/0009-downgrade-on-release-with-rector.md. This config is run over the
 * whole tree on the `release` branch, never during development.
 *
 * Both src and tests are downgraded: the 7.4 validation leg runs the tests on a
 * real 7.4, so they must be 7.4-runnable too.
 */

use Rector\Config\RectorConfig;
use Rector\DowngradePhp81\Rector\FuncCall\DowngradeHashAlgorithmXxHashRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../vterm/src',
        __DIR__ . '/../vterm/tests',
        __DIR__ . '/../pty/src',
        __DIR__ . '/../pty/tests',
        __DIR__ . '/../screen-test/src',
        __DIR__ . '/../screen-test/tests',
        __DIR__ . '/../tty/src',
        __DIR__ . '/../tty/tests',
        __DIR__ . '/../reline/src',
        __DIR__ . '/../reline/tests',
    ])
    ->withDowngradeSets(php74: true)
    // This rule evaluates `MHASH_XXH32` at class-load time, a constant the
    // nixpkgs PHP build does not define, so it fatals before Rector does any
    // work. The downgrade must run in the flake for reproducibility
    // (ADR-0008), and we do not downgrade xxHash calls anyway, so skip it.
    ->withSkip([
        DowngradeHashAlgorithmXxHashRector::class,
    ]);
