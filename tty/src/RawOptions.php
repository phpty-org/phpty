<?php

declare(strict_types=1);

namespace PhPty\Tty;

/**
 * The knobs on Raw mode, mirroring the arguments upstream passes io-console's
 * `raw` (docs/porting/reline-io-contract.md §8). The defaults are io-console's:
 * blocking single-byte reads with signals still delivered.
 *
 * - `intr` keeps ISIG on, so Ctrl-C/Ctrl-Z still signal — upstream's
 *   `raw(intr: true)` for the main read loop. Turning it off (the quoted-insert
 *   read) makes those keys literal bytes.
 * - `vmin`/`vtime` are the c_cc read thresholds. The default (1, 0) blocks for
 *   one byte; upstream's single `raw(min: 0, time: 0)` call site (the macOS
 *   Terminal.app ^V non-ASCII peek) is a non-blocking single read. If upstream
 *   drops that peek, this class shrinks to `intr` alone (ADR-0016).
 */
final class RawOptions
{
    public function __construct(
        private readonly bool $intr = true,
        private readonly int $vmin = 1,
        private readonly int $vtime = 0
    ) {
    }

    public function intr(): bool
    {
        return $this->intr;
    }

    public function vmin(): int
    {
        return $this->vmin;
    }

    public function vtime(): int
    {
        return $this->vtime;
    }
}
