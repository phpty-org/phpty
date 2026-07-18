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

    // --- Completion (tier 4) ----------------------------------------------

    /**
     * The caller's completion function (Reline#completion_proc, reline.rb:132).
     * Given the word being completed (and optionally the pre/post text, by
     * arity), returns the candidate list. Pushed in by Core per readline, like
     * the other caller procs; never cleared by reset_variables.
     *
     * @var (callable): (array<int, ?string>|mixed)|null
     */
    private $completion_proc = null;

    /**
     * The character appended after a lone perfect completion (Reline#completion_
     * append_character, reline.rb:84). Core normalises it to a single char before
     * pushing it here; default '' inserts nothing. A persistent caller setting.
     */
    private string $completion_append_character = '';

    /**
     * Called with the perfectly-matched word when Tab is pressed again on a
     * perfect match (Reline#dig_perfect_match_proc, reline.rb:156). Lets IRB dig
     * into a matched constant/method; unset here disables that continuation.
     *
     * @var (callable(string): void)|null
     */
    private $dig_perfect_match_proc = null;

    /**
     * Quote / word-break character sets used to split the line into
     * preposing/target/postposing (retrieve_completion_block, line_editor.rb:1153).
     * Upstream reads these off the Reline module (`Reline.completer_quote_
     * characters`); the injected-not-global deviation pushes them onto the editor
     * from Core instead. Ruby treats them as Strings and tests membership with
     * String#include?, so they stay plain strings here (strpos membership).
     */
    private string $completer_quote_characters = '"\'';

    private string $completer_word_break_characters = " \t\n`><=;|&{(";

    /**
     * The active quote character while a completion proc runs (line_editor.rb:1089).
     * Upstream stashes it on Reline.core for the caller's proc to inspect; kept on
     * the editor here and exposed through Core.
     */
    private ?string $completion_quote_character = null;

    /** The completion FSM state (CompletionState::*), walked by perform_completion. */
    private string $completion_state = CompletionState::NORMAL;

    /** The cycling-completion cursor while autocompletion / menu-complete runs. */
    private ?CompletionJourneyState $completion_journey_state = null;

    /** The last word promoted to a perfect match, handed to dig_perfect_match_proc. */
    private ?string $perfect_matched = null;

    /** The completion menu (the flat all-candidates listing), rendered then cleared. */
    private ?MenuInfo $menu_info = null;

    // --- Dialogs (tier 4) -------------------------------------------------

    /** @var list<Dialog> the registered dialogs (autocomplete dropdown, IRB adds more) */
    private array $dialogs = [];

    /** Scrollbar glyphs, chosen in reset() (line_editor.rb:145-165); UTF-8 block chars. */
    private string $full_block = '█';

    private string $upper_half_block = '▀';

    private string $lower_half_block = '▄';

    private int $block_elem_width = 1;

    private const DIALOG_DEFAULT_HEIGHT = 20;

    private const MINIMUM_SCROLLBAR_HEIGHT = 1;

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

    /**
     * The block readmultiline hands over to decide when a multiline buffer is
     * complete (reline.rb:315). Receives the whole buffer joined with a trailing
     * newline; a truthy return finishes the read. Set per-readline by Core, never
     * reset in reset_variables — it belongs to the caller, not the buffer.
     *
     * @var (callable(string): bool)|null
     */
    private $confirm_multiline_termination_proc = null;

    /**
     * The dynamic-prompt proc (Reline#prompt_proc, reline.rb:323). Given the whole
     * buffer, returns one prompt string per line; nil falls back to the static
     * prompt on every row. Set per-readline by Core.
     *
     * @var (callable(list<string>): list<string>)|null
     */
    private $prompt_proc = null;

    /** The history store. Upstream reaches the global `Reline::HISTORY`; per the
     * injected-not-global deviation (CONTEXT.md) the store is owned by Core and
     * handed in here, and every `Reline::HISTORY` upstream is `$this->history`. */
    private History $history;

    /**
     * The index into the history store the buffer is currently browsing, or null
     * when editing a fresh line (line_editor.rb:229). Reset per readline.
     */
    private ?int $history_pointer = null;

    /**
     * The fresh (non-history) line stashed when the user first steps into history,
     * restored on stepping back past the newest entry (line_editor.rb:266,1612).
     */
    private ?string $line_backup_in_history = null;

    /**
     * The incremental-search prompt shown in place of @prompt while a C-r/C-s
     * search is running (line_editor.rb:240,112). Null when not searching.
     */
    private ?string $searching_prompt = null;

    /**
     * When set, the next key is handed to this proc instead of dispatched as a
     * command — the incremental-search reader installs it (line_editor.rb:233,1536).
     *
     * @var (callable(string, string|int|null): void)|null
     */
    private $waiting_proc = null;

    /**
     * The last committed incremental-search word, reused when C-r/C-s is pressed
     * with an empty search (line_editor.rb:1478). Owned by the Core/Reline module
     * upstream (`Reline.last_incremental_search`); kept on the reused editor here
     * so it survives across readline calls exactly as the module-level slot does,
     * and is deliberately not cleared by reset_variables.
     */
    private ?string $last_incremental_search = null;

    public function __construct(?Config $config, IO $io, ?History $history = null)
    {
        $this->config = $config;
        $this->io = $io;
        $this->kill_ring = new KillRing();
        $this->history = $history ?? new History($config ?? new Config());
        $this->reset_variables();
    }

    public function history(): History
    {
        return $this->history;
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
        // Scrollbar glyphs (line_editor.rb:145-165). The Windows branch is out of
        // scope; this port is UTF-8-only, so the block-drawing chars are always
        // available save for the explicit RELINE_ALT_SCROLLBAR ASCII opt-out.
        if (\getenv('RELINE_ALT_SCROLLBAR') !== false) {
            $this->full_block = '::';
            $this->upper_half_block = "''";
            $this->lower_half_block = '..';
            $this->block_elem_width = 2;
        } else {
            $this->full_block = '█';
            $this->upper_half_block = '▀';
            $this->lower_half_block = '▄';
            $this->block_elem_width = Unicode::calculate_width('█');
        }
    }

    public function reset_variables(string $prompt = ''): void
    {
        $this->prompt = \str_replace("\n", '\\n', $prompt);
        $this->is_multiline = false;
        $this->finished = false;
        $this->history_pointer = null;
        $this->waiting_proc = null;
        $this->searching_prompt = null;
        $this->just_cursor_moving = false;
        $this->eof = false;
        $this->continuous_insertion_buffer = '';
        $this->scroll_partial_screen = 0;
        $this->in_pasting = false;
        $this->interrupted = false;
        $this->resized = false;
        $this->completion_occurs = false;
        $this->completion_journey_state = null;
        $this->completion_state = CompletionState::NORMAL;
        $this->perfect_matched = null;
        $this->menu_info = null;
        $this->dialogs = [];
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
        $this->line_backup_in_history = null;
    }

    public function multiline_on(): void
    {
        $this->is_multiline = true;
    }

    public function multiline_off(): void
    {
        $this->is_multiline = false;
    }

    /** @param (callable(string): bool)|null $proc */
    public function set_confirm_multiline_termination_proc(?callable $proc): void
    {
        $this->confirm_multiline_termination_proc = $proc;
    }

    /** @param (callable(list<string>): list<string>)|null $proc */
    public function set_prompt_proc(?callable $proc): void
    {
        $this->prompt_proc = $proc;
    }

    /**
     * Caller completion settings, pushed by Core#inner_readline exactly as
     * upstream assigns line_editor.completion_proc etc. (reline.rb:320-325). They
     * persist across resets — they belong to the caller, not the buffer.
     *
     * @param (callable): mixed|null $proc
     */
    public function set_completion_proc(?callable $proc): void
    {
        $this->completion_proc = $proc;
    }

    /** @return (callable): mixed|null */
    public function completion_proc(): ?callable
    {
        return $this->completion_proc;
    }

    public function set_completion_append_character(string $value): void
    {
        $this->completion_append_character = $value;
    }

    /** @param (callable(string): void)|null $proc */
    public function set_dig_perfect_match_proc(?callable $proc): void
    {
        $this->dig_perfect_match_proc = $proc;
    }

    public function set_completer_quote_characters(string $value): void
    {
        $this->completer_quote_characters = $value;
    }

    public function set_completer_word_break_characters(string $value): void
    {
        $this->completer_word_break_characters = $value;
    }

    public function completion_quote_character(): ?string
    {
        return $this->completion_quote_character;
    }

    /** Test / dialog-scope accessors mirroring upstream instance_variable_get reads. */
    public function completion_state(): string
    {
        return $this->completion_state;
    }

    public function menu_info(): ?MenuInfo
    {
        return $this->menu_info;
    }

    /** @return list<Dialog> */
    public function dialogs(): array
    {
        return $this->dialogs;
    }

    public function just_cursor_moving(): bool
    {
        return $this->just_cursor_moving;
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

    public function line_index(): int
    {
        return $this->line_index;
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
            // @just_cursor_moving lets a dialog proc tell a pure cursor move from
            // an edit (the autocomplete dropdown ignores cursor-only moves).
            $this->just_cursor_moving = !$modified;
            $this->update_dialogs($key);
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
        // A dialog whose name matches the key's method-symbol has trapped the key
        // (line_editor.rb:1029); it was handled while the dialog updated, so skip
        // the editor dispatch. The default autocomplete dialog traps nothing.
        foreach ($this->dialogs as $dialog) {
            if ($dialog->name() === $key->method_symbol) {
                return null;
            }
        }

        $this->completion_occurs = false;

        $this->process_key($key->char, $key->method_symbol);

        $this->prev_action_state = $this->next_action_state;
        $this->next_action_state = [];

        // Leaving a completion clears its FSM / journey (line_editor.rb:1041).
        if (!$this->completion_occurs) {
            $this->completion_state = CompletionState::NORMAL;
            $this->completion_journey_state = null;
        }

        $modified = $oldBufferOfLines !== $this->buffer_of_lines;

        if (!$this->restoring) {
            $this->push_undo_redo($modified);
        }
        $this->restoring = false;

        if ($this->in_pasting) {
            $this->clear_dialogs();

            return null;
        }

        // Autocompletion starts a journey on every edit (line_editor.rb:1056), so
        // the dropdown follows what is typed without an explicit Tab.
        if (!$this->completion_occurs && $modified && $this->config !== null && !$this->config->disable_completion() && $this->config->autocompletion()) {
            $this->process_insert(true);
            $this->completion_journey_state = $this->retrieve_completion_journey_state();
        }

        return $modified;
    }

    /**
     * @param string|int|null $method_symbol
     */
    private function process_key(string $key, $method_symbol): void
    {
        // A waiting proc (incremental search) consumes multi-character keys as a
        // signal to stop waiting (line_editor.rb:998); the vi_waiting_operator
        // branch is tier 5 and absent.
        if ($this->waiting_proc !== null && \mb_strlen($key, 'UTF-8') !== 1) {
            $this->cleanup_waiting();
        }
        $this->process_insert($method_symbol !== 'ed_insert');
        $this->run_for_operators($key, $method_symbol);
    }

    private function cleanup_waiting(): void
    {
        // The vi_waiting_operator / drop_terminate_spaces state (line_editor.rb:986)
        // is tier 5; only the search-mode fields are live here.
        $this->waiting_proc = null;
        $this->searching_prompt = null;
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
        // While a search is running the key is fed to the waiting proc rather than
        // dispatched (line_editor.rb:964).
        if ($this->waiting_proc !== null) {
            ($this->waiting_proc)($key, $method_symbol);

            return;
        }
        // Unknown / unbound methods (vi commands absent until tier 5) no-op here,
        // mirroring upstream's `return unless respond_to?`. This is the
        // ed_unassigned-equivalent: dispatch simply does nothing.
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
            } elseif ($this->line_index === \count($this->buffer_of_lines) - 1 && $this->line_index > 0) {
                \array_pop($this->buffer_of_lines);
                $this->line_index -= 1;
                $this->byte_pointer = 0;
            } elseif ($this->line_index < \count($this->buffer_of_lines) - 1) {
                \array_splice($this->buffer_of_lines, $this->line_index, 1);
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
        if ($this->is_multiline) {
            $nextLine = \substr($this->current_line(), $this->byte_pointer);
            $cursorLine = \substr($this->current_line(), 0, $this->byte_pointer);
            $this->insert_new_line($cursorLine, $nextLine);
        }
    }

    /**
     * Split the current line at the cursor into two buffer lines, ported from
     * line_editor.rb:277. The auto_indent_proc branch is tier-4+ (no indent proc
     * in tier 2), so this reduces to the plain insert-and-advance.
     */
    private function insert_new_line(string $cursor_line, string $next_line): void
    {
        \array_splice($this->buffer_of_lines, $this->line_index + 1, 0, [$next_line]);
        $this->buffer_of_lines[$this->line_index] = $cursor_line;
        $this->line_index += 1;
        $this->byte_pointer = 0;
    }

    /**
     * Insert a (possibly multi-line) chunk of text at the cursor, splitting the
     * current line around each embedded newline. The bracketed-paste target
     * (Core maps :bracketed_paste_start to this), ported from line_editor.rb:1194.
     */
    public function insert_multiline_text(string $text): void
    {
        $pre = \substr($this->buffer_of_lines[$this->line_index], 0, $this->byte_pointer);
        $post = \substr($this->buffer_of_lines[$this->line_index], $this->byte_pointer);
        $normalised = \preg_replace('/\r\n?/', "\n", Unicode::safe_encode($text, $this->encoding()));
        $lines = \explode("\n", $pre . $normalised . $post);
        if ($lines === []) {
            $lines = [''];
        }
        \array_splice($this->buffer_of_lines, $this->line_index, 1, $lines);
        $this->line_index += \count($lines) - 1;
        $this->byte_pointer = \strlen($this->buffer_of_lines[$this->line_index]) - \strlen($post);
    }

    /** Ask the caller's block whether the multiline buffer is complete (line_editor.rb:1189). */
    public function confirm_multiline_termination(): bool
    {
        if ($this->confirm_multiline_termination_proc === null) {
            return false;
        }

        return (bool) ($this->confirm_multiline_termination_proc)(\implode("\n", $this->buffer_of_lines) . "\n");
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
        if ($this->is_multiline) {
            // The vi_command branch (cursor-down via ed_next_history) is tier 5;
            // emacs multiline accepts only at the last line, and only if the
            // caller's confirm proc says the buffer is complete.
            if ($this->line_index === \count($this->buffer_of_lines) - 1 && $this->confirm_multiline_termination()) {
                $this->finish();
            } else {
                $this->key_newline($key);
            }
        } else {
            $this->finish();
        }
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

    /**
     * Replace the whole buffer with a stored history entry, ported from
     * line_editor.rb:1607. The current buffer is first saved back — into
     * @line_backup_in_history when leaving the fresh line, or into the history
     * store when leaving an already-recalled entry — so an edited history line
     * keeps its edit until the buffer moves off it (the "leaves the original
     * intact until accept" behaviour). $line and $cursor accept 'start', 'end', or
     * an explicit index.
     *
     * @param 'start'|'end'|int $line
     * @param 'start'|'end'|int $cursor
     */
    private function move_history(?int $history_pointer, $line, $cursor): void
    {
        if ($history_pointer === null) {
            $history_pointer = $this->history->size();
        }
        if ($history_pointer < 0 || $history_pointer > $this->history->size()) {
            return;
        }
        $old_history_pointer = $this->history_pointer ?? $this->history->size();
        if ($old_history_pointer === $this->history->size()) {
            $this->line_backup_in_history = $this->whole_buffer();
        } else {
            $this->history[$old_history_pointer] = $this->whole_buffer();
        }
        if ($history_pointer === $this->history->size()) {
            $buf = $this->line_backup_in_history;
            $this->history_pointer = null;
            $this->line_backup_in_history = null;
        } else {
            $buf = $this->history[$history_pointer];
            $this->history_pointer = $history_pointer;
        }
        // Ruby String#split("\n") drops trailing empties and an empty string
        // becomes []; either way upstream falls back to a single empty line.
        $this->buffer_of_lines = ((string) $buf) === '' ? [''] : \explode("\n", (string) $buf);
        if ($this->buffer_of_lines === []) {
            $this->buffer_of_lines = [''];
        }
        $this->line_index = $line === 'start' ? 0 : ($line === 'end' ? \count($this->buffer_of_lines) - 1 : $line);
        $this->byte_pointer = $cursor === 'start' ? 0 : ($cursor === 'end' ? \strlen($this->current_line()) : $cursor);
    }

    /**
     * Up-arrow / C-p. Ported from line_editor.rb:1629. The leading branch moves the
     * cursor up one buffer line, keeping its display column (the multiline vertical
     * motion landed at tier 2); at the top line it steps back through the history
     * store via move_history.
     */
    private function ed_prev_history(string $key, int $arg = 1): void
    {
        if ($this->line_index > 0) {
            $cursor = $this->current_byte_pointer_cursor();
            $this->line_index -= 1;
            $this->calculate_nearest_cursor($cursor);

            return;
        }
        $this->move_history(
            ($this->history_pointer ?? $this->history->size()) - 1,
            'end',
            $this->config !== null && $this->config->editing_mode_is('vi_command') ? 'start' : 'end',
        );
        $arg -= 1;
        if ($arg > 0) {
            $this->ed_prev_history($key, $arg);
        }
    }

    private function previous_history(string $key, int $arg = 1): void
    {
        $this->ed_prev_history($key, $arg);
    }

    /** Down-arrow / C-n. The mirror of ed_prev_history (line_editor.rb:1646). */
    private function ed_next_history(string $key, int $arg = 1): void
    {
        if ($this->line_index < \count($this->buffer_of_lines) - 1) {
            $cursor = $this->current_byte_pointer_cursor();
            $this->line_index += 1;
            $this->calculate_nearest_cursor($cursor);

            return;
        }
        $this->move_history(
            ($this->history_pointer ?? $this->history->size()) + 1,
            'start',
            $this->config !== null && $this->config->editing_mode_is('vi_command') ? 'start' : 'end',
        );
        $arg -= 1;
        if ($arg > 0) {
            $this->ed_next_history($key, $arg);
        }
    }

    private function next_history(string $key, int $arg = 1): void
    {
        $this->ed_next_history($key, $arg);
    }

    /** M-<: jump to the oldest history entry (line_editor.rb:1663). */
    private function ed_beginning_of_history(string $key): void
    {
        $this->move_history(0, 'end', 'end');
    }

    private function beginning_of_history(string $key): void
    {
        $this->ed_beginning_of_history($key);
    }

    /** M->: jump back to the fresh line past the newest entry (line_editor.rb:1668). */
    private function ed_end_of_history(string $key): void
    {
        $this->move_history($this->history->size(), 'end', 'end');
    }

    private function end_of_history(string $key): void
    {
        $this->ed_end_of_history($key);
    }

    // --- Incremental search (C-r / C-s, line_editor.rb:1451-1565) -----------

    /**
     * Build the stateful searcher a C-r/C-s session drives one key at a time,
     * ported from line_editor.rb:1451. Returns [search_word, prompt_name,
     * hit_pointer]; the closure carries the accumulated word, the running
     * direction (which C-r/C-s can flip), and the last hit across calls.
     *
     * @return callable(string, string|int|null): array{0: string, 1: string, 2: int|null}
     */
    private function generate_searcher(string $direction): callable
    {
        $search_word = '';
        $hit_pointer = null;

        return function (string $key, $key_symbol) use (&$search_word, &$hit_pointer, &$direction): array {
            $search_again = false;
            switch ($key_symbol) {
                case 'em_delete_prev_char':
                case 'backward_delete_char':
                    $gcs = $this->grapheme_clusters($search_word);
                    if (\count($gcs) > 0) {
                        \array_pop($gcs);
                        $search_word = \implode('', $gcs);
                    }
                    break;
                case 'reverse_search_history':
                case 'vi_search_prev':
                    $search_again = $direction === 'reverse';
                    $direction = 'reverse';
                    break;
                case 'forward_search_history':
                case 'vi_search_next':
                    $search_again = $direction === 'forward';
                    $direction = 'forward';
                    break;
                default:
                    $search_word .= $key;
            }
            $hit = null;
            if ($search_word !== '' && $this->line_backup_in_history !== null && \strpos($this->line_backup_in_history, $search_word) !== false) {
                $hit_pointer = $this->history->size();
                $hit = $this->line_backup_in_history;
            } else {
                if ($search_again) {
                    if ($search_word === '' && $this->last_incremental_search !== null) {
                        $search_word = $this->last_incremental_search;
                    }
                    if ($this->history_pointer !== null) {
                        if ($direction === 'reverse') {
                            $history_pointer_base = 0;
                            $history = $this->history_slice(0, $this->history_pointer - 1);
                        } else {
                            $history_pointer_base = $this->history_pointer + 1;
                            $history = $this->history_slice($this->history_pointer + 1, -1);
                        }
                    } else {
                        $history_pointer_base = 0;
                        $history = $this->history->to_a();
                    }
                } elseif ($this->history_pointer !== null) {
                    if ($direction === 'reverse') {
                        $history_pointer_base = 0;
                        $history = $this->history_slice(0, $this->history_pointer);
                    } else {
                        $history_pointer_base = $this->history_pointer;
                        $history = $this->history_slice($this->history_pointer, -1);
                    }
                } else {
                    $history_pointer_base = 0;
                    $history = $this->history->to_a();
                }
                $hit_index = null;
                if ($direction === 'reverse') {
                    for ($i = \count($history) - 1; $i >= 0; $i--) {
                        if (\strpos($history[$i], $search_word) !== false) {
                            $hit_index = $i;
                            break;
                        }
                    }
                } else {
                    foreach ($history as $i => $item) {
                        if (\strpos($item, $search_word) !== false) {
                            $hit_index = $i;
                            break;
                        }
                    }
                }
                if ($hit_index !== null) {
                    $hit_pointer = $history_pointer_base + $hit_index;
                    $hit = $this->history[$hit_pointer];
                }
            }
            $prompt_name = $direction === 'forward' ? 'i-search' : 'reverse-i-search';
            if ($hit === null) {
                $prompt_name = "failed {$prompt_name}";
            }

            return [$search_word, $prompt_name, $hit_pointer];
        };
    }

    /**
     * Ruby Array#[a..b] inclusive-range slice with negative-index wrap, so the
     * `HISTORY[0..(@history_pointer - 1)]` etc. slices in generate_searcher keep
     * their exact upstream semantics (notably 0..-1 meaning "whole array").
     *
     * @return list<string>
     */
    private function history_slice(int $start, int $end_inclusive): array
    {
        $all = $this->history->to_a();
        $n = \count($all);
        $s = $start < 0 ? $start + $n : $start;
        $e = $end_inclusive < 0 ? $end_inclusive + $n : $end_inclusive;
        if ($s < 0 || $s > $n) {
            return [];
        }
        if ($e >= $n) {
            $e = $n - 1;
        }
        if ($e < $s) {
            return [];
        }

        return \array_values(\array_slice($all, $s, $e - $s + 1));
    }

    /**
     * Enter incremental-search mode, ported from line_editor.rb:1528. Installs the
     * waiting proc that reads each subsequent key: printable keys (and the
     * search/delete command keys) extend the search and jump the buffer to the
     * hit; C-g cancels and restores; a termination key commits the current hit.
     */
    private function incremental_search_history(string $direction): void
    {
        $backup = [$this->buffer_of_lines, $this->line_index, $this->byte_pointer, $this->history_pointer, $this->line_backup_in_history];
        $searcher = $this->generate_searcher($direction);
        $prompt_name = $direction === 'forward' ? 'i-search' : 'reverse-i-search';
        $this->searching_prompt = "({$prompt_name})`': ";
        $termination_keys = ["\x0a"]; // C-j
        if ($this->config !== null && $this->config->isearch_terminators() !== null) {
            $termination_keys = \array_merge($termination_keys, \mb_str_split($this->config->isearch_terminators()));
        }
        $accept_key_syms = ['em_delete_prev_char', 'backward_delete_char', 'vi_search_prev', 'vi_search_next', 'reverse_search_history', 'forward_search_history'];
        $this->waiting_proc = function (string $k, $key_symbol) use ($searcher, $termination_keys, $accept_key_syms, $backup): void {
            if ($k === "\x07") { // C-g: cancel search and restore buffer
                [$this->buffer_of_lines, $this->line_index, $this->byte_pointer, $this->history_pointer, $this->line_backup_in_history] = $backup;
                $this->searching_prompt = null;
                $this->waiting_proc = null;
            } elseif (!\in_array($k, $termination_keys, true) && ($this->is_printable($k) || \in_array($key_symbol, $accept_key_syms, true))) {
                [$search_word, $prompt_name, $hit_pointer] = $searcher($k, $key_symbol);
                $this->last_incremental_search = $search_word;
                $this->searching_prompt = \sprintf("(%s)`%s'", $prompt_name, $search_word);
                if (!$this->is_multiline) {
                    $this->searching_prompt .= ': ';
                }
                if ($hit_pointer !== null) {
                    $this->move_history($hit_pointer, 'end', 'end');
                }
            } else {
                // Termination keys and other keys commit the current hit.
                $this->move_history($this->history_pointer, 'end', 'start');
                $this->searching_prompt = null;
                $this->waiting_proc = null;
            }
        };
    }

    private function is_printable(string $k): bool
    {
        return \preg_match('/[[:print:]]/', $k) === 1;
    }

    private function vi_search_prev(string $key): void
    {
        $this->incremental_search_history('reverse');
    }

    private function reverse_search_history(string $key): void
    {
        $this->vi_search_prev($key);
    }

    private function vi_search_next(string $key): void
    {
        $this->incremental_search_history('forward');
    }

    private function forward_search_history(string $key): void
    {
        $this->vi_search_next($key);
    }

    // --- Completion (line_editor.rb:1088-1352) -----------------------------

    /**
     * Split the current line at the cursor into preposing / target / postposing,
     * honouring quote and word-break characters, ported from line_editor.rb:1153.
     * Returns the closing quote too, when the cursor sits at end of line inside an
     * open quote. Public so the dialog scope's retrieve_completion_block can reach
     * it (the injected analogue of upstream's DialogProcScope delegation).
     *
     * @return array{0: string, 1: string, 2: string, 3: string|null}
     */
    public function retrieve_completion_block(): array
    {
        $quote_characters = $this->completer_quote_characters;
        $before = $this->grapheme_clusters(\substr($this->current_line(), 0, $this->byte_pointer));
        $quote = null;
        if (\strlen($this->current_line()) === $this->byte_pointer && $quote_characters !== '') {
            $escaped = false;
            foreach ($before as $c) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($c === '\\') {
                    $escaped = true;
                } elseif ($quote !== null) {
                    if ($c === $quote) {
                        $quote = null;
                    }
                } elseif (\strpos($quote_characters, $c) !== false) {
                    $quote = $c;
                }
            }
        }

        $word_break_characters = $quote_characters . $this->completer_word_break_characters;
        $break_index = -1;
        for ($i = \count($before) - 1; $i >= 0; $i--) {
            $c = $before[$i];
            if (\strpos($word_break_characters, $c) !== false || \strpos($quote_characters, $c) !== false) {
                $break_index = $i;
                break;
            }
        }
        $preposing = \implode('', \array_slice($before, 0, $break_index + 1));
        $target = \implode('', \array_slice($before, $break_index + 1));
        $postposing = \substr($this->current_line(), $this->byte_pointer);
        $lines = $this->whole_lines();
        if ($this->line_index > 0) {
            $preposing = \implode("\n", \array_slice($lines, 0, $this->line_index)) . "\n" . $preposing;
        }
        if ((\count($lines) - 1) > $this->line_index) {
            $postposing = $postposing . "\n" . \implode("\n", \array_slice($lines, $this->line_index + 1));
        }

        return [$preposing, $target, $postposing, $quote];
    }

    /**
     * Call the completion proc with the quote character exposed, ported from
     * line_editor.rb:1088.
     *
     * @return list<int, ?string>|null
     */
    public function call_completion_proc(string $pre, string $target, string $post, ?string $quote): ?array
    {
        $this->completion_quote_character = $quote;
        $result = $this->call_completion_proc_with_checking_args($pre, $target, $post);
        $this->completion_quote_character = null;

        return $result;
    }

    /**
     * Invoke the completion proc with the argument count it declares (1, 2, or 3+),
     * ported from line_editor.rb:1095. Ruby inspects `@completion_proc.parameters`;
     * PHP reflects the callable's parameter list, treating a variadic as 3+.
     *
     * @return list<int, ?string>|null
     */
    public function call_completion_proc_with_checking_args(string $pre, string $target, string $post): ?array
    {
        $result = null;
        if ($this->completion_proc !== null) {
            $ref = new \ReflectionFunction(\Closure::fromCallable($this->completion_proc));
            $argnum = 0;
            foreach ($ref->getParameters() as $param) {
                if ($param->isVariadic()) {
                    $argnum = 3;
                    break;
                }
                $argnum++;
            }
            if ($argnum === 1) {
                $result = ($this->completion_proc)($target);
            } elseif ($argnum === 2) {
                $result = ($this->completion_proc)($target, $pre);
            } elseif ($argnum >= 3) {
                $result = ($this->completion_proc)($target, $pre, $post);
            }
        }

        return \is_array($result) ? \array_values($result) : null;
    }

    /**
     * Keep candidates that start with the target, normalise and de-dup them,
     * ported from line_editor.rb:816. Nulls in the list are skipped (a proc may
     * pad the list with nil); case-folding follows completion_ignore_case.
     *
     * @param list<int, ?string> $list
     * @return list<string>
     */
    private function filter_normalize_candidates(string $target, array $list): array
    {
        $ignore = $this->config !== null && $this->config->completion_ignore_case();
        $needle = $ignore ? \mb_strtolower($target, 'UTF-8') : $target;
        $selected = [];
        foreach ($list as $item) {
            if ($item === null) {
                continue;
            }
            $haystack = $ignore ? \mb_strtolower($item, 'UTF-8') : $item;
            if (\strncmp($haystack, $needle, \strlen($needle)) === 0) {
                $selected[] = $item;
            }
        }
        $normalized = [];
        foreach ($selected as $item) {
            if (\class_exists('\\Normalizer')) {
                $n = \Normalizer::normalize($item, \Normalizer::FORM_C);
                $normalized[] = $n === false ? $item : $n;
            } else {
                $normalized[] = $item;
            }
        }

        return \array_values(\array_unique($normalized));
    }

    /**
     * Insert the common prefix of the candidates and advance the completion FSM,
     * ported from line_editor.rb:839. First Tab inserts the shared prefix; a second
     * lists the candidates (MENU); a lone perfect match appends the append char.
     *
     * @param list<int, ?string> $list
     */
    private function perform_completion(string $preposing, string $target, string $postposing, ?string $quote, array $list): void
    {
        $candidates = $this->filter_normalize_candidates($target, $list);

        switch ($this->completion_state) {
            case CompletionState::PERFECT_MATCH:
                if ($this->dig_perfect_match_proc !== null) {
                    ($this->dig_perfect_match_proc)($this->perfect_matched);

                    return;
                }
                break;
            case CompletionState::MENU:
                $this->menu($candidates);

                return;
            case CompletionState::MENU_WITH_PERFECT_MATCH:
                $this->menu($candidates);
                $this->completion_state = CompletionState::PERFECT_MATCH;

                return;
        }

        $completed = Unicode::common_prefix($candidates, $this->config !== null && $this->config->completion_ignore_case());
        if ($completed === '') {
            return;
        }

        $append_character = '';
        if (\in_array($completed, $candidates, true)) {
            if (\count($candidates) === 1) {
                $append_character = $quote ?? $this->completion_append_character;
                $this->completion_state = CompletionState::PERFECT_MATCH;
            } elseif ($this->config !== null && $this->config->show_all_if_ambiguous()) {
                $this->menu($candidates);
                $this->completion_state = CompletionState::PERFECT_MATCH;
            } else {
                $this->completion_state = CompletionState::MENU_WITH_PERFECT_MATCH;
            }
            $this->perfect_matched = $completed;
        } else {
            $this->completion_state = CompletionState::MENU;
            if ($this->config !== null && $this->config->show_all_if_ambiguous()) {
                $this->menu($candidates);
            }
        }
        $whole = \explode("\n", $preposing . $completed . $append_character . $postposing);
        $this->buffer_of_lines[$this->line_index] = $whole[$this->line_index] ?? '';
        $toPointer = \explode("\n", $preposing . $completed . $append_character);
        $line_to_pointer = $toPointer[$this->line_index] ?? '';
        $this->byte_pointer = \strlen($line_to_pointer);
    }

    /**
     * Build the view of the current journey a dialog proc reads, ported from
     * line_editor.rb:881. Public so the DialogProcScope can reach it.
     */
    public function dialog_proc_scope_completion_journey_data(): ?CompletionJourneyData
    {
        if ($this->completion_journey_state === null) {
            return null;
        }
        $line_index = $this->completion_journey_state->line_index;
        $pre_lines = '';
        for ($i = 0; $i < $line_index; $i++) {
            $pre_lines .= $this->buffer_of_lines[$i] . "\n";
        }
        $post_lines = '';
        for ($i = $line_index + 1, $n = \count($this->buffer_of_lines); $i < $n; $i++) {
            $post_lines .= $this->buffer_of_lines[$i] . "\n";
        }

        return new CompletionJourneyData(
            $pre_lines . $this->completion_journey_state->pre,
            $this->completion_journey_state->post . $post_lines,
            $this->completion_journey_state->list,
            $this->completion_journey_state->pointer,
        );
    }

    /** Move the journey pointer and write the completed word, ported from line_editor.rb:894. */
    private function move_completed_list(string $direction): bool
    {
        if ($this->completion_journey_state === null) {
            $this->completion_journey_state = $this->retrieve_completion_journey_state();
        }
        if ($this->completion_journey_state === null) {
            return false;
        }

        $delta = ['up' => -1, 'down' => 1][$direction] ?? null;
        if ($delta !== null) {
            $size = \count($this->completion_journey_state->list);
            // Ruby % is always non-negative for a positive modulus; PHP % is not.
            $this->completion_journey_state->pointer = ((($this->completion_journey_state->pointer + $delta) % $size) + $size) % $size;
        }
        $state = $this->completion_journey_state;
        $completed = $state->list[$state->pointer];
        $this->set_current_line($state->pre . $completed . $state->post, \strlen($state->pre) + \strlen($completed));

        return true;
    }

    /** Set up a fresh journey from the completion proc's result, ported from line_editor.rb:906. */
    private function retrieve_completion_journey_state(): ?CompletionJourneyState
    {
        [$preposing, $target, $postposing, $quote] = $this->retrieve_completion_block();
        $list = $this->call_completion_proc($preposing, $target, $postposing, $quote);
        if (!\is_array($list)) {
            return null;
        }

        $candidates = [];
        foreach ($list as $item) {
            if ($item !== null && \strncmp($item, $target, \strlen($target)) === 0) {
                $candidates[] = $item;
            }
        }
        if ($candidates === []) {
            return null;
        }

        $preLines = \explode("\n", $preposing);
        $pre = $preLines[\count($preLines) - 1];
        $postLines = \explode("\n", $postposing);
        $post = $postLines[0];

        return new CompletionJourneyState(
            $this->line_index,
            $pre,
            $target,
            $post,
            \array_merge([$target], $candidates),
            0,
        );
    }

    private function menu(array $list): void
    {
        $this->menu_info = new MenuInfo($list);
    }

    /**
     * Tab (^I). Ported from line_editor.rb:1306. With autocompletion on it cycles
     * the journey down; otherwise it runs the completion proc and inserts the
     * common prefix / lists candidates via perform_completion.
     */
    private function complete(string $key): void
    {
        if ($this->config !== null && $this->config->disable_completion()) {
            return;
        }

        $this->process_insert(true);
        if ($this->config !== null && $this->config->autocompletion()) {
            $this->completion_state = CompletionState::NORMAL;
            $this->completion_occurs = $this->move_completed_list('down');
        } else {
            $this->completion_journey_state = null;
            [$pre, $target, $post, $quote] = $this->retrieve_completion_block();
            $result = $this->call_completion_proc($pre, $target, $post, $quote);
            if (\is_array($result)) {
                $this->completion_occurs = true;
                $this->perform_completion($pre, $target, $post, $quote, $result);
            }
        }
    }

    private function completion_journey_move(string $direction): void
    {
        if ($this->config !== null && $this->config->disable_completion()) {
            return;
        }
        $this->process_insert(true);
        $this->completion_state = CompletionState::NORMAL;
        $this->completion_occurs = $this->move_completed_list($direction);
    }

    private function menu_complete(string $key): void
    {
        $this->completion_journey_move('down');
    }

    private function menu_complete_backward(string $key): void
    {
        $this->completion_journey_move('up');
    }

    private function completion_journey_up(string $key): void
    {
        if ($this->config !== null && $this->config->autocompletion()) {
            $this->completion_journey_move('up');
        }
    }

    // --- Dialogs (line_editor.rb:446-798) ----------------------------------

    public function upper_space_height(int $wrapped_cursor_y): int
    {
        return $wrapped_cursor_y - $this->screen_scroll_top();
    }

    public function rest_height(int $wrapped_cursor_y): int
    {
        return $this->screen_height() - $wrapped_cursor_y + $this->screen_scroll_top() - $this->rendered_screen['base_y'] - 1;
    }

    public function clear_dialogs(): void
    {
        foreach ($this->dialogs as $dialog) {
            $dialog->setContents(null);
            $dialog->trap_key = null;
        }
    }

    /**
     * Register (or replace) a dialog by name, ported from line_editor.rb:699. Core
     * calls this once per readline from its @dialog_proc_list, after reset().
     *
     * @param callable(DialogProcScope): ?DialogRenderInfo $p
     * @param list<mixed>|null                             $context
     */
    public function add_dialog_proc(string $name, callable $p, ?array $context = null): void
    {
        $config = $this->config ?? new Config();
        $dialog = new Dialog($name, $config, new DialogProcScope($this, $config, $p, $context));
        foreach ($this->dialogs as $i => $d) {
            if ($d->name() === $name) {
                $this->dialogs[$i] = $dialog;

                return;
            }
        }
        $this->dialogs[] = $dialog;
    }

    /** Re-run every dialog proc against the current cursor, ported from line_editor.rb:453. */
    public function update_dialogs(?Key $key = null): void
    {
        [$wrappedCursorX, $wrappedCursorY] = $this->wrapped_cursor_position();
        foreach ($this->dialogs as $dialog) {
            $dialog->trap_key = null;
            $this->update_each_dialog($dialog, $wrappedCursorX, $wrappedCursorY - $this->screen_scroll_top(), $key);
        }
    }

    /**
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}} [[xBegin,xEnd),[yBegin,yEnd)]
     */
    private function dialog_range(Dialog $dialog, int $dialog_y): array
    {
        $xBegin = $dialog->column;
        $xEnd = $dialog->column + (int) $dialog->width();
        $yBegin = $dialog_y + $dialog->vertical_offset;
        $yEnd = $yBegin + \count($dialog->contents() ?? []);

        return [[$xBegin, $xEnd], [$yBegin, $yEnd]];
    }

    /**
     * Run one dialog proc and turn its DialogRenderInfo into positioned, coloured,
     * scroll-clipped overlay rows, ported verbatim from line_editor.rb:716-798.
     * This is where the dialog is placed above or below the cursor per the space
     * available (rest_height) and the scrollbar column is drawn.
     */
    private function update_each_dialog(Dialog $dialog, int $cursor_column, int $cursor_row, ?Key $key = null): void
    {
        $dialog->set_cursor_pos($cursor_column, $cursor_row);
        $dialog_render_info = $dialog->call($key);
        if ($dialog_render_info === null || $dialog_render_info->contents === null || $dialog_render_info->contents === []) {
            $dialog->setContents(null);
            $dialog->trap_key = null;

            return;
        }
        $contents = $dialog_render_info->contents;
        $pointer = $dialog->pointer;
        if ($dialog_render_info->width !== null) {
            $dialog->setWidth($dialog_render_info->width);
        } else {
            $widths = \array_map(fn (string $l): int => $this->calculate_width($l, true), $contents);
            $dialog->setWidth($widths === [] ? 0 : \max($widths));
        }
        $height = $dialog_render_info->height ?? self::DIALOG_DEFAULT_HEIGHT;
        if (\count($contents) < $height) {
            $height = \count($contents);
        }
        if (\count($contents) > $height) {
            if ($dialog->pointer !== null) {
                if ($dialog->pointer < 0) {
                    $dialog->scroll_top = 0;
                } elseif (($dialog->pointer - $dialog->scroll_top) >= ($height - 1)) {
                    $dialog->scroll_top = $dialog->pointer - ($height - 1);
                } elseif (($dialog->pointer - $dialog->scroll_top) < 0) {
                    $dialog->scroll_top = $dialog->pointer;
                }
                $pointer = $dialog->pointer - $dialog->scroll_top;
            } else {
                $dialog->scroll_top = 0;
            }
            $contents = \array_slice($contents, $dialog->scroll_top, $height);
        }
        $scrollbar_pos = null;
        $bar_height = 0;
        if ($dialog_render_info->scrollbar && \count($dialog_render_info->contents) > $height) {
            $bar_max_height = $height * 2;
            $moving_distance = (\count($dialog_render_info->contents) - $height) * 2;
            $position_ratio = $dialog->scroll_top === 0 ? 0.0 : (($dialog->scroll_top * 2) / $moving_distance);
            $bar_height = (int) \floor($bar_max_height * ((\count($contents) * 2) / (\count($dialog_render_info->contents) * 2)));
            if ($bar_height < self::MINIMUM_SCROLLBAR_HEIGHT) {
                $bar_height = self::MINIMUM_SCROLLBAR_HEIGHT;
            }
            $scrollbar_pos = (int) \floor(($bar_max_height - $bar_height) * $position_ratio);
        }
        $dialog->column = $dialog_render_info->pos->x;
        if ($scrollbar_pos !== null) {
            $dialog->setWidth($dialog->width() + $this->block_elem_width);
        }
        $diff = ($dialog->column + $dialog->width()) - $this->screen_width();
        if ($diff > 0) {
            $dialog->column -= $diff;
        }
        if ($this->rest_height($this->screen_scroll_top() + $cursor_row) - $dialog_render_info->pos->y >= $height) {
            $dialog->vertical_offset = $dialog_render_info->pos->y + 1;
        } elseif ($cursor_row >= $height) {
            $dialog->vertical_offset = $dialog_render_info->pos->y - $height;
        } else {
            $dialog->vertical_offset = $dialog_render_info->pos->y + 1;
        }
        if ($dialog->column < 0) {
            $dialog->column = 0;
            $dialog->setWidth($this->screen_width());
        }
        $face = Face::get($dialog_render_info->face);
        $scrollbar_sgr = $face['scrollbar'];
        $default_sgr = $face['default'];
        $enhanced_sgr = $face['enhanced'];
        $rendered = [];
        foreach ($contents as $i => $item) {
            $line_sgr = $i === $pointer ? $enhanced_sgr : $default_sgr;
            $str_width = $dialog->width() - ($scrollbar_pos === null ? 0 : $this->block_elem_width);
            [$str] = Unicode::take_mbchar_range($item, 0, $str_width, false, false, true);
            $colored_content = $line_sgr . $str;
            if ($scrollbar_pos !== null) {
                if ($scrollbar_pos <= ($i * 2) && ($i * 2 + 1) < ($scrollbar_pos + $bar_height)) {
                    $colored_content .= $scrollbar_sgr . $this->full_block;
                } elseif ($scrollbar_pos <= ($i * 2) && ($i * 2) < ($scrollbar_pos + $bar_height)) {
                    $colored_content .= $scrollbar_sgr . $this->upper_half_block;
                } elseif ($scrollbar_pos <= ($i * 2 + 1) && ($i * 2) < ($scrollbar_pos + $bar_height)) {
                    $colored_content .= $scrollbar_sgr . $this->lower_half_block;
                } else {
                    $colored_content .= $scrollbar_sgr . \str_repeat(' ', $this->block_elem_width);
                }
            }
            $rendered[] = $colored_content;
        }
        $dialog->setContents($rendered);
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
        // @vi_arg (vi, tier 5) would also override the prompt here; the
        // @searching_prompt override is live for incremental search.
        $prompt = $this->searching_prompt ?? $this->prompt;
        if (!$this->is_multiline) {
            // Single-line: one prompt, then blanks for any extra lines.
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
        if ($this->prompt_proc !== null) {
            $prompt_list = \array_map(
                static fn (string $pr): string => \str_replace("\n", '\\n', $pr),
                ($this->prompt_proc)($buffer),
            );
            // @vi_arg (tier 5) or an active search collapses the per-line prompts
            // to the single override prompt (line_editor.rb:123).
            if ($this->searching_prompt !== null) {
                $prompt_list = \array_map(static fn (string $pr): string => $prompt, $prompt_list);
            }
            if ($prompt_list === []) {
                $prompt_list = [$prompt];
            }
            if ($mode_string !== null) {
                $prompt_list = \array_map(static fn (string $pr): string => $mode_string . $pr, $prompt_list);
            }
            if (\count($buffer) > \count($prompt_list)) {
                $last = $prompt_list[\count($prompt_list) - 1];
                for ($i = 0, $n = \count($buffer) - \count($prompt_list); $i < $n; $i++) {
                    $prompt_list[] = $last;
                }
            }

            return $prompt_list;
        }
        if ($mode_string !== null) {
            $prompt = $mode_string . $prompt;
        }

        return \array_fill(0, \count($buffer), $prompt);
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
        return $this->with_cache('prompt_list', [$this->whole_lines(), $this->check_mode_string(), null, $this->searching_prompt], function (array $deps) {
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

        // rprompt (overlay index 2, line_editor.rb:481-491) is deferred past tier
        // 4; its slot stays empty and dialogs still key off index+3 below.

        // The completion menu (line_editor.rb:493-498): each laid-out row becomes a
        // single-cell overlay line appended after the input, then cleared.
        if ($this->menu_info !== null) {
            foreach ($this->menu_info->lines($this->screen_width()) as $item) {
                $newLines[] = [[0, Unicode::calculate_width($item), $item]];
            }
            $this->menu_info = null;
        }

        // Dialog overlays (line_editor.rb:500-511): each visible dialog paints its
        // rows into overlay level index+3 (0=prompt, 1=line, 2=rprompt). These are
        // the overlay levels the ADR-0017 renderer already proved with the upstream
        // RenderLineDifferentialTest dialog cases; tier 4 finally drives them.
        foreach ($this->dialogs as $index => $dialog) {
            if ($dialog->contents() === null) {
                continue;
            }
            [$xRange, $yRange] = $this->dialog_range($dialog, $wrappedCursorY - $this->screen_scroll_top());
            $contents = $dialog->contents();
            for ($row = $yRange[0]; $row < $yRange[1]; $row++) {
                if ($row < 0 || $row >= $this->screen_height()) {
                    continue;
                }
                if (!isset($newLines[$row])) {
                    $newLines[$row] = [];
                }
                $newLines[$row][$index + 3] = [$xRange[0], (int) $dialog->width(), $contents[$row - $yRange[0]]];
            }
        }
        // Ruby's `new_lines[row] ||=` auto-extends the array with nils; PHP leaves
        // gaps, so backfill any skipped rows to a contiguous 0-indexed list before
        // the differ walks it (render_differential indexes rows 0..num_lines-1).
        if ($newLines !== []) {
            $maxRow = \max(\array_keys($newLines));
            for ($k = 0; $k <= $maxRow; $k++) {
                if (!\array_key_exists($k, $newLines)) {
                    $newLines[$k] = [];
                }
            }
            \ksort($newLines);
        }

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
