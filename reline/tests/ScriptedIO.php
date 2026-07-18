<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\CursorPos;
use PhPty\Reline\IO;

/**
 * A gate whose getc replays a scripted byte queue and returns null once it is
 * drained — which read_io treats as a keyseq timeout (when a match is pending)
 * or EOF (when the buffer is empty). Lets the keyseq-timeout disambiguation be
 * unit-tested without real waits. All screen control is inert.
 */
final class ScriptedIO extends IO
{
    /** @var list<int> */
    private array $queue;

    /** @param list<int> $bytes */
    public function __construct(array $bytes)
    {
        $this->queue = $bytes;
    }

    public function getc(float $timeout): ?int
    {
        if ($this->buf !== []) {
            return \array_shift($this->buf);
        }
        if ($this->queue === []) {
            return null;
        }

        return \array_shift($this->queue);
    }

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

    public function write(string $string): void
    {
    }

    public function buffered_output(callable $fn): void
    {
        $fn();
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

    public function hide_cursor(): void
    {
    }

    public function show_cursor(): void
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

    public function set_screen_size(int $rows, int $columns): void
    {
    }
}
