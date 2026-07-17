<?php

declare(strict_types=1);

namespace PhPty\VTerm\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Proves the test harness itself works: the monorepo autoloader resolves this
 * test class, and the Yoast polyfill TestCase resolves from the isolated bin
 * package. Real VTerm tests replace this once there is code to exercise.
 */
final class SmokeTest extends TestCase
{
    public function testHarnessRuns(): void
    {
        $this->assertTrue(true);
    }
}
