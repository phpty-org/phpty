<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The line editor, ported from lib/reline/line_editor.rb — the tier-1 subset.
 *
 * Buffer state, the emacs single-line editing commands, and the renderer. Per
 * ADR-0017 the renderer is ported in its real shape — cell-tuple rows, the
 * cached rendered screen, per-row then per-span diffing (render / render_line_
 * differential) — and tier 1 merely restricts what drives it: one buffer line,
 * no wrap that scrolls, no dialog overlays, no rprompt. The diff algorithm is
 * never simplified; it is exactly upstream's, so a one-row frame and a
 * dialog-covered frame flow through the same code.
 *
 * Overlay levels degenerate cleanly: with no dialogs and no rprompt each row is
 * `[[0, pw, prompt], [pw, lw, line]]`, so calculate_overlay_levels paints two
 * spans (prompt then input) and the "topmost cell per column" reduces to "input
 * over prompt where they meet" — the general algorithm with the dialog inputs
 * absent, not a special case.
 *
 * Absent by design (ADR-0017): vi_* commands, history navigation, completion /
 * dialogs, multiline buffers and wrapping. The buffer is still stored as
 * @buffer_of_lines (a list of lines) even though tier 1 only ever has one, so
 * tier 2 widens without reshaping. Upstream method bodies tier 1 does not need
 * are simply not ported — absence tracks upstream diffs better than stubs.
 *
 * The IO gate and Config are injected rather than read from a global
 * `Reline::IOGate` constant (see IO.php); every `Reline::IOGate.foo` upstream is
 * `$this->io->foo` here.
 */
final class LineEditor
{
    private ?Config $config;

    private IO $io;

    /** @var array{0: int, 1: int} [rows, cols] */
    private array $screen_size = [0, 0];

    private string $prompt = '';

    private int $byte_pointer = 0;

    /** @var list<string> the buffer, one line per element (tier 1 keeps size 1) */
    private array $buffer_of_lines = [''];

    private int $line_index = 0;

    private bool $is_multiline = false;

    private bool $finished = false;

    private bool $eof = false;

    private KillRing $kill_ring;

    private string $continuous_insertion_buffer = '';

    private bool $in_pasting = false;

    private bool $just_cursor_moving = false;

    private int $scroll_partial_screen = 0;

    private bool $interrupted = false;

    private bool $resized = false;

    private bool $completion_occurs = false;

    /** @var array<string, array{0: mixed, 1: mixed}> the with_cache memo table */
    private array $cache = [];

    /** @var array{base_y: int, lines: list<array<int, array{0: int, 1: int, 2: string}|null>>, cursor_y: int} */
    private array $rendered_screen = ['base_y' => 0, 'lines' => [], 'cursor_y' => 0];

    /** @var array<string, mixed> */
    private array $prev_action_state = [];

    /** @var array<string, mixed> */
    private array $next_action_state = [];

    /** @var list<array{0: list<string>, 1: int, 2: int}> */
    private array $undo_redo_history = [];

    private int $undo_redo_index = 0;

    private bool $restoring = false;

    /** @var callable|null the previous SIGINT disposition, restored in finalize */
    private $old_trap = null;

    public function __construct(?Config $config, IO $io)
    {
        $this->config = $config;
        $this->io = $io;
        $this->kill_ring = new KillRing();
        $this->reset_variables();
    }

    public function encoding(): string
    {
        return $this->io->encoding();
    }

    // --- Lifecycle ---------------------------------------------------------

    public function reset(string $prompt = ''): void
    {
        $this->screen_size = $this->io->get_screen_size();
        $this->reset_variables($prompt);
        $this->rendered_screen['base_y'] = $this->io->cursor_pos()->y;
    }

    public function reset_variables(string $prompt = ''): void
    {
        $this->prompt = \str_replace("\n", '\\n', $prompt);
        $this->is_multiline = false;
        $this->finished = false;
        $this->just_cursor_moving = false;
        $this->eof = false;
        $this->continuous_insertion_buffer = '';
        $this->scroll_partial_screen = 0;
        $this->in_pasting = false;
        $this->interrupted = false;
        $this->resized = false;
        $this->completion_occurs = false;
        $this->cache = [];
        $this->rendered_screen = ['base_y' => 0, 'lines' => [], 'cursor_y' => 0];
        $this->undo_redo_history = [[[''], 0, 0]];
        $this->undo_redo_index = 0;
        $this->restoring = false;
        $this->prev_action_state = [];
        $this->next_action_state = [];
        $this->reset_line();
    }

    public function reset_line(): void
    {
        $this->byte_pointer = 0;
        $this->buffer_of_lines = [''];
        $this->line_index = 0;
        $this->cache = [];
    }

    public function multiline_on(): void
    {
        $this->is_multiline = true;
    }

    public function multiline_off(): void
    {
        $this->is_multiline = false;
    }

