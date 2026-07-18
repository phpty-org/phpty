<?php

declare(strict_types=1);

namespace PhPty\Tty\Tests;

use PhPty\Tty\RawOptions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RawOptionsTest extends TestCase
{
    public function testDefaultsMatchIoConsole(): void
    {
        // intr on (signals still delivered), one blocking byte, no timeout.
        $opts = new RawOptions();

        $this->assertTrue($opts->intr());
        $this->assertSame(1, $opts->vmin());
        $this->assertSame(0, $opts->vtime());
    }

    public function testCarriesTheGivenValues(): void
    {
        $opts = new RawOptions(false, 0, 5);

        $this->assertFalse($opts->intr());
        $this->assertSame(0, $opts->vmin());
        $this->assertSame(5, $opts->vtime());
    }
}
