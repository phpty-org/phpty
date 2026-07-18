<?php

declare(strict_types=1);

namespace PhPty\Reline\IO;

use PhPty\Reline\Config;
use PhPty\Reline\CursorPos;
use PhPty\Reline\IO;
use PhPty\Tty\RawOptions;
use PhPty\Tty\Tty;

/**
 * The POSIX terminal gate, ported from lib/reline/io/ansi.rb.
 *
 * Raw mode, the DSR cursor-position probe, bracketed paste, screen size, and all
 * cursor/erase/scroll emission. Escape-sequence emission and parsing live here,
 * not in Tty (ADR-0016): Tty supplies only raw mode (withRawMode) and winsize
 * (getWinsize); everything else is string I/O over the input/output streams.
 *
 * The exact byte sequences are upstream's (io-contract §3): column `\e[{x+1}G`,
 * up/down `\e[{n}A`/`B`, hide/show `\e[?25l`/`h`, erase `\e[K`, clear
 * `\e[2J\e[1;1H`, DSR `\e[6n`, bracketed paste `\e[?2004h`/`l`. scroll_down is
 * `"\n"` repetition, deliberately not CSI S, per ruby/reline#576.
 */
final class Ansi extends IO
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    private Tty $tty;

    /** Set while buffered_output is coalescing writes; flushed at the end. */
    private ?string $outputBuffer = null;

    /** Config, needed by prep/deprep for the bracketed-paste gate. Wired by Core. */
    private ?Config $config = null;

    /** Previous SIGWINCH/SIGCONT dispositions, restored by deprep. */
    private $oldWinchHandler = null;

    private $oldContHandler = null;

    private const START_BRACKETED_PASTE = "\e[200~";
    private const END_BRACKETED_PASTE = "\e[201~";

    /**
     * The arrow / home / end bindings, ported from ansi.rb's
     * ANSI_CURSOR_KEY_BINDINGS. Up/Down map to history commands that tier 1 does
     * not implement; dispatch no-ops on them (wrap_method_call's respond_to?
     * guard), so binding them is harmless and tracks upstream.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const ANSI_CURSOR_KEY_BINDINGS = [
        'A' => ['ed_prev_history', []],
        'B' => ['ed_next_history', []],
        'C' => ['ed_next_char', ['ctrl' => 'em_next_word', 'meta' => 'em_next_word']],
        'D' => ['ed_prev_char', ['ctrl' => 'ed_prev_word', 'meta' => 'ed_prev_word']],
        'F' => ['ed_move_to_end', []],
        'H' => ['ed_move_to_beg', []],
    ];

    /**
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct(?Tty $tty = null, $input = null, $output = null)
    {
        $this->input = $input ?? \STDIN;
        $this->output = $output ?? \STDOUT;
        $this->tty = $tty ?? new Tty(null, $this->input);
    }

    public function setConfig(Config $config): void
    {
        $this->config = $config;
    }

    public function encoding(): string
    {
        // The port is UTF-8-first (ADR, reline/CONTEXT.md); no locale probing.
        return 'UTF-8';
    }

    // --- Key bindings ------------------------------------------------------

    public function set_default_key_bindings(Config $config): void
    {
        $this->set_bracketed_paste_key_bindings($config);
        $this->set_default_key_bindings_ansi_cursor($config);
        $this->set_default_key_bindings_comprehensive_list($config);
        // S-Tab
        $config->add_default_key_binding_by_keymap('emacs', [27, 91, 90], 'completion_journey_up');
        // M-<space>, C-x C-x
        $config->add_default_key_binding_by_keymap('emacs', [27, 32], 'em_set_mark');
        $config->add_default_key_binding_by_keymap('emacs', [24, 24], 'em_exchange_mark');
    }

    private function set_bracketed_paste_key_bindings(Config $config): void
    {
        $config->add_default_key_binding_by_keymap(
            'emacs',
            \array_values(\unpack('C*', self::START_BRACKETED_PASTE)),
            'bracketed_paste_start'
        );
    }

    private function set_default_key_bindings_ansi_cursor(Config $config): void
    {
        foreach (self::ANSI_CURSOR_KEY_BINDINGS as $char => [$defaultFunc, $modifiers]) {
            $bindings = [
                ["\e[{$char}", $defaultFunc], // CSI + char
                ["\eO{$char}", $defaultFunc], // SS3 + char (application cursor key mode)
            ];
            if (isset($modifiers['ctrl'])) {
                $bindings[] = ["\e[1;5{$char}", $modifiers['ctrl']];
            }
            if (isset($modifiers['meta'])) {
                $bindings[] = ["\e[1;3{$char}", $modifiers['meta']];
                $bindings[] = ["\e\e[{$char}", $modifiers['meta']];
            }
            foreach ($bindings as [$sequence, $func]) {
                $config->add_default_key_binding_by_keymap('emacs', \array_values(\unpack('C*', $sequence)), $func);
            }
        }
    }

    private function set_default_key_bindings_comprehensive_list(Config $config): void
    {
        $list = [
            [[27, 91, 51, 126], 'key_delete'],          // xterm kdch1 (Delete)
            [[27, 91, 53, 126], 'ed_search_prev_history'],
            [[27, 91, 54, 126], 'ed_search_next_history'],
            [[27, 91, 49, 126], 'ed_move_to_beg'],       // Home
            [[27, 91, 52, 126], 'ed_move_to_end'],       // End
            [[27, 91, 55, 126], 'ed_move_to_beg'],       // urxvt Home
            [[27, 91, 56, 126], 'ed_move_to_end'],       // urxvt End
        ];
        foreach ($list as [$key, $func]) {
            $config->add_default_key_binding_by_keymap('emacs', $key, $func);
        }
    }

    // --- Raw input and reading ---------------------------------------------

    public function with_raw_input(callable $fn)
    {
        return $this->tty->withRawMode($fn, new RawOptions(true));
    }

    public function getc(float $timeout): ?int
    {
        return $this->inner_getc($timeout);
    }

    /**
     * Poll @buf first, then wait_readable in 10ms slices servicing signals
     * between slices (ansi.rb:116-137). handle_signal may itself push bytes into
     * @buf (a cursor_pos probe), so @buf is re-checked each iteration.
     */
    private function inner_getc(float $timeout): ?int
    {
        while (true) {
            if ($this->buf !== []) {
                return \array_shift($this->buf);
            }
            if ($this->waitReadable(0.01)) {
                break;
            }
            $timeout -= 0.01;
            if ($timeout <= 0) {
                return null;
            }
            $this->serviceSignals();
        }

        $byte = \fread($this->input, 1);
        if ($byte === '' || $byte === false) {
            return null; // EOF / closed I/O (Errno::EIO upstream).
        }
        $c = \ord($byte);

        // macOS Terminal.app "Escape non-ASCII Input with Control-V" doubles every
        // non-ASCII byte with a leading ^V (0x16). When a ^V is immediately
        // followed by a ready byte on a tty, consume that byte as the real one.
        if ($c === 0x16 && $this->tty->isTty()) {
            $read = [$this->input];
            $write = [];
            $except = [];
            if (@\stream_select($read, $write, $except, 0, 0)) {
                $next = \fread($this->input, 1);
                if ($next !== '' && $next !== false) {
                    return \ord($next);
                }
            }
        }

        return $c;
    }

    private function waitReadable(float $seconds): bool
    {
        $read = [$this->input];
        $write = [];
        $except = [];
        $sec = (int) $seconds;
        $usec = (int) (($seconds - $sec) * 1_000_000);

        return (bool) @\stream_select($read, $write, $except, $sec, $usec);
    }

    public function read_bracketed_paste(): string
    {
        $buffer = '';
        while (\substr($buffer, -\strlen(self::END_BRACKETED_PASTE)) !== self::END_BRACKETED_PASTE) {
            $c = $this->inner_getc(\INF);
            if ($c === null) {
                break;
            }
            $buffer .= \chr($c);
        }
        $string = \substr($buffer, 0, \strlen($buffer) - \strlen(self::END_BRACKETED_PASTE));
        if (\substr($buffer, -\strlen(self::END_BRACKETED_PASTE)) !== self::END_BRACKETED_PASTE) {
            $string = $buffer;
        }

        return \mb_check_encoding($string, 'UTF-8') ? $string : '';
    }

    public function in_pasting(): bool
    {
        return !$this->empty_buffer();
    }

    private function empty_buffer(): bool
    {
        if ($this->buf !== []) {
            return false;
        }

        return !$this->waitReadable(0);
    }

    // --- Screen size and cursor position -----------------------------------

    /** @return array{0: int, 1: int} */
    public function get_screen_size(): array
    {
        try {
            $ws = $this->tty->getWinsize();
            if ($ws->rows() > 0 && $ws->cols() > 0) {
                return [$ws->rows(), $ws->cols()];
            }
        } catch (\Throwable $e) {
            // Fall through to env / default, as ansi.rb rescues SystemCallError.
        }
        $rows = (int) \getenv('LINES');
        $cols = (int) \getenv('COLUMNS');
        if ($rows > 0 && $cols > 0) {
            return [$rows, $cols];
        }

        return [24, 80];
    }

    public function set_screen_size(int $rows, int $columns): void
    {
        try {
            $this->tty->setWinsize($rows, $columns);
        } catch (\Throwable $e) {
            // ansi.rb rescues SystemCallError and returns self.
        }
    }

    public function cursor_pos(): CursorPos
    {
        $pos = null;
        if ($this->both_tty()) {
            $pos = $this->cursor_pos_internal(0.5);
        }

        return new CursorPos($pos[0] ?? 0, $pos[1] ?? 0);
    }

    private function both_tty(): bool
    {
        return \stream_isatty($this->input) && \stream_isatty($this->output);
    }

    /**
     * Write DSR (`\e[6n`), parse the `\e[row;colR` reply, and push any bytes that
     * were not part of the reply back onto @buf (typed-ahead), per ansi.rb:189-206.
     *
     * @return array{0: int, 1: int}|null [col, row] 0-based, or null on timeout
     */
    private function cursor_pos_internal(float $timeout): ?array
    {
        return $this->tty->withRawMode(function () use ($timeout) {
            \fwrite($this->output, "\e[6n");
            \fflush($this->output);
            $deadline = \microtime(true) + $timeout;
            $buf = '';
            $match = null;
            while (($wait = $deadline - \microtime(true)) > 0) {
                if (!$this->waitReadable($wait)) {
                    continue;
                }
                $chunk = \fread($this->input, 1024);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $buf .= $chunk;
                if (\preg_match('/\e\[(\d+);(\d+)R/', $buf, $m, \PREG_OFFSET_CAPTURE)) {
                    $match = $m;
                    $pre = \substr($buf, 0, $m[0][1]);
                    $post = \substr($buf, $m[0][1] + \strlen($m[0][0]));
                    $buf = $pre . $post;
                    break;
                }
            }
            foreach (\str_split($buf) as $ch) {
                if ($ch !== '') {
                    $this->buf[] = \ord($ch);
                }
            }
            if ($match !== null) {
                return [(int) $match[2][0] - 1, (int) $match[1][0] - 1];
            }

            return null;
        }, new RawOptions(true));
    }

    // --- Output ------------------------------------------------------------

    public function write(string $string): void
    {
        if ($this->outputBuffer !== null) {
            $this->outputBuffer .= $string;
        } else {
            \fwrite($this->output, $string);
        }
    }

    public function buffered_output(callable $fn): void
    {
        $this->outputBuffer = '';
        try {
            $fn();
            \fwrite($this->output, $this->outputBuffer);
        } finally {
            $this->outputBuffer = null;
        }
    }

    public function move_cursor_column(int $x): void
    {
        $this->write("\e[" . ($x + 1) . 'G');
    }

    public function move_cursor_up(int $x): void
    {
        if ($x > 0) {
            $this->write("\e[{$x}A");
        } elseif ($x < 0) {
            $this->move_cursor_down(-$x);
        }
    }

    public function move_cursor_down(int $x): void
    {
        if ($x > 0) {
            $this->write("\e[{$x}B");
        } elseif ($x < 0) {
            $this->move_cursor_up(-$x);
        }
    }

    public function hide_cursor(): void
    {
        $this->write("\e[?25l");
    }

    public function show_cursor(): void
    {
        $this->write("\e[?25h");
    }

    public function erase_after_cursor(): void
    {
        $this->write("\e[K");
    }

    /**
     * Scroll by writing `"\n" * x`, not CSI S: CSI S corrupts the scrollback in
     * some terminals (ruby/reline#576). Only correct at the bottom of the scroll
     * range, which is where the renderer calls it (io-contract §3).
     */
    public function scroll_down(int $x): void
    {
        if ($x === 0) {
            return;
        }
        $this->write(\str_repeat("\n", $x));
    }

    public function clear_screen(): void
    {
        $this->write("\e[2J");
        $this->write("\e[1;1H");
    }

    // --- Signals -----------------------------------------------------------

    public function set_winch_handler(callable $handler): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        // SIGWINCH: chain the previous disposition, then flag a resize.
        $this->oldWinchHandler = \pcntl_signal_get_handler(\SIGWINCH);
        \pcntl_signal(\SIGWINCH, function ($signo) use ($handler): void {
            $handler();
            if (\is_callable($this->oldWinchHandler)) {
                ($this->oldWinchHandler)($signo);
            }
        });
        // SIGCONT: re-render on job-control resume. Re-asserting raw mode after a
        // resume needs a bare (unscoped) raw set, which Tty deliberately does not
        // expose (ADR-0016); the main read loop's withRawMode scope re-establishes
        // it on the next call, so job-control recovery is left to that. Documented
        // in CONTEXT.md as a tier-1 gap.
        $this->oldContHandler = \pcntl_signal_get_handler(\SIGCONT);
        \pcntl_signal(\SIGCONT, function ($signo) use ($handler): void {
            $handler();
            if (\is_callable($this->oldContHandler)) {
                ($this->oldContHandler)($signo);
            }
        });
    }

    // --- Session prep ------------------------------------------------------

    public function read_single_char(float $timeout): ?string
    {
        // Quoted-insert: disable intr so C-c / C-z / C-\ read as literal bytes.
        return $this->tty->withRawMode(fn () => parent::read_single_char($timeout), new RawOptions(false));
    }

    public function prep()
    {
        if ($this->bracketedPasteEnabled()) {
            $this->write("\e[?2004h");
        }

        return null;
    }

    public function deprep($otio): void
    {
        if ($this->bracketedPasteEnabled()) {
            $this->write("\e[?2004l");
        }
        if (\function_exists('pcntl_signal')) {
            if ($this->oldWinchHandler !== null) {
                \pcntl_signal(\SIGWINCH, $this->oldWinchHandler === false ? \SIG_DFL : $this->oldWinchHandler);
            }
            if ($this->oldContHandler !== null) {
                \pcntl_signal(\SIGCONT, $this->oldContHandler === false ? \SIG_DFL : $this->oldContHandler);
            }
        }
    }

    private function bracketedPasteEnabled(): bool
    {
        return $this->config !== null && $this->config->enable_bracketed_paste() && $this->both_tty();
    }
}
