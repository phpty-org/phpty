<?php

declare(strict_types=1);

namespace PhPty\Tty;

use FFI;
use FFI\CData;

/**
 * The Raw mode and Winsize operations over termios and ioctl, the recommended
 * path (ADR-0001 prefers FFI). All operations act on this process's stdin — the
 * Tty it is attached to (tty/CONTEXT.md); the API takes no descriptor because
 * the Stty fallback could not honour one, and the two Backends must stay
 * equivalent.
 *
 * Raw mode is deliberately minimal, per ADR-0016: clear ICANON and ECHO, clear
 * ISIG unless `intr`, and set the VMIN/VTIME read thresholds — nothing in the
 * iflag/oflag/cflag words. That is exactly the "byte at a time, unbuffered,
 * unechoed, no signal interpretation" of tty/CONTEXT.md's Raw mode and no more.
 */
final class FfiBackend implements Backend
{
    private FFI $libc;

    /** stdin's descriptor: the Tty this process is attached to. */
    private const FD = 0;

    public function __construct(FFI $libc)
    {
        $this->libc = $libc;
    }

    /**
     * @return CData a `struct termios` holding the current state
     */
    public function save(): mixed
    {
        return $this->currentTermios();
    }

    public function enterRaw(RawOptions $opts): void
    {
        $termios = $this->currentTermios();

        $termios->c_lflag = $termios->c_lflag & ~(Libc::ICANON | Libc::ECHO);
        if (!$opts->intr()) {
            $termios->c_lflag = $termios->c_lflag & ~Libc::ISIG;
        }
        $termios->c_cc[Libc::VMIN] = $opts->vmin();
        $termios->c_cc[Libc::VTIME] = $opts->vtime();

        $this->applyTermios($termios);
    }

    /**
     * @param CData $saved a token from save()
     */
    public function restore(mixed $saved): void
    {
        $this->applyTermios($saved);
    }

    public function getWinsize(): Winsize
    {
        $winsize = $this->libc->new('struct winsize');
        if ($this->libc->ioctl(self::FD, Libc::TIOCGWINSZ, FFI::addr($winsize)) !== 0) {
            throw new \RuntimeException('ioctl(TIOCGWINSZ) failed: stdin is not a Tty.');
        }

        return new Winsize($winsize->ws_row, $winsize->ws_col);
    }

    public function setWinsize(int $rows, int $cols): void
    {
        // Leave the pixel fields zero: unset is how a character terminal reports
        // them, and nothing reads them back (Winsize drops them).
        $winsize = $this->libc->new('struct winsize');
        $winsize->ws_row = $rows;
        $winsize->ws_col = $cols;
        if ($this->libc->ioctl(self::FD, Libc::TIOCSWINSZ, FFI::addr($winsize)) !== 0) {
            throw new \RuntimeException('ioctl(TIOCSWINSZ) failed: stdin is not a Tty.');
        }
    }

    private function currentTermios(): CData
    {
        $termios = $this->libc->new('struct termios');
        if ($this->libc->tcgetattr(self::FD, FFI::addr($termios)) !== 0) {
            throw new \RuntimeException('tcgetattr failed: stdin is not a Tty.');
        }

        return $termios;
    }

    private function applyTermios(CData $termios): void
    {
        if ($this->libc->tcsetattr(self::FD, Libc::TCSANOW, FFI::addr($termios)) !== 0) {
            throw new \RuntimeException('tcsetattr failed.');
        }
    }
}
