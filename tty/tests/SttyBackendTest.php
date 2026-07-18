<?php

declare(strict_types=1);

namespace PhPty\Tty\Tests;

use PhPty\Tty\RawOptions;
use PhPty\Tty\SttyBackend;
use PhPty\Tty\Winsize;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The Stty Backend's argument construction and output parsing, exercised through
 * the FakeSttyRunner seam — no real Tty involved, so these run anywhere.
 */
final class SttyBackendTest extends TestCase
{
    public function testEnterRawKeepsSignalsByDefault(): void
    {
        $runner = new FakeSttyRunner();
        (new SttyBackend($runner))->enterRaw(new RawOptions());

        // raw -echo clears everything including ISIG; isig puts signals back,
        // and min/time override raw's implied `min 1 time 0`.
        $this->assertSame(
            [['raw', '-echo', 'isig', 'min', '1', 'time', '0']],
            $runner->calls
        );
    }

    public function testEnterRawWithoutIntrOmitsIsig(): void
    {
        $runner = new FakeSttyRunner();
        (new SttyBackend($runner))->enterRaw(new RawOptions(false));

        $this->assertSame(
            [['raw', '-echo', 'min', '1', 'time', '0']],
            $runner->calls
        );
    }

    public function testEnterRawCarriesTheMinAndTimeThresholds(): void
    {
        $runner = new FakeSttyRunner();
        (new SttyBackend($runner))->enterRaw(new RawOptions(true, 0, 5));

        $this->assertSame(
            [['raw', '-echo', 'isig', 'min', '0', 'time', '5']],
            $runner->calls
        );
    }

    public function testSaveReturnsTheTrimmedOpaqueState(): void
    {
        $runner = new FakeSttyRunner(['-g' => "  cbrk:1234:0:5 \n"]);

        $this->assertSame('cbrk:1234:0:5', (new SttyBackend($runner))->save());
        $this->assertSame([['-g']], $runner->calls);
    }

    public function testRestoreReplaysTheSavedStateVerbatim(): void
    {
        $runner = new FakeSttyRunner();
        (new SttyBackend($runner))->restore('cbrk:1234:0:5');

        $this->assertSame([['cbrk:1234:0:5']], $runner->calls);
    }

    public function testGetWinsizeParsesSttySize(): void
    {
        $runner = new FakeSttyRunner(['size' => "24 80\n"]);
        $winsize = (new SttyBackend($runner))->getWinsize();

        $this->assertInstanceOf(Winsize::class, $winsize);
        $this->assertSame(24, $winsize->rows());
        $this->assertSame(80, $winsize->cols());
    }

    public function testGetWinsizeRejectsUnparseableOutput(): void
    {
        $runner = new FakeSttyRunner(['size' => 'not a size']);

        $this->expectException(\RuntimeException::class);
        (new SttyBackend($runner))->getWinsize();
    }

    public function testSetWinsizeBuildsRowsAndCols(): void
    {
        $runner = new FakeSttyRunner();
        (new SttyBackend($runner))->setWinsize(11, 47);

        $this->assertSame([['rows', '11', 'cols', '47']], $runner->calls);
    }
}
