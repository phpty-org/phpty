<?php

declare(strict_types=1);

namespace PhPty\Reline;

/**
 * The readline driver, ported from the Reline::Core part of lib/reline.rb — the
 * tier-1 subset.
 *
 * Owns one Config, KeyStroke, LineEditor, and IO gate, and drives the read loop:
 * read_io does the keyseq-timeout matching (reline.rb:378-406), inner_readline
 * runs the render/update loop (reline.rb:293-370), and may_req_ambiguous_char_
 * width probes the terminal once to fix Unicode's ambiguous width
 * (reline.rb:408-427). History, completion, multiline, and rprompt wiring are
 * absent (tiers 2-5); what remains is the minimum to echo, edit, and accept one
 * line.
 *
 * Where upstream reaches the singleton IO gate through the global
 * `Reline::IOGate`, this Core holds the gate as a field and injects it into the
 * LineEditor and KeyStroke — the same seam upstream's own tests exploit.
 */
final class Core
{
    private Config $config;

    private KeyStroke $key_stroke;

    private LineEditor $line_editor;

    private History $history;

    private IO $io;

    /** @var resource */
    private $output;

    // --- Completion settings (reline.rb:41-159, 501-506) -------------------
    // The canonical completion configuration lives on Core (mirroring upstream's
    // Reline::Core), and inner_readline pushes the per-readline slice onto the
    // LineEditor — the injected-not-global analogue of upstream reaching these
    // back through the Reline module from retrieve_completion_block.

    /** @var (callable): mixed|null */
    private $completion_proc = null;

    private string $completion_append_character = '';

    /** @var (callable(string): void)|null */
    private $dig_perfect_match_proc = null;

    private string $basic_word_break_characters = " \t\n`><=;|&{(";

    private string $completer_word_break_characters = " \t\n`><=;|&{(";

    private string $basic_quote_characters = '"\'';

    private string $completer_quote_characters = '"\'';

    private string $filename_quote_characters = '';

    private string $special_prefixes = '';

    /** @var array<string, array{proc: callable, context: list<mixed>|null}> registered dialog procs */
    private array $dialog_proc_list = [];

    /**
     * @param resource|null $output the stream the ambiguous-width probe glyph is
     *                              written to; defaults to STDOUT
     */
    public function __construct(?IO $io = null, ?Config $config = null, $output = null)
    {
        $this->config = $config ?? new Config();
        $this->io = $io ?? IO::decide_io_gate();
        $this->output = $output ?? \STDOUT;
        $this->key_stroke = new KeyStroke($this->config, $this->io->encoding());
        // Upstream builds `Reline::HISTORY = Reline::History.new(Reline.core.config)`
        // as a module constant (reline.rb:528); the injected-not-global deviation
        // (CONTEXT.md) makes Core the owner and hands the store to the editor.
        $this->history = new History($this->config);
        $this->line_editor = new LineEditor($this->config, $this->io, $this->history);
        if ($this->io instanceof IO\Ansi) {
            $this->io->setConfig($this->config);
        }
        // Wire deferred-signal servicing: the read loop calls this between poll
        // slices (ansi.rb:126 / dumb.rb:59).
        $this->io->setSignalServicer(function (): void {
            $this->line_editor->handle_signal();
        });
        // Register the built-in autocomplete dropdown, as the Reline.core builder
        // does (reline.rb:507). IRB adds more via add_dialog_proc.
        $this->add_dialog_proc('autocomplete', self::default_dialog_proc_autocomplete(), []);
    }

    // --- Completion accessors (Reline module delegates to these) -----------

    /** @param (callable): mixed|null $proc */
    public function set_completion_proc(?callable $proc): void
    {
        $this->completion_proc = $proc;
    }

    /** @return (callable): mixed|null */
    public function completion_proc(): ?callable
    {
        return $this->completion_proc;
    }

    /**
     * Normalise to a single character, ported from reline.rb:84. A multi-char
     * value keeps only its first character; empty / null clears it.
     */
    public function set_completion_append_character(?string $val): void
    {
        if ($val === null || $val === '') {
            $this->completion_append_character = '';
        } else {
            $chars = \mb_str_split($val, 1, 'UTF-8');
            $this->completion_append_character = $chars[0];
        }
    }

