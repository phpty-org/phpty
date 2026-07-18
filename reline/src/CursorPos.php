<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The upstream Reline::CursorPos struct (lib/reline.rb:28): a 0-based (x, y)
 * cursor position. Returned by IO::cursor_pos and consumed by the ambiguous-width
 * probe and the renderer. Kept as a tiny value object rather than a bare pair so
 * the `.x`/`.y` reads in the ported code stay legible.
 */
final class CursorPos
{
    public function __construct(
        public readonly int $x = 0,
        public readonly int $y = 0,
    ) {
    }
}
