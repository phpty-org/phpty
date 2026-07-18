<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\CursorPos;
use PhPty\Reline\IO;

/**
 * The PHP counterpart of upstream's TestIO fake (test/reline/test_line_editor.rb):
 * an in-memory gate that logs the render calls instead of driving a terminal.
 * Like upstream's TestIO it extends the base IO (so reset_color_sequence is still
 * `\e[0m` and the test strips it) and overrides only the three calls
 * render_line_differential makes: writes verbatim, cursor moves as `[COL_n]`,
 * erase as `[ERASE]`. Every other gate method is an inert stub.
 */
final class LoggingIO extends IO
{
    private string $log = '';

    public function write(string $string): void
    {
        $this->log .= $string;
    }

    public function move_cursor_column(int $x): void
    {
        $this->log .= "[COL_{$x}]";
    }

    public function erase_after_cursor(): void
    {
        $this->log .= '[ERASE]';
    }

    public function reset(): void
    {
        $this->log = '';
    }

    public function log(): string
    {
        return $this->log;
    }

    // --- inert stubs -------------------------------------------------------

    public function encoding(): string
    {
        return 'UTF-8';
    }

    /** @return array{0: int, 1: int} */
    public function get_screen_size(): array
    {
        return [24, 80];
    }

    public function cursor_pos(): CursorPos
    {
        return new CursorPos(0, 0);
    }

    public function getc(float $timeout): ?int
    {
        return null;
    }

    public function with_raw_input(callable $fn)
    {
        return $fn();
    }

    public function set_default_key_bindings(Config $config): void
    {
    }

    public function prep()
    {
        return null;
    }

    public function deprep($otio): void
    {
    }

    public function in_pasting(): bool
    {
        return false;
    }

    public function read_bracketed_paste(): string
    {
        return '';
    }

    public function buffered_output(callable $fn): void
    {
        $fn();
    }

    public function move_cursor_up(int $x): void
    {
    }

    public function move_cursor_down(int $x): void
    {
    }

    public function hide_cursor(): void
    {
    }

    public function show_cursor(): void
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

    public function set_screen_size(int $rows, int $columns): void
    {
    }
}
