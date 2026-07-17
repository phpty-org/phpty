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

    private FFI $ffi;

    /** @var FFI\CData GhosttyTerminal handle */
    private $terminal;

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
     * The grapheme cluster rendered at a Cell, as a UTF-8 string. An empty Cell
     * is the empty string; the second Cell of a fullwidth character is also
     * empty (its content belongs to the Cell before it). Reading outside the
     * Screen is an error, not an empty Cell.
     */
    public function cellAt(int $row, int $col): string
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

        return $this->graphemesToString(FFI::addr($ref), $row, $col);
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
