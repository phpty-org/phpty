<?php

declare(strict_types=1);

namespace PhPty\Tty;

/**
 * Reads and changes the state of the Tty this process is attached to: raw mode,
 * size, and whether a stream is a Tty at all — the io-console capability PHP
 * lacks (tty/CONTEXT.md, ADR-0016). Four operations, this process's own Tty
 * only; nothing else belongs here (no getc, no select, no escape emission — see
 * the ADR).
 *
 * A Backend is chosen on first use: Ffi if the extension is present and the
 * termios library loads, else Stty. A Backend may be injected for tests.
 */
final class Tty
{
    private ?Backend $backend;

    /** @var resource */
    private $input;

    /**
     * @param resource|null $input the stream whose Tty-ness isTty() reports;
     *                             defaults to stdin, the Tty the Backends act on
     */
    public function __construct(?Backend $backend = null, $input = null)
    {
        $this->backend = $backend;
        $this->input = $input ?? \STDIN;
    }

    public function isTty(): bool
    {
        // No Backend: stream_isatty is native, the one operation FFI/stty do not
        // differ on (docs/porting/reline-io-contract.md §8).
        return \stream_isatty($this->input);
    }

    /**
     * Enter Raw mode, run $fn, and restore the prior state on the way out —
     * exception-safe (try/finally) and re-entrant (each nested call saves and
     * restores the state it found, so a differently-optioned inner call, such as
     * the quoted-insert `intr: false` read, still takes effect and still leaves
     * the outer state intact). Scoped only: there is no bare enter/exit pair,
     * because an unpaired enter is the wedged-cooked-less-shell bug a line editor
     * must never ship (ADR-0016).
     *
     * On a stream that is not a Tty this is a plain pass-through: $fn runs and
     * nothing is touched, matching upstream's `with_raw_input` guard.
     *
     * @param callable():mixed $fn
     *
     * @return mixed whatever $fn returns
     */
    public function withRawMode(callable $fn, ?RawOptions $opts = null): mixed
    {
        if (!$this->isTty()) {
            return $fn();
        }

        $backend = $this->backend();
        $saved = $backend->save();
        try {
            $backend->enterRaw($opts ?? new RawOptions());

            return $fn();
        } finally {
            // enterRaw is inside the try so a partial change it makes before
            // failing is still rolled back: once save() has a token, restore
            // always runs.
            $backend->restore($saved);
        }
    }

    public function getWinsize(): Winsize
    {
        return $this->backend()->getWinsize();
    }

    public function setWinsize(int $rows, int $cols): void
    {
        $this->backend()->setWinsize($rows, $cols);
    }

    private function backend(): Backend
    {
        return $this->backend ?? ($this->backend = self::chooseBackend());
    }

    private static function chooseBackend(): Backend
    {
        if (\extension_loaded('ffi')) {
            try {
                return new FfiBackend(Libc::load());
            } catch (\FFI\Exception $e) {
                // The termios library would not load; fall back to stty.
            }
        }

        return new SttyBackend();
    }
}
