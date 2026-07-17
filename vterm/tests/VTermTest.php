<?php

declare(strict_types=1);

namespace PhPty\VTerm\Tests;

use PhPty\VTerm\VTerm;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class VTermTest extends TestCase
{
    protected function set_up(): void
    {
        if (!\extension_loaded('FFI')) {
            $this->markTestSkipped('The FFI extension is required to exercise VTerm.');
        }
        if ((getenv('PHPTY_LIBGHOSTTY_VT') ?: '') === '') {
            $this->markTestSkipped('Set PHPTY_LIBGHOSTTY_VT (the Nix dev shell does) to exercise VTerm.');
        }
    }

    public function testRendersAsciiOnePerCell(): void
    {
        $vterm = new VTerm(1, 20);
        $vterm->write('Hello');

        $row = $this->readRow($vterm, 0, 20);

        $expected = \array_merge(['H', 'e', 'l', 'l', 'o'], \array_fill(0, 15, ''));
        $this->assertSame($expected, $row);
    }

    public function testFullwidthCharacterOccupiesTwoCellsWithAnEmptySecond(): void
    {
        $vterm = new VTerm(1, 10);
        $vterm->write('日本語');

        $row = $this->readRow($vterm, 0, 10);

        // Each fullwidth character sits in one Cell; the next Cell is empty
        // because the character's content belongs to the Cell before it.
        $expected = ['日', '', '本', '', '語', '', '', '', '', ''];
        $this->assertSame($expected, $row);
    }

    public function testEmptyScreenIsAllEmptyCells(): void
    {
        $vterm = new VTerm(2, 4);

        $this->assertSame(['', '', '', ''], $this->readRow($vterm, 0, 4));
        $this->assertSame(['', '', '', ''], $this->readRow($vterm, 1, 4));
    }

    public function testReadingOutsideTheScreenIsAnError(): void
    {
        $vterm = new VTerm(1, 4);

        $this->expectException(\OutOfRangeException::class);
        $vterm->cellAt(0, 4);
    }

    public function testCarriageReturnAndOverwrite(): void
    {
        $vterm = new VTerm(1, 5);
        $vterm->write("abc\rX");

        // \r returns to column 0; X overwrites 'a'.
        $this->assertSame(['X', 'b', 'c', '', ''], $this->readRow($vterm, 0, 5));
    }

    /** @return list<string> */
    private function readRow(VTerm $vterm, int $row, int $cols): array
    {
        $cells = [];
        for ($col = 0; $col < $cols; $col++) {
            $cells[] = $vterm->cellAt($row, $col);
        }

        return $cells;
    }
}
