<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The IO gate base class, ported from lib/reline/io.rb.
 *
 * All terminal control in Reline goes through one IO gate — a Reline\IO\Ansi on
 * a real terminal or a Reline\IO\Dumb for pipes / TERM=dumb. Upstream selects it
 * once into the global constant `Reline::IOGate`; this port injects the chosen
 * gate into Core and LineEditor instead (PHP has no clean global-constant swap,
 * and upstream's own render tests swap the constant, confirming it is a seam).
 * The contract this class and its subclasses answer to is
 * docs/porting/reline-io-contract.md.
 *
 * Everything here is string I/O over already-open streams plus a pushback buffer
 * and deferred-signal servicing — none of it is Tty's job (ADR-0016). Tty is
 * consumed only for raw mode and winsize, by the Ansi subclass.
 *
 * The `@buf` pushback stack and `ungetc` live in this base, deduplicated from the
 * two identical copies upstream keeps in ansi.rb and dumb.rb — the one structural
 * merge in this file, safe because both copies are byte-for-byte the same.
 */
abstract class IO
{
    protected const RESET_COLOR = "\e[0m";

    /**
     * Pushback stack (LIFO): bytes returned by ungetc and leftover bytes from a
     * DSR reply, read before the stream. Ruby's `@buf` Array with unshift/shift.
     *
     * @var list<int>
     */
    protected array $buf = [];

    /**
     * The read loop's deferred-signal servicer (Core wires it to
     * LineEditor::handle_signal). Called between getc poll slices, mirroring
     * `Reline.core.line_editor.handle_signal` in ansi.rb:126 / dumb.rb:59.
     *
     * @var (callable():void)|null
     */
    protected $signalServicer = null;

    /**
     * Choose a gate the way lib/reline/io.rb:6-25 does: Dumb for TERM=dumb, else
     * Ansi. Windows is out of scope (ADR-0006). The non-tty fallback is still
     * Ansi upstream — its methods guard on tty themselves — so a piped stdin does
     * not force Dumb; only TERM=dumb does.
     */
    public static function decide_io_gate(): self
    {
        if (\getenv('TERM') === 'dumb') {
            return new IO\Dumb();
        }

        return new IO\Ansi();
    }

    public function dumb(): bool
    {
        return false;
    }

    public function win(): bool
    {
        return false;
    }

    public function reset_color_sequence(): string
    {
        return static::RESET_COLOR;
    }

    /** Windows-only auto-linewrap toggle; a no-op on every gate this port ships. */
    public function disable_auto_linewrap(bool $_disable): void
    {
    }

    /** @param (callable():void)|null $servicer */
    public function setSignalServicer(?callable $servicer): void
    {
        $this->signalServicer = $servicer;
    }

    /**
     * Run any deferred signal work between getc poll slices. pcntl delivers the
     * OS signal to a handler that only flips a flag; the real work (re-render on
     * resize, raise on interrupt) happens here, off the signal-handler stack —
     * the "deferred flags, serviced from the read loop" shape the io-contract
     * §4/§5 describes. Guarded for pcntl absence.
     */
    protected function serviceSignals(): void
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            \pcntl_signal_dispatch();
        }
        if ($this->signalServicer !== null) {
            ($this->signalServicer)();
        }
    }

    /** Pushback, LIFO (ansi.rb:168-170 / dumb.rb:68-70). */
    public function ungetc(int $c): void
    {
        \array_unshift($this->buf, $c);
    }

    /**
     * Compose getc bytes into one encoding-valid character (io.rb:39-51). The
     * first byte waits forever; continuation bytes wait only $timeout, so a lone
     * leading byte of a truncated sequence still returns. Used by quoted-insert.
     */
    public function read_single_char(float $timeout): ?string
    {
        $buffer = '';
        while (true) {
            $t = $buffer === '' ? \INF : $timeout;
            $c = $this->getc($t);
            if ($c === null) {
                return null;
            }
            $buffer .= \chr($c);
            if (\mb_check_encoding($buffer, $this->encoding())) {
                return $buffer;
            }
        }
    }

    abstract public function encoding(): string;

    /** @return array{0: int, 1: int} [rows, cols] */
    abstract public function get_screen_size(): array;

    abstract public function cursor_pos(): CursorPos;

    /**
     * Main read primitive. Returns the next byte 0..255, or null on timeout/EOF.
     * $timeout is seconds; INF blocks forever.
     */
    abstract public function getc(float $timeout): ?int;

    /**
     * Run $fn with the terminal in raw mode (or as a pass-through when not a tty).
     *
     * @param callable():mixed $fn
     * @return mixed
     */
    abstract public function with_raw_input(callable $fn);

    abstract public function set_default_key_bindings(Config $config): void;

    /** @return mixed opaque state handed back to deprep */
    abstract public function prep();

    /** @param mixed $otio */
    abstract public function deprep($otio): void;

    abstract public function in_pasting(): bool;

    abstract public function read_bracketed_paste(): string;

    abstract public function write(string $string): void;

    /** @param callable():void $fn */
    abstract public function buffered_output(callable $fn): void;

    abstract public function move_cursor_column(int $x): void;

    abstract public function move_cursor_up(int $x): void;

    abstract public function move_cursor_down(int $x): void;

    abstract public function hide_cursor(): void;

    abstract public function show_cursor(): void;

    abstract public function erase_after_cursor(): void;

    abstract public function scroll_down(int $x): void;

    abstract public function clear_screen(): void;

    /** @param callable():void $handler */
    abstract public function set_winch_handler(callable $handler): void;

    abstract public function set_screen_size(int $rows, int $columns): void;
}