    /** For the render-differential unit tests, which set the size directly. */
    public function set_screen_size_for_test(int $rows, int $cols): void
    {
        $this->screen_size = [$rows, $cols];
    }

    // --- Signals -----------------------------------------------------------

    public function set_pasting_state(bool $in_pasting): void
    {
        if ($this->in_pasting && !$in_pasting) {
            $this->process_insert(true);
        }
        $this->in_pasting = $in_pasting;
    }

    public function handle_signal(): void
    {
        $this->handle_interrupted();
        $this->handle_resized();
    }

    private function handle_resized(): void
    {
        if (!$this->resized) {
            return;
        }
        $this->screen_size = $this->io->get_screen_size();
        $this->resized = false;
        $this->scroll_into_view();
        $this->io->move_cursor_up($this->rendered_screen['cursor_y']);
        $this->rendered_screen['base_y'] = $this->io->cursor_pos()->y;
        $this->clear_rendered_screen_cache();
        $this->render();
    }

    private function handle_interrupted(): void
    {
        if (!$this->interrupted) {
            return;
        }
        $this->interrupted = false;
        $this->render();
        $cursorToBottomOffset = \count($this->rendered_screen['lines']) - $this->rendered_screen['cursor_y'];
        $this->io->scroll_down($cursorToBottomOffset);
        $this->io->move_cursor_column(0);
        $this->clear_rendered_screen_cache();
        // Upstream raises Interrupt here (aborting readline). Tier 1 keeps the
        // deferred-flag plumbing but stops short of raising: SIGINT semantics
        // (re-raise / old-trap dispatch) are not exercised by the tier-1 tests
        // and are deferred with history — see CONTEXT.md.
    }

    public function set_signal_handlers(): void
    {
        $this->io->set_winch_handler(function (): void {
            $this->resized = true;
        });
        if (\function_exists('pcntl_signal')) {
            $this->old_trap = \pcntl_signal_get_handler(\SIGINT);
            \pcntl_signal(\SIGINT, function (): void {
                $this->interrupted = true;
            });
        }
    }

    public function finalize(): void
    {
        if (\function_exists('pcntl_signal') && $this->old_trap !== null) {
            \pcntl_signal(\SIGINT, $this->old_trap === false ? \SIG_DFL : $this->old_trap);
        }
    }

    // --- Buffer queries ----------------------------------------------------

    public function byte_pointer(): int
    {
        return $this->byte_pointer;
    }

