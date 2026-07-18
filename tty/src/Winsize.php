<?php

declare(strict_types=1);

namespace PhPty\Tty;

/**
 * The row and column count of a Tty. Named after the C `winsize` struct because
 * both Backends ultimately report the same thing — see tty/CONTEXT.md. The
 * struct's pixel fields (ws_xpixel/ws_ypixel) are carried by neither Backend:
 * `stty` cannot express them and nothing in the Reline port reads them.
 */
final class Winsize
{
    public function __construct(
        private readonly int $rows,
        private readonly int $cols
    ) {
    }

    public function rows(): int
    {
        return $this->rows;
    }

    public function cols(): int
    {
        return $this->cols;
    }
}
