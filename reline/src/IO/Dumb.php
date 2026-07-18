<?php

declare(strict_types=1);

namespace PhPty\Reline\IO;

use PhPty\Reline\Config;
use PhPty\Reline\CursorPos;
use PhPty\Reline\IO;

/**
 * The no-tty / TERM=dumb gate, ported from lib/reline/io/dumb.rb.
 *
 * For pipes and non-interactive runs: every screen-control call is a no-op, the
 * screen size is a settable fixed [24, 80], the cursor is always at (0, 0), and
 * raw mode is a plain pass-through. getc still polls with signal servicing and
 * still honours the pushback buffer. This is the shape ScreenTest's piped-stdin
 * fallback and the ambiguous-width skip rely on (io-contract §7).
 */
final class Dumb extends IO
{
    protected const RESET_COLOR = ''; // Do not send a colour reset sequence.

    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    private string $encoding;

    /** @var array{0: int, 1: int} */
    private array $screenSize = [24, 80];

    private bool $pasting = false;

    /**
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct($input = null, $output = null, string $encoding = 'UTF-8')
    {
        $this->input = $input ?? \STDIN;
        $this->output = $output ?? \STDOUT;
        $this->encoding = $encoding;
    }

    public function dumb(): bool
    {
        return true;
    }

    public function encoding(): string
    {
        return $this->encoding;
    }

    public function set_default_key_bindings(Config $config): void
    {
    }

    public function with_raw_input(callable $fn)
    {
        return $fn();
    }

    public function write(string $string): void
    {
        \fwrite($this->output, $string);
    }

    public function buffered_output(callable $fn): void
    {
        $fn();
    }

    public function getc(float $timeout): ?int
    {
        if ($this->buf !== []) {
            return \array_shift($this->buf);
        }
        while (true) {
            $this->serviceSignals();
            $read = [$this->input];
            $write = [];
            $except = [];
            // 0.1s slices, as dumb.rb:62.
            $ready = @\stream_select($read, $write, $except, 0, 100_000);
            if ($ready === false || $ready === 0) {
                continue;
            }
            $byte = \fread($this->input, 1);
            if ($byte === '' || $byte === false) {
                // EOF.
                return null;
            }

            return \ord($byte);
        }
    }

    /** @return array{0: int, 1: int} */
    public function get_screen_size(): array
    {
        return $this->screenSize;
    }

    public function set_screen_size(int $rows, int $columns): void
    {
        $this->screenSize = [$rows, $columns];
    }

    public function cursor_pos(): CursorPos
    {
        return new CursorPos(0, 0);
    }

    public function hide_cursor(): void
    {
    }

    public function show_cursor(): void
    {
    }

    public function move_cursor_column(int $x): void
    {
    }

    public function move_cursor_up(int $x): void
    {
    }

    public function move_cursor_down(int $x): void
    {
    }

    public function erase_after_cursor(): void
    {
    }

    public function scroll_down(int $x): void
    {
    }

    public function clear_screen(): void
    {
    }

    public function set_winch_handler(callable $handler): void
    {
    }

    public function in_pasting(): bool
    {
        return $this->pasting;
    }

    public function read_bracketed_paste(): string
    {
        return '';
    }

    public function prep()
    {
        return null;
    }

    public function deprep($otio): void
    {
    }
}