    public function set_byte_pointer(int $val): void
    {
        $this->byte_pointer = $val;
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    public function finish(): void
    {
        $this->finished = true;
        if ($this->config !== null) {
            $this->config->reset();
        }
    }

    public function current_line(): string
    {
        return $this->buffer_of_lines[$this->line_index];
    }

    public function line(): ?string
    {
        return $this->eof() ? null : \implode("\n", $this->buffer_of_lines);
    }

    /** @return list<string> */
    public function whole_lines(): array
    {
        return $this->buffer_of_lines;
    }

    public function whole_buffer(): string
    {
        return \implode("\n", $this->whole_lines());
    }

    private function buffer_empty(): bool
    {
        return $this->current_line() === '' && \count($this->buffer_of_lines) === 1;
    }

    // --- Input dispatch ----------------------------------------------------

    public function update(Key $key): void
    {
        $modified = $this->input_key($key);
        if (!$this->in_pasting) {
            $this->scroll_into_view();
            $this->just_cursor_moving = !$modified;
            $this->just_cursor_moving = false;
        }
    }

    public function input_key(Key $key): ?bool
    {
        $oldBufferOfLines = $this->buffer_of_lines;
        if ($this->config !== null) {
            $this->config->reset_oneshot_key_bindings();
        }
        if ($key->char === null) {
            $this->process_insert(true);
            $this->eof = $this->buffer_empty();
            $this->finish();

            return null;
        }

        $this->completion_occurs = false;

        $this->process_key($key->char, $key->method_symbol);

        $this->prev_action_state = $this->next_action_state;
        $this->next_action_state = [];

        $modified = $oldBufferOfLines !== $this->buffer_of_lines;

        if (!$this->restoring) {
            $this->push_undo_redo($modified);
        }
        $this->restoring = false;

        if ($this->in_pasting) {
            return null;
        }

        return $modified;
    }

    /**
     * @param string|int|null $method_symbol
     */
    private function process_key(string $key, $method_symbol): void
    {
        $this->process_insert($method_symbol !== 'ed_insert');
        $this->run_for_operators($key, $method_symbol);
    }

    /**
     * @param string|int|null $method_symbol
     */
    private function run_for_operators(string $key, $method_symbol): void
    {
        // Upstream branches here on numeric-argument and vi-operator state
        // (line_editor.rb:921-951); both are vi-only and absent in tier 1, so
        // every emacs command takes the same plain dispatch path.
        $this->wrap_method_call($method_symbol, $key, false);
        $this->kill_ring->process();
    }

    /**
     * @param string|int|null $method_symbol
     */
    public function wrap_method_call($method_symbol, string $key, bool $with_operator): void
    {
        // Unknown / unbound methods (history and vi commands absent in tier 1)
        // no-op here, mirroring upstream's `return unless respond_to?`. This is
        // the ed_unassigned-equivalent: dispatch simply does nothing.
        if (!\is_string($method_symbol) || !\method_exists($this, $method_symbol)) {
            return;
        }
        // Tier 1 is emacs-only: @vi_arg is always nil and no motion is inclusive,
        // so wrap_method_call reduces to a plain single-argument call.
        $this->{$method_symbol}($key);
    }

    private function push_undo_redo(bool $modified): void
    {
        if ($modified) {
            $this->undo_redo_history = \array_slice($this->undo_redo_history, 0, $this->undo_redo_index + 1);
            $this->undo_redo_history[] = [$this->buffer_of_lines, $this->byte_pointer, $this->line_index];
            if (\count($this->undo_redo_history) > self::MAX_UNDO_REDO_HISTORY_SIZE) {
                \array_shift($this->undo_redo_history);
            }
            $this->undo_redo_index = \count($this->undo_redo_history) - 1;
        } else {
            $this->undo_redo_history[$this->undo_redo_index] = [$this->buffer_of_lines, $this->byte_pointer, $this->line_index];
        }
    }

    private const MAX_UNDO_REDO_HISTORY_SIZE = 100;

    public function scroll_into_view(): void
    {
        [, $wrappedCursorY] = $this->wrapped_cursor_position();
        if ($wrappedCursorY < $this->screen_scroll_top()) {
            $this->scroll_partial_screen = $wrappedCursorY;
        }
        if ($wrappedCursorY >= $this->screen_scroll_top() + $this->screen_height()) {
            $this->scroll_partial_screen = $wrappedCursorY - $this->screen_height() + 1;
        }
    }

    // --- Text mutation primitives ------------------------------------------

    public function set_current_line(string $line, ?int $byte_pointer = null): void
    {
        $cursor = $this->current_byte_pointer_cursor();
        $this->buffer_of_lines[$this->line_index] = $line;
        if ($byte_pointer !== null) {
            $this->byte_pointer = $byte_pointer;
        } else {
            $this->calculate_nearest_cursor($cursor);
        }
        // process_auto_indent is a no-op in tier 1 (no auto_indent_proc).
    }

    public function insert_text(string $text): void
    {
        if (\strlen($this->buffer_of_lines[$this->line_index]) === $this->byte_pointer) {
            $this->buffer_of_lines[$this->line_index] .= $text;
        } else {
            $this->buffer_of_lines[$this->line_index] = $this->byteinsert($this->buffer_of_lines[$this->line_index], $this->byte_pointer, $text);
        }
        $this->byte_pointer += \strlen($text);
    }

    public function delete_text(?int $start = null, ?int $length = null): void
    {
        if ($start === null && $length === null) {
            if (\count($this->buffer_of_lines) === 1) {
                $this->buffer_of_lines[$this->line_index] = '';
                $this->byte_pointer = 0;
            }
        } elseif ($start !== null && $length !== null) {
            $before = \substr($this->current_line(), 0, $start);
            $after = \substr($this->current_line(), $start + $length);
            $this->set_current_line($before . $after);
        } else {
            $this->set_current_line(\substr($this->current_line(), 0, (int) $start));
        }
    }

    private function current_byte_pointer_cursor(): int
    {
        return $this->calculate_width(\substr($this->current_line(), 0, $this->byte_pointer));
    }

    /**
     * Move @byte_pointer to the grapheme boundary nearest the display column
     * $cursor, ported from line_editor.rb:308-341 (emacs branch only).
     */
    private function calculate_nearest_cursor(int $cursor): void
    {
        $lineToCalc = $this->current_line();
        $newCursorMax = $this->calculate_width($lineToCalc);
        $newCursor = 0;
        $newBytePointer = 0;
        $endOfLineCursor = $newCursorMax;
        foreach ($this->grapheme_clusters($lineToCalc) as $gc) {
            $mbcharWidth = Unicode::get_mbchar_width($gc);
            $now = $newCursor + $mbcharWidth;
            if ($now > $endOfLineCursor || $now > $cursor) {
                break;
            }
            $newCursor += $mbcharWidth;
            $newBytePointer += \strlen($gc);
        }
        $this->byte_pointer = $newBytePointer;
    }

    /** @return array{0: string, 1: string} [remaining, removed] */
    private function byteslice(string $str, int $byte_pointer, int $size): array
    {
        $newStr = \substr($str, 0, $byte_pointer) . \substr($str, $byte_pointer + $size);
        $removed = \substr($str, $byte_pointer, $size);

        return [$newStr, $removed];
    }

    private function byteinsert(string $str, int $byte_pointer, string $other): string
    {
        return \substr($str, 0, $byte_pointer) . $other . \substr($str, $byte_pointer);
    }

    private function calculate_width(string $str, bool $allow_escape_code = false): int
    {
        return Unicode::calculate_width($str, $allow_escape_code);
    }

    /** @return list<string> */
    private function grapheme_clusters(string $str): array
    {
        if ($str === '') {
            return [];
        }
        \preg_match_all('/\X/u', $str, $m);

        return $m[0];
    }

    /**
     * @param string|int|null $method_symbol
     */
    private function set_next_action_state(string $type, $value): void
    {
        $this->next_action_state[$type] = $value;
    }

    /**
     * @return mixed
     */
    private function prev_action_state_value(string $type)
    {
        return $this->prev_action_state[$type] ?? null;
    }

    // --- Editing commands (emacs, single-line) -----------------------------

    private function process_insert(bool $force = false): void
    {
        if ($this->continuous_insertion_buffer === '' || ($this->in_pasting && !$force)) {
            return;
        }
        $this->insert_text($this->continuous_insertion_buffer);
        $this->continuous_insertion_buffer = '';
    }

    private function ed_insert(string $str): void
    {
        if (!\mb_check_encoding($str, 'UTF-8')) {
            return;
        }
        if ($this->in_pasting) {
            $this->continuous_insertion_buffer .= $str;

            return;
        }
        if ($this->continuous_insertion_buffer !== '') {
            $this->process_insert();
        }
        $this->insert_text($str);
    }

    private function ed_digit(string $key): void
    {
        // No @vi_arg in tier 1, so ed_digit is always a literal insert.
        $this->ed_insert($key);
    }

    private function insert_raw_char(string $str, int $arg = 1): void
    {
        for ($i = 0; $i < $arg; $i++) {
            if ($str === "\n" || $str === "\r") {
                $this->key_newline($str);
            } elseif ($str !== "\0") {
                $this->ed_insert($str);
            }
        }
    }

    private function key_newline(string $key): void
    {
        // Multiline insertion is tier 2; single-line readline never sets it.
        if ($this->is_multiline) {
            // Intentionally minimal until tier 2 ports insert_new_line.
        }
    }

    private function ed_next_char(string $key, int $arg = 1): void
    {
        $byteSize = Unicode::get_next_mbchar_size($this->current_line(), $this->byte_pointer);
        if ($this->byte_pointer < \strlen($this->current_line())) {
            $this->byte_pointer += $byteSize;
        } elseif ($this->byte_pointer === \strlen($this->current_line()) && $this->line_index < \count($this->buffer_of_lines) - 1) {
            $this->byte_pointer = 0;
            $this->line_index += 1;
        }
        $arg -= 1;
        if ($arg > 0) {
            $this->ed_next_char($key, $arg);
        }
    }

    private function ed_prev_char(string $key, int $arg = 1): void
    {
        if ($this->byte_pointer > 0) {
            $byteSize = Unicode::get_prev_mbchar_size($this->current_line(), $this->byte_pointer);
            $this->byte_pointer -= $byteSize;
        } elseif ($this->byte_pointer === 0 && $this->line_index > 0) {
            $this->line_index -= 1;
            $this->byte_pointer = \strlen($this->current_line());
        }
        $arg -= 1;
        if ($arg > 0) {
            $this->ed_prev_char($key, $arg);
        }
    }

    private function ed_move_to_beg(string $key): void
    {
        $this->byte_pointer = 0;
    }

    private function ed_move_to_end(string $key): void
    {
        $this->byte_pointer = \strlen($this->current_line());
    }

    private function ed_newline(string $key): void
    {
        $this->process_insert(true);
        // Single-line readline: accept immediately (multiline is tier 2).
        $this->finish();
    }

    private function em_delete_prev_char(string $key, int $arg = 1): void
    {
        for ($i = 0; $i < $arg; $i++) {
            if ($this->byte_pointer === 0 && $this->line_index > 0) {
                $this->byte_pointer = \strlen($this->buffer_of_lines[$this->line_index - 1]);
                $removed = \array_splice($this->buffer_of_lines, $this->line_index, 1)[0];
                $this->buffer_of_lines[$this->line_index - 1] .= $removed;
                $this->line_index -= 1;
            } elseif ($this->byte_pointer > 0) {
                $byteSize = Unicode::get_prev_mbchar_size($this->current_line(), $this->byte_pointer);
                [$line] = $this->byteslice($this->current_line(), $this->byte_pointer - $byteSize, $byteSize);
                $this->set_current_line($line, $this->byte_pointer - $byteSize);
            }
        }
    }

    private function ed_kill_line(string $key): void
    {
        if (\strlen($this->current_line()) > $this->byte_pointer) {
            [$line, $deleted] = $this->byteslice($this->current_line(), $this->byte_pointer, \strlen($this->current_line()) - $this->byte_pointer);
            $this->set_current_line($line, \strlen($line));
            $this->kill_ring->append($deleted);
        } elseif ($this->byte_pointer === \strlen($this->current_line()) && \count($this->buffer_of_lines) > $this->line_index + 1) {
            $next = \array_splice($this->buffer_of_lines, $this->line_index + 1, 1)[0];
            $this->set_current_line($this->current_line() . $next, \strlen($this->current_line()));
        }
    }

    private function em_kill_line(string $key): void
    {
        if ($this->current_line() !== '') {
            $this->kill_ring->append($this->current_line(), true);
            $this->set_current_line('', 0);
        }
    }

    private function em_delete(string $key): void
    {
        if ($this->buffer_empty() && $key === "\x04") { // C-d
            $this->eof = true;
            $this->finish();
        } elseif ($this->byte_pointer < \strlen($this->current_line())) {
            $splittedLast = \substr($this->current_line(), $this->byte_pointer);
            $mbchar = $this->grapheme_clusters($splittedLast)[0] ?? '';
            [$line] = $this->byteslice($this->current_line(), $this->byte_pointer, \strlen($mbchar));
            $this->set_current_line($line);
        } elseif ($this->byte_pointer === \strlen($this->current_line()) && \count($this->buffer_of_lines) > $this->line_index + 1) {
            $next = \array_splice($this->buffer_of_lines, $this->line_index + 1, 1)[0];
            $this->set_current_line($this->current_line() . $next, \strlen($this->current_line()));
        }
    }

    private function key_delete(string $key): void
    {
        if ($this->config !== null && $this->config->editing_mode_is('emacs')) {
            $this->em_delete($key);
        }
    }

    private function em_yank(string $key): void
    {
        $yanked = $this->kill_ring->yank();
        if ($yanked === null) {
            return;
        }
        $beforeCursor = \substr($this->current_line(), 0, $this->byte_pointer);
        $afterCursor = \substr($this->current_line(), $this->byte_pointer);
        $this->set_current_line($beforeCursor . $yanked . $afterCursor, \strlen($beforeCursor) + \strlen($yanked));
        $this->set_next_action_state('em_yank_line', [$beforeCursor, $afterCursor]);
    }

    private function em_yank_pop(string $key): void
    {
        $state = $this->prev_action_state_value('em_yank_line');
        if (!\is_array($state)) {
            return;
        }
        [$beforeCursor, $afterCursor] = $state;
        $popped = $this->kill_ring->yank_pop();
        if ($popped === null) {
            return;
        }
        [$yanked] = $popped;
        $this->set_current_line($beforeCursor . $yanked . $afterCursor, \strlen($beforeCursor) + \strlen($yanked));
        $this->set_next_action_state('em_yank_line', [$beforeCursor, $afterCursor]);
    }

    private function ed_clear_screen(string $key): void
    {
        $this->io->clear_screen();
        $this->screen_size = $this->io->get_screen_size();
        $this->rendered_screen['base_y'] = 0;
        $this->clear_rendered_screen_cache();
    }

    private function em_next_word(string $key): void
    {
        if (\strlen($this->current_line()) > $this->byte_pointer) {
            $byteSize = Unicode::em_forward_word($this->current_line(), $this->byte_pointer);
            $this->byte_pointer += $byteSize;
        }
    }

    private function ed_prev_word(string $key): void
    {
        if ($this->byte_pointer > 0) {
            $byteSize = Unicode::em_backward_word($this->current_line(), $this->byte_pointer);
            $this->byte_pointer -= $byteSize;
        }
    }

    private function em_delete_next_word(string $key): void
    {
        if (\strlen($this->current_line()) > $this->byte_pointer) {
            $byteSize = Unicode::em_forward_word($this->current_line(), $this->byte_pointer);
            [$line, $word] = $this->byteslice($this->current_line(), $this->byte_pointer, $byteSize);
            $this->set_current_line($line);
            $this->kill_ring->append($word);
        }
    }

    private function ed_delete_prev_word(string $key): void
    {
        if ($this->byte_pointer > 0) {
            $byteSize = Unicode::em_backward_word($this->current_line(), $this->byte_pointer);
            [$line, $word] = $this->byteslice($this->current_line(), $this->byte_pointer - $byteSize, $byteSize);
            $this->set_current_line($line, $this->byte_pointer - $byteSize);
            $this->kill_ring->append($word, true);
        }
    }

    private function ed_transpose_chars(string $key): void
    {
        if ($this->byte_pointer > 0) {
            if ($this->byte_pointer < \strlen($this->current_line())) {
                $byteSize = Unicode::get_next_mbchar_size($this->current_line(), $this->byte_pointer);
                $this->byte_pointer += $byteSize;
            }
            $back1ByteSize = Unicode::get_prev_mbchar_size($this->current_line(), $this->byte_pointer);
            if (($this->byte_pointer - $back1ByteSize) > 0) {
                $back2ByteSize = Unicode::get_prev_mbchar_size($this->current_line(), $this->byte_pointer - $back1ByteSize);
                $back2Pointer = $this->byte_pointer - $back1ByteSize - $back2ByteSize;
                [$line, $back2Mbchar] = $this->byteslice($this->current_line(), $back2Pointer, $back2ByteSize);
                $this->set_current_line($this->byteinsert($line, $this->byte_pointer - $back2ByteSize, $back2Mbchar));
            }
        }
    }

    private function ed_ignore(string $key): void
    {
    }

    private function ed_unassigned(string $key): void
    {
    }

    // --- Prompt / geometry helpers -----------------------------------------

    public function screen_height(): int
    {
        return $this->screen_size[0];
    }

    public function screen_width(): int
    {
        return $this->screen_size[1];
    }

    public function screen_scroll_top(): int
    {
        return $this->scroll_partial_screen;
    }

    private function check_mode_string(): ?string
    {
        if ($this->config !== null && $this->config->show_mode_in_prompt()) {
            return $this->config->emacs_mode_string();
        }

        return null;
    }

    /**
     * @param list<string> $buffer
     * @return list<string>
     */
    private function check_multiline_prompt(array $buffer, ?string $mode_string): array
    {
        $prompt = $this->prompt;
        // Single-line (tier 1): one prompt, then blanks for any extra lines.
        $mode_string = $this->check_mode_string();
        if ($mode_string !== null) {
            $prompt = $mode_string . $prompt;
        }
        $result = [$prompt];
        for ($i = 0; $i < \count($buffer) - 1; $i++) {
            $result[] = '';
        }

        return $result;
    }

    /**
     * @param list<string> $before
     * @return list<string>
     */
    private function modify_lines(array $before, bool $complete): array
    {
        // No output_modifier_proc in tier 1: escape each line for printing.
        return \array_map(static fn (string $l): string => Unicode::escape_for_print($l), $before);
    }

    /**
     * @param list<mixed> $deps
     * @param callable(list<mixed>, mixed, mixed): mixed $block
     * @return mixed
     */
    private function with_cache(string $key, array $deps, callable $block)
    {
        [$cachedDeps, $value] = $this->cache[$key] ?? [null, null];
        if ($cachedDeps !== $deps) {
            $value = $block($deps, $cachedDeps, $value);
            $this->cache[$key] = [$deps, $value];
        }

        return $value;
    }

    /** @return list<string> */
    public function modified_lines(): array
    {
        return $this->with_cache('modified_lines', [$this->whole_lines(), $this->finished()], function (array $deps) {
            [$whole, $complete] = $deps;

            return $this->modify_lines($whole, $complete);
        });
    }

    /** @return list<string> */
    public function prompt_list(): array
    {
        return $this->with_cache('prompt_list', [$this->whole_lines(), $this->check_mode_string(), null, null], function (array $deps) {
            [$lines, $modeString] = $deps;

            return $this->check_multiline_prompt($lines, $modeString);
        });
    }

    /**
     * @return list<list<array{0: string, 1: string}>>
     */
    public function wrapped_prompt_and_input_lines(): array
    {
        return $this->with_cache(
            'wrapped_prompt_and_input_lines',
            [\count($this->buffer_of_lines), $this->modified_lines(), $this->prompt_list(), $this->screen_width()],
            function (array $deps, $prevCacheKey, $cachedValue) {
                [$n, $lines, $prompts, $width] = $deps;
                $cachedWraps = [];
                if (\is_array($prevCacheKey) && $prevCacheKey[3] === $width && \is_array($cachedValue)) {
                    [$prevN, $prevLines, $prevPrompts] = $prevCacheKey;
                    for ($i = 0; $i < $prevN; $i++) {
                        $cachedWraps[$this->wrapKey($prevPrompts[$i] ?? '', $prevLines[$i] ?? '')] = $cachedValue[$i];
                    }
                }

                $out = [];
                for ($i = 0; $i < $n; $i++) {
                    $prompt = $prompts[$i] ?? '';
                    $line = $lines[$i] ?? '';
                    $key = $this->wrapKey($prompt, $line);
                    if (isset($cachedWraps[$key])) {
                        $out[] = $cachedWraps[$key];
                        continue;
                    }
                    $wrappedPrompts = Unicode::split_line_by_width($prompt, $width);
                    $codeLinePrompt = \array_pop($wrappedPrompts);
                    $wrappedLines = Unicode::split_line_by_width($line, $width, $this->calculate_width($codeLinePrompt, true));
                    $rows = [];
                    foreach ($wrappedPrompts as $p) {
                        $rows[] = [$p, ''];
                    }
                    $rows[] = [$codeLinePrompt, $wrappedLines[0] ?? ''];
                    foreach (\array_slice($wrappedLines, 1) as $c) {
                        $rows[] = ['', $c];
                    }
                    $out[] = $rows;
                }

                return $out;
            }
        );
    }

    private function wrapKey(string $prompt, string $line): string
    {
        return $prompt . "\x00" . $line;
    }

    /**
     * @return array{0: int, 1: int} [x, y]
     */
    public function wrapped_cursor_position(): array
    {
        $promptWidth = $this->calculate_width($this->prompt_list()[$this->line_index], true);
        $lineBeforeCursor = Unicode::escape_for_print(\substr($this->whole_lines()[$this->line_index], 0, $this->byte_pointer));
        $wrappedLineBeforeCursor = Unicode::split_line_by_width(\str_repeat(' ', $promptWidth) . $lineBeforeCursor, $this->screen_width());
        $sum = 0;
        foreach (\array_slice($this->wrapped_prompt_and_input_lines(), 0, $this->line_index) as $rows) {
            $sum += \count($rows);
        }
        $wrappedCursorY = $sum + \count($wrappedLineBeforeCursor) - 1;
        $wrappedCursorX = $this->calculate_width($wrappedLineBeforeCursor[\count($wrappedLineBeforeCursor) - 1]);

        return [$wrappedCursorX, $wrappedCursorY];
    }

    // --- Rendering (ADR-0017: full-shape port) -----------------------------

    /**
     * @param list<int> $overlay_levels flattened [x, w, l] triples
     * @return list<int|null>
     */
    private function calculate_overlay_levels(array $overlay_levels): array
    {
        $levels = [];
        foreach ($overlay_levels as [$x, $w, $l]) {
            $end = $x + $w;
            for ($i = \count($levels); $i < $end; $i++) {
                $levels[$i] = null;
            }
            for ($i = $x; $i < $end; $i++) {
                $levels[$i] = $l;
            }
        }

        return $levels;
    }

    /**
     * Per-column diff of one row, ported verbatim from line_editor.rb:406-434.
     * Cells are [x, w, content] triples; a null entry is an absent overlay. This
     * is the algorithm ADR-0017 forbids simplifying; tier 1 just feeds it rows
     * with at most two cells (prompt + input).
     *
     * @param list<array{0: int, 1: int, 2: string}|null> $old_items
     * @param list<array{0: int, 1: int, 2: string}|null> $new_items
     */
    public function render_line_differential(array $old_items, array $new_items): void
    {
        $oldTriples = [];
        foreach ($old_items as $i => $oldItem) {
            if ($oldItem === null) {
                continue;
            }
            [$x, $w, $c] = $oldItem;
            $newItem = $new_items[$i] ?? null;
            $nx = $newItem[0] ?? null;
            $nc = $newItem[2] ?? null;
            $oldTriples[] = [$x, $w, ($c === $nc && $x === $nx) ? $i : -1];
        }
        $oldLevels = $this->calculate_overlay_levels($oldTriples);

        $newTriples = [];
        foreach ($new_items as $i => $newItem) {
            if ($newItem === null) {
                continue;
            }
            [$x, $w] = $newItem;
            $newTriples[] = [$x, $w, $i];
        }
        $newLevels = \array_slice($this->calculate_overlay_levels($newTriples), 0, $this->screen_width());

        $baseX = 0;
        foreach ($this->chunkLevels($newLevels, $oldLevels) as [$level, $width]) {
            if ($level === 'skip') {
                // Unchanged span; emit nothing.
            } elseif ($level === 'blank') {
                $this->io->move_cursor_column($baseX);
                $this->io->write($this->io->reset_color_sequence() . \str_repeat(' ', $width));
            } else {
                [$x, $w, $content] = $new_items[$level];
                $coverBegin = $baseX !== 0 && ($newLevels[$baseX - 1] ?? null) === $level;
                $coverEnd = ($newLevels[$baseX + $width] ?? null) === $level;
                $pos = 0;
                if (!($x === $baseX && $w === $width)) {
                    [$content, $pos] = Unicode::take_mbchar_range($content, $baseX - $x, $width, $coverBegin, $coverEnd, true);
                }
                $this->io->move_cursor_column($x + $pos);
                $reset = $this->io->reset_color_sequence();
                $this->io->write($reset . $content . $reset);
            }
            $baseX += $width;
        }
        if (\count($oldLevels) > \count($newLevels)) {
            $this->io->move_cursor_column(\count($newLevels));
            $this->io->erase_after_cursor();
        }
    }

    /**
     * Ruby `new_levels.zip(old_levels).chunk { |n, o| n == o ? :skip : n || :blank }`.
     * Returns runs as [key, length] where key is 'skip', 'blank', or an int level.
     *
     * @param list<int|null> $newLevels
     * @param list<int|null> $oldLevels
     * @return list<array{0: string|int, 1: int}>
     */
    private function chunkLevels(array $newLevels, array $oldLevels): array
    {
        $runs = [];
        $currentKey = null;
        $currentLen = 0;
        $hasCurrent = false;
        $count = \count($newLevels);
        for ($i = 0; $i < $count; $i++) {
            $n = $newLevels[$i];
            $o = $oldLevels[$i] ?? null;
            if ($n === $o) {
                $key = 'skip';
            } elseif ($n !== null) {
                $key = $n;
            } else {
                $key = 'blank';
            }
            if ($hasCurrent && $key === $currentKey) {
                $currentLen++;
            } else {
                if ($hasCurrent) {
                    $runs[] = [$currentKey, $currentLen];
                }
                $currentKey = $key;
                $currentLen = 1;
                $hasCurrent = true;
            }
        }
        if ($hasCurrent) {
            $runs[] = [$currentKey, $currentLen];
        }

        return $runs;
    }

    public function render_finished(): void
    {
        $this->io->buffered_output(function (): void {
            $this->render_differential([], 0, 0);
            $lines = [];
            $count = \count($this->buffer_of_lines);
            for ($i = 0; $i < $count; $i++) {
                $line = Unicode::strip_non_printing_start_end($this->prompt_list()[$i]) . $this->modified_lines()[$i];
                $wrappedLines = Unicode::split_line_by_width($line, $this->screen_width());
                $lines[] = ($wrappedLines[\count($wrappedLines) - 1] === '') ? "{$line} " : $line;
            }
            $out = '';
            foreach ($lines as $l) {
                $out .= "{$l}\r\n";
            }
            $this->io->write($out);
        });
    }

    public function render(): void
    {
        [$wrappedCursorX, $wrappedCursorY] = $this->wrapped_cursor_position();
        $flat = [];
        foreach ($this->wrapped_prompt_and_input_lines() as $rows) {
            foreach ($rows as $row) {
                $flat[] = $row;
            }
        }
        $visible = \array_slice($flat, $this->screen_scroll_top(), $this->screen_height());
        $newLines = [];
        foreach ($visible as [$prompt, $line]) {
            $promptWidth = Unicode::calculate_width($prompt, true);
            $newLines[] = [
                [0, $promptWidth, $prompt],
                [$promptWidth, Unicode::calculate_width($line, true), $line],
            ];
        }

        // rprompt, menu, and dialog overlay rows are tier 2+ and never populated
        // here; their branches (upstream line_editor.rb:481-511) collapse to
        // no-ops with @rprompt/@menu_info nil and @dialogs empty, so the two-cell
        // rows above are the whole frame in tier 1.

        $this->io->buffered_output(function () use ($newLines, $wrappedCursorX, $wrappedCursorY): void {
            $this->render_differential($newLines, $wrappedCursorX, $wrappedCursorY - $this->screen_scroll_top());
        });
    }

    /**
     * @param list<list<array{0: int, 1: int, 2: string}|null>> $new_lines
     */
    private function render_differential(array $new_lines, int $new_cursor_x, int $new_cursor_y): void
    {
        $renderedLines = $this->rendered_screen['lines'];
        $cursorY = $this->rendered_screen['cursor_y'];
        if ($new_lines !== $renderedLines) {
            $this->io->hide_cursor();
            $numLines = \min(\max(\count($new_lines), \count($renderedLines)), $this->screen_height());
            if ($this->rendered_screen['base_y'] + $numLines > $this->screen_height()) {
                $this->io->scroll_down($numLines - $cursorY - 1);
                $this->rendered_screen['base_y'] = $this->screen_height() - $numLines;
                $cursorY = $numLines - 1;
            }
            for ($i = 0; $i < $numLines; $i++) {
                $renderedLine = $renderedLines[$i] ?? [];
                $lineToRender = $new_lines[$i] ?? [];
                if ($renderedLine === $lineToRender) {
                    continue;
                }
                $this->io->move_cursor_down($i - $cursorY);
                $cursorY = $i;
                if (!isset($renderedLines[$i])) {
                    $this->io->move_cursor_column(0);
                    $this->io->erase_after_cursor();
                }
                $this->render_line_differential($renderedLine, $lineToRender);
            }
            $this->rendered_screen['lines'] = $new_lines;
            $this->io->show_cursor();
        }
        $this->io->move_cursor_column($new_cursor_x);
        $newCursorY = \max(0, \min($new_cursor_y, $this->screen_height() - 1));
        $this->io->move_cursor_down($newCursorY - $cursorY);
        $this->rendered_screen['cursor_y'] = $newCursorY;
    }

    private function clear_rendered_screen_cache(): void
    {
        $this->rendered_screen['lines'] = [];
        $this->rendered_screen['cursor_y'] = 0;
    }

    public function rerender(): void
    {
        if (!$this->in_pasting) {
            $this->render();
        }
    }
}
