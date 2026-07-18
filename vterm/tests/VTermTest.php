<?php

declare(strict_types=1);

namespace PhPty\VTerm\Tests;

use PhPty\VTerm\VTerm;
use PhPty\VTerm\Wide;
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

    public function testEastAsianAmbiguousCharactersAreNarrow(): void
    {
        // U+2192 (→) is East Asian Ambiguous: one column in a Western context,
        // two in a terminal configured for wide ambiguous width. libghostty — like
        // PsySH's own width path — hardcodes one. So this harness renders such
        // characters exactly as PsySH does and cannot exhibit that divergence.
        // See docs/adr/0007-harness-first-reline-undecided.md.
        $vterm = new VTerm(1, 10);
        $vterm->write('→X');

        $this->assertSame(Wide::narrow(), $vterm->cellAt(0, 0)->wide());
        $this->assertSame('→', $vterm->cellAt(0, 0)->text());
        // X lands in the very next column — the arrow occupied only one.
        $this->assertSame('X', $vterm->cellAt(0, 1)->text());
    }

    public function testTheTwoKindsOfEmptyCellAreDistinguishable(): void
    {
        $vterm = new VTerm(1, 10);
        $vterm->write('日 ');

        // Col 0: the fullwidth character. Col 1: its spacer (renders nothing).
        // Col 3: an unwritten Narrow Cell. Both cols 1 and 3 have empty text,
        // but only col 1 is a SpacerTail — that is the distinction ScreenTest
        // needs to render CJK correctly.
        $this->assertSame(Wide::wide(), $vterm->cellAt(0, 0)->wide());
        $this->assertSame(Wide::spacerTail(), $vterm->cellAt(0, 1)->wide());
        $this->assertTrue($vterm->cellAt(0, 1)->isSpacerTail());

        $this->assertSame(Wide::narrow(), $vterm->cellAt(0, 3)->wide());
        $this->assertFalse($vterm->cellAt(0, 3)->isSpacerTail());
        $this->assertSame('', $vterm->cellAt(0, 3)->text());
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

    public function testAnswersACursorPositionQuery(): void
    {
        $vterm = new VTerm(3, 20);
        $this->assertSame('', $vterm->takeResponses());

        // DSR: report cursor position. The reply is a CPR: ESC [ row ; col R,
        // 1-based, so top-left is "1;1".
        $vterm->write("\x1b[6n");

        $this->assertSame("\x1b[1;1R", $vterm->takeResponses());
        $this->assertSame('', $vterm->takeResponses(), 'responses are cleared once taken');
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
            $cells[] = $vterm->cellAt($row, $col)->text();
        }

        return $cells;
    }
}
