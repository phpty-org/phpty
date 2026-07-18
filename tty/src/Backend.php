<?php

declare(strict_types=1);

namespace PhPty\Tty;

/**
 * An interchangeable implementation of every Tty operation against one
 * mechanism. There are two — Ffi and Stty — chosen at runtime (tty/CONTEXT.md,
 * ADR-0016). They are equivalent in outcome but not in cost.
 *
 * Raw mode is split into save/enterRaw/restore rather than a single scoped call
 * so the Tty facade owns the try/finally and the re-entrancy: each nested
 * `withRawMode` saves the current state, applies its own options, and restores
 * to exactly what it found, which is what makes nesting harmless (ansi.rb's
 * `read_single_char`/^V peek re-enter raw while already raw). The token save()
 * returns is opaque and belongs only to the Backend that made it — a cloned
 * termios for Ffi, the `stty -g` string for Stty.
 */
interface Backend
{
    /** Capture the current Tty state as an opaque token for restore(). */
    public function save(): mixed;

    /** Put the Tty into Raw mode per the options, over whatever state is current. */
    public function enterRaw(RawOptions $opts): void;

    /** Put the Tty back into a state previously returned by save(). */
    public function restore(mixed $saved): void;

    public function getWinsize(): Winsize;

    public function setWinsize(int $rows, int $cols): void;
}
