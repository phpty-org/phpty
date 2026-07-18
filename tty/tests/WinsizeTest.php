<?php

declare(strict_types=1);

namespace PhPty\Tty\Tests;

use PhPty\Tty\Winsize;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class WinsizeTest extends TestCase
{
    public function testReportsRowsAndCols(): void
    {
        $winsize = new Winsize(24, 80);

        $this->assertSame(24, $winsize->rows());
        $this->assertSame(80, $winsize->cols());
    }
}