    public function completion_append_character(): string
    {
        return $this->completion_append_character;
    }

    /** @param (callable(string): void)|null $proc */
    public function set_dig_perfect_match_proc(?callable $proc): void
    {
        $this->dig_perfect_match_proc = $proc;
    }

    /** @return (callable(string): void)|null */
    public function dig_perfect_match_proc(): ?callable
    {
        return $this->dig_perfect_match_proc;
    }

    public function set_basic_word_break_characters(string $v): void
    {
        $this->basic_word_break_characters = $v;
    }

    public function set_completer_word_break_characters(string $v): void
    {
        $this->completer_word_break_characters = $v;
    }

    public function completer_word_break_characters(): string
    {
        return $this->completer_word_break_characters;
    }

    public function set_basic_quote_characters(string $v): void
    {
        $this->basic_quote_characters = $v;
    }

    public function set_completer_quote_characters(string $v): void
    {
        $this->completer_quote_characters = $v;
    }

    public function completer_quote_characters(): string
    {
        return $this->completer_quote_characters;
    }

    public function set_filename_quote_characters(string $v): void
    {
        $this->filename_quote_characters = $v;
    }

    public function set_special_prefixes(string $v): void
    {
        $this->special_prefixes = $v;
    }

    /** completion-ignore-case lives on Config (reline.rb:120-126). */
    public function set_completion_case_fold(bool $v): void
    {
        $this->config->set_completion_ignore_case($v);
    }

    public function completion_case_fold(): bool
    {
        return $this->config->completion_ignore_case();
    }

    /** The quote character in force during the last completion-proc call. */
    public function completion_quote_character(): ?string
    {
        return $this->line_editor->completion_quote_character();
    }

    public function autocompletion(): bool
    {
        return $this->config->autocompletion();
    }

    public function set_autocompletion(bool $v): void
    {
        $this->config->set_autocompletion($v);
    }

    // --- Dialog procs (reline.rb:162-174) ----------------------------------

    /**
     * @param callable(DialogProcScope): ?DialogRenderInfo|null $p
     * @param list<mixed>|null                                  $context
     */
    public function add_dialog_proc(string $name, ?callable $p, ?array $context = null): void
    {
        if ($p === null) {
            unset($this->dialog_proc_list[$name]);

            return;
        }
        $this->dialog_proc_list[$name] = ['proc' => $p, 'context' => $context];
    }

    /** @return array{proc: callable, context: list<mixed>|null}|null */
    public function dialog_proc(string $name): ?array
    {
        return $this->dialog_proc_list[$name] ?? null;
    }

