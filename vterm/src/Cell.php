<?php

declare(strict_types=1);

namespace PhPty\VTerm;

/**
 * One position in a Screen: the grapheme cluster rendered there together with
 * its width behaviour. A Cell is a snapshot, decoupled from the terminal's live
 * state — it stays valid after the terminal changes, unlike the grid reference
 * it was read from.
 */
final class Cell
{
    public function __construct(
        private readonly string $text,
        private readonly Wide $wide,
    ) {
    }

    /**
     * The grapheme cluster as UTF-8. Empty both for an unwritten Cell and for
     * the spacer after a fullwidth character — tell those apart with wide().
     */
    public function text(): string
    {
        return $this->text;
    }

    public function wide(): Wide
    {
        return $this->wide;
    }

    /**
     * True for the second Cell of a fullwidth character. It renders nothing;
     * the character's content belongs to the Cell before it.
     */
    public function isSpacerTail(): bool
    {
        return $this->wide === Wide::spacerTail();
    }
}
