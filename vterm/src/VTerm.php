<?php

declare(strict_types=1);

namespace PhPty\VTerm;

use FFI;

/**
 * An in-memory terminal emulator: write a byte stream in, read the rendered
 * Screen back. A thin binding over libghostty-vt — it emulates nothing itself.
 * See vterm/CONTEXT.md.
 */
final class VTerm
{
    private const GHOSTTY_SUCCESS = 0;
    private const GHOSTTY_OUT_OF_SPACE = -3;

    /** GHOSTTY_POINT_TAG_ACTIVE: the active area where the cursor moves. */
    private const POINT_TAG_ACTIVE = 0;

    /** GHOSTTY_CELL_DATA_WIDE: query a cell's width behaviour. */
    private const CELL_DATA_WIDE = 3;

    /** GHOSTTY_TERMINAL_OPT_WRITE_PTY: install the query-response callback. */
    private const OPT_WRITE_PTY = 1;

    private FFI $ffi;

    /** @var FFI\CData GhosttyTerminal handle */
    private $terminal;

    /** Bytes the terminal wants sent back upstream (replies to queries). */
    private string $responses = '';

    /** @var callable Held so the FFI callback is not garbage-collected. */
    private $writePty;

    public function __construct(
        private readonly int $rows,
        private readonly int $cols,
        ?FFI $ffi = null,
    ) {
        if ($rows < 1 || $cols < 1) {
            throw new \InvalidArgumentException('A Screen must have at least one row and one column.');
        }
        $this->ffi = $ffi ?? LibGhostty::load();

        $options = $this->ffi->new('GhosttyTerminalOptions');
        $options->cols = $cols;
        $options->rows = $rows;
        $options->max_scrollback = 0;

        $terminal = $this->ffi->new('GhosttyTerminal');
        $result = $this->ffi->ghostty_terminal_new(null, FFI::addr($terminal), $options);
        if ($result !== self::GHOSTTY_SUCCESS) {
            throw new \RuntimeException("ghostty_terminal_new failed with result {$result}.");
        }
        $this->terminal = $terminal;

        // Collect the terminal's replies to queries (a cursor-position report,
        // for one) so a driver can send them back to the program. Without this,
        // libghostty ignores such sequences and a program awaiting a reply hangs.
        $this->writePty = function ($terminal, $userdata, $data, $length): void {
            $this->responses .= FFI::string($data, $length);
        };
        $this->ffi->ghostty_terminal_set($this->terminal, self::OPT_WRITE_PTY, $this->writePty);
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function cols(): int
    {
        return $this->cols;
    }

    /**
     * Feed bytes to the emulator for interpretation. The direction is from the
     * program towards the Screen; escape sequences become state, not text.
     */
    public function write(string $bytes): void
    {
        $length = \strlen($bytes);
        if ($length === 0) {
            return;
        }
        $buffer = $this->ffi->new("uint8_t[{$length}]");
        FFI::memcpy($buffer, $bytes, $length);
        $this->ffi->ghostty_terminal_vt_write($this->terminal, $buffer, $length);
    }

    /**
     * Take the bytes the terminal wants to send back upstream — replies to
     * queries in what was written — and clear them. The direction is the
     * reverse of write(): these go towards the program, not the Screen.
     */
    public function takeResponses(): string
    {
        $responses = $this->responses;
        $this->responses = '';

        return $responses;
    }

    /**
     * The Cell rendered at a position: its grapheme cluster and width behaviour,
     * snapshotted so it stays valid after the terminal changes. Reading outside
     * the Screen is an error, not an empty Cell.
     */
    public function cellAt(int $row, int $col): Cell
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            throw new \OutOfRangeException(
                "Cell ({$row}, {$col}) is outside the {$this->rows}x{$this->cols} Screen."
            );
        }

        $point = $this->ffi->new('GhosttyPoint');
        $point->tag = self::POINT_TAG_ACTIVE;
        $point->value->coordinate->x = $col;
        $point->value->coordinate->y = $row;

        $ref = $this->ffi->new('GhosttyGridRef');
        $ref->size = FFI::sizeof($ref);
        $result = $this->ffi->ghostty_terminal_grid_ref($this->terminal, $point, FFI::addr($ref));
        if ($result !== self::GHOSTTY_SUCCESS) {
            throw new \RuntimeException("grid_ref failed at ({$row}, {$col}) with result {$result}.");
        }

        $text = $this->graphemesToString(FFI::addr($ref), $row, $col);
        $wide = $this->cellWide(FFI::addr($ref), $row, $col);

        return new Cell($text, $wide);
    }

    /** @param FFI\CData $ref pointer to GhosttyGridRef */
    private function cellWide($ref, int $row, int $col): Wide
    {
        $cell = $this->ffi->new('GhosttyCell');
        $result = $this->ffi->ghostty_grid_ref_cell($ref, FFI::addr($cell));
        if ($result !== self::GHOSTTY_SUCCESS) {
            throw new \RuntimeException("grid_ref_cell failed at ({$row}, {$col}) with result {$result}.");
        }

        // ghostty_cell_get takes the cell by value (uint64_t); pass the scalar,
        // not the CData wrapper, or FFI cannot marshal it.
        $wide = $this->ffi->new('GhosttyCellWide');
        $result = $this->ffi->ghostty_cell_get($cell->cdata, self::CELL_DATA_WIDE, FFI::addr($wide));
        if ($result !== self::GHOSTTY_SUCCESS) {
            throw new \RuntimeException("cell_get(WIDE) failed at ({$row}, {$col}) with result {$result}.");
        }

        return Wide::fromInt($wide->cdata);
    }

    /** @param FFI\CData $ref pointer to GhosttyGridRef */
    private function graphemesToString($ref, int $row, int $col): string
    {
        $capacity = 8;
        $outLen = $this->ffi->new('size_t');

        $buffer = $this->ffi->new("uint32_t[{$capacity}]");
        $result = $this->ffi->ghostty_grid_ref_graphemes($ref, $buffer, $capacity, FFI::addr($outLen));
        if ($result === self::GHOSTTY_OUT_OF_SPACE) {
            $capacity = $outLen->cdata;
            $buffer = $this->ffi->new("uint32_t[{$capacity}]");
            $result = $this->ffi->ghostty_grid_ref_graphemes($ref, $buffer, $capacity, FFI::addr($outLen));
        }
        if ($result !== self::GHOSTTY_SUCCESS) {
            throw new \RuntimeException("graphemes failed at ({$row}, {$col}) with result {$result}.");
        }

        $string = '';
        for ($i = 0, $n = $outLen->cdata; $i < $n; $i++) {
            $string .= \mb_chr($buffer[$i], 'UTF-8');
        }

        return $string;
    }

    public function __destruct()
    {
        if (isset($this->terminal)) {
            $this->ffi->ghostty_terminal_free($this->terminal);
        }
    }
}