    /**
     * The built-in autocomplete dropdown, ported from DEFAULT_DIALOG_PROC_
     * AUTOCOMPLETE (reline.rb:211-247). Upstream runs it under instance_exec so the
     * scope is `self`; here the scope is the explicit DialogProcScope argument (the
     * idiom deviation documented on DialogProcScope). Returns null to hide the
     * dialog, or a DialogRenderInfo describing the candidate dropdown.
     */
    public static function default_dialog_proc_autocomplete(): callable
    {
        return static function (DialogProcScope $scope): ?DialogRenderInfo {
            if (!$scope->config()->autocompletion()) {
                return null;
            }
            $journey_data = $scope->completion_journey_data();
            if ($journey_data === null) {
                return null;
            }
            $target = $journey_data->list[0];
            $completed = $journey_data->list[$journey_data->pointer];
            $result = \array_slice($journey_data->list, 1);
            $pointer = $journey_data->pointer - 1;
            if ($completed === '' || ($result === [$completed] && $pointer < 0)) {
                return null;
            }

            $target_width = Unicode::calculate_width($target);
            $completed_width = Unicode::calculate_width($completed);
            if ($scope->cursor_pos()->x <= $completed_width - $target_width) {
                // Target already rendered on the line above the cursor.
                $x = $scope->screen_width() - $completed_width;
                $y = -1;
            } else {
                $x = \max($scope->cursor_pos()->x - $completed_width, 0);
                $y = 0;
            }
            $cursor_pos_to_render = new CursorPos($x, $y);
            // The IRB-facing context.push (reline.rb:235-238) is not consumed
            // in-port and PHP arrays are value types, so it is omitted.
            $dialog = $scope->dialog();
            if ($dialog !== null) {
                $dialog->pointer = $pointer;
            }

            return new DialogRenderInfo(
                $cursor_pos_to_render,
                $result,
                'completion_dialog',
                \min(15, $scope->preferred_dialog_height()),
                true,
            );
        };
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function line_editor(): LineEditor
    {
        return $this->line_editor;
    }

    /** The history store; upstream's `Reline::HISTORY` constant, owned by Core here. */
    public function history(): History
    {
        return $this->history;
    }

    public function io_gate(): IO
    {
        return $this->io;
    }

    public function encoding(): string
    {
        return $this->io->encoding();
    }

    public function get_screen_size(): array
    {
        return $this->io->get_screen_size();
    }

    public function readline(string $prompt = '', bool $add_history = false): ?string
    {
        $this->io->with_raw_input(function () use ($prompt): void {
            $this->inner_readline($prompt, false, null);
        });

        $line = $this->line_editor->line();
        // Append the accepted line (chomped of one trailing newline), matching
        // reline.rb:284 — only when the caller asked and the line is non-empty.
        if ($add_history && $line !== null && $this->chomp_newline($line) !== '') {
            $this->history->push($this->chomp_newline($line));
        }
        if ($this->line_editor->line() === null) {
            $this->line_editor->reset_line();
        }

        return $line;
    }

    /**
     * The multiline entry point, ported from Reline#readmultiline (reline.rb:250).
     * The block decides when the buffer is complete: it is handed the whole buffer
     * with a trailing newline and returns whether to accept. Returns null when the
     * read is aborted by C-d on an empty buffer.
     *
     * @param callable(string): bool $confirm_multiline_termination
     */
    public function readmultiline(string $prompt, callable $confirm_multiline_termination, bool $add_history = false): ?string
    {
        $this->io->with_raw_input(function () use ($prompt, $confirm_multiline_termination): void {
            $this->inner_readline($prompt, true, $confirm_multiline_termination);
        });

        $whole_buffer = $this->line_editor->whole_buffer();
        // Upstream stores the whole buffer un-chomped, guarded on the chomped
        // length being non-zero (reline.rb:262).
        if ($add_history && $this->chomp_newline($whole_buffer) !== '') {
            $this->history->push($whole_buffer);
        }
        if ($this->line_editor->eof()) {
            $this->line_editor->reset_line();

            return null;
        }

        return $whole_buffer;
    }

    /** Ruby String#chomp("\n"): strip a single trailing newline. */
    private function chomp_newline(string $line): string
    {
        return \substr($line, -1) === "\n" ? \substr($line, 0, -1) : $line;
    }

    /**
     * @param (callable(string): bool)|null $confirm_multiline_termination
     */
    private function inner_readline(string $prompt, bool $multiline, ?callable $confirm_multiline_termination): void
    {
        if (!$this->config->test_mode() && !$this->config->loaded()) {
            $this->config->read();
            $this->io->set_default_key_bindings($this->config);
        }
        $otio = $this->io->prep();

        $this->may_req_ambiguous_char_width();
        $this->key_stroke->encoding = $this->io->encoding();
        $this->line_editor->reset($prompt);
        if ($multiline) {
            $this->line_editor->multiline_on();
            if ($confirm_multiline_termination !== null) {
                $this->line_editor->set_confirm_multiline_termination_proc($confirm_multiline_termination);
            }
        } else {
            $this->line_editor->multiline_off();
        }
        // Push the caller's completion settings onto the editor, as upstream
        // inner_readline assigns them (reline.rb:320-325). auto_indent_proc and
        // rprompt remain deferred past tier 4.
        $this->line_editor->set_completion_proc($this->completion_proc);
        $this->line_editor->set_completion_append_character($this->completion_append_character);
        $this->line_editor->set_dig_perfect_match_proc($this->dig_perfect_match_proc);
        $this->line_editor->set_completer_quote_characters($this->completer_quote_characters);
        $this->line_editor->set_completer_word_break_characters($this->completer_word_break_characters);

        // Register dialog procs unless the gate is dumb (reline.rb:330-334), then
        // prime them once before the first render.
        if (!$this->io->dumb()) {
            foreach ($this->dialog_proc_list as $name => $d) {
                $this->line_editor->add_dialog_proc($name, $d['proc'], $d['context']);
            }
        }
        $this->line_editor->update_dialogs();
        $this->line_editor->rerender();

        try {
            $this->line_editor->set_signal_handlers();
            while (true) {
                $this->read_io($this->config->keyseq_timeout(), function (array $inputs): void {
                    foreach ($inputs as $key) {
                        if ($key->method_symbol === 'bracketed_paste_start') {
                            $key = new Key($this->io->read_bracketed_paste(), 'insert_multiline_text', false);
                        } elseif ($key->method_symbol === 'quoted_insert' || $key->method_symbol === 'ed_quoted_insert') {
                            $char = $this->io->read_single_char($this->config->keyseq_timeout() / 1000);
                            $key = new Key($char ?? '', 'insert_raw_char', false);
                        }
                        $this->line_editor->set_pasting_state($this->io->in_pasting());
                        $this->line_editor->update($key);
                    }
                });
                if ($this->line_editor->finished()) {
                    $this->line_editor->render_finished();
                    break;
                }
                $this->line_editor->rerender();
            }
            $this->io->move_cursor_column(0);
        } finally {
            $this->line_editor->finalize();
            $this->io->deprep($otio);
        }
    }

    /**
     * Inspection seam: run one read_io cycle and return the expanded keys. Used
     * by the keyseq-timeout disambiguation test to drive read_io over a scripted
     * gate without the whole readline loop.
     *
     * @return list<Key>
     */
    public function read_keys(int $keyseq_timeout_ms): array
    {
        $out = [];
        $this->read_io($keyseq_timeout_ms, function (array $keys) use (&$out): void {
            $out = $keys;
        });

        return $out;
    }

    /**
     * GNU Readline waits keyseq-timeout ms when input is ambiguous between
     * matching and matched; ESC is the canonical case (a bare ESC, or the start
     * of `ESC char` / a CSI sequence). Ported from reline.rb:378-406.
     *
     * @param callable(list<Key>): void $block
     */
    private function read_io(int $keyseq_timeout_ms, callable $block): void
    {
        $buffer = [];
        $status = KeyStroke::MATCHING;
        while (true) {
            $timeout = $status === KeyStroke::MATCHING_MATCHED ? $keyseq_timeout_ms / 1000 : \INF;
            $c = $this->io->getc($timeout);
            if ($c === null || $c === -1) {
                if ($status === KeyStroke::MATCHING_MATCHED) {
                    $status = KeyStroke::MATCHED;
                } elseif ($buffer === []) {
                    // The gate is closed and reached EOF.
                    $block([new Key(null, null, false)]);

                    return;
                } else {
                    $status = KeyStroke::UNMATCHED;
                }
            } else {
                $buffer[] = $c;
                $status = $this->key_stroke->match_status($buffer);
            }

            if ($status === KeyStroke::MATCHED || $status === KeyStroke::UNMATCHED) {
                [$expanded, $restBytes] = $this->key_stroke->expand($buffer);
                foreach (\array_reverse($restBytes) as $b) {
                    $this->io->ungetc($b);
                }
                $block($expanded);

                return;
            }
        }
    }

    /**
     * Fix Unicode's ambiguous width once against the real terminal
     * (reline.rb:408-427): width 1 on a dumb gate or when either stdio is not a
     * tty; otherwise write `▽` (U+25BD) at column 0 and read the cursor column —
     * landed at 2 means the terminal renders ambiguous characters double-width.
     */
    private function may_req_ambiguous_char_width(): void
    {
        if ($this->io->dumb() || !\stream_isatty(\STDIN) || !\stream_isatty(\STDOUT)) {
            Unicode::setAmbiguousWidth(1);

            return;
        }
        $this->io->move_cursor_column(0);
        \fwrite($this->output, "\u{25bd}");
        \fflush($this->output);
        Unicode::setAmbiguousWidth($this->io->cursor_pos()->x === 2 ? 2 : 1);
        $this->io->move_cursor_column(0);
        $this->io->erase_after_cursor();
    }
}
