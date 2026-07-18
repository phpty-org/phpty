<?php

declare(strict_types=1);

namespace PhPty\Tty;

/**
 * Runs `stty` against this process's Tty. A seam, not a feature: the Stty
 * Backend shells out through this, so a test can supply a fake that records the
 * argument lists and returns canned output — letting the argument construction
 * be asserted without a real Tty. The default is SystemSttyRunner.
 */
interface SttyRunner
{
    /**
     * Run `stty` with these arguments against this process's stdin Tty and
     * return its standard output.
     *
     * @param list<string> $args
     *
     * @throws \RuntimeException if stty cannot be run or exits non-zero
     */
    public function run(array $args): string;
}
