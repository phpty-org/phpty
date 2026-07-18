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

    private IO $io;

    /** @var resource */
    private $output;

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
        $this->line_editor = new LineEditor($this->config, $this->io);
        if ($this->io instanceof IO\Ansi) {
            $this->io->setConfig($this->config);
        }
        // Wire deferred-signal servicing: the read loop calls this between poll
        // slices (ansi.rb:126 / dumb.rb:59).
        $this->io->setSignalServicer(function (): void {
            $this->line_editor->handle_signal();
        });
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function line_editor(): LineEditor
    {
        return $this->line_editor;
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

    public function readline(string $prompt = ''): ?string
    {
        $this->io->with_raw_input(function () use ($prompt): void {
            $this->inner_readline($prompt, false, null);
        });

        $line = $this->line_editor->line();
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
    public function readmultiline(string $prompt, callable $confirm_multiline_termination): ?string
    {
        $this->io->with_raw_input(function () use ($prompt, $confirm_multiline_termination): void {
            $this->inner_readline($prompt, true, $confirm_multiline_termination);
        });

        $whole_buffer = $this->line_editor->whole_buffer();
        if ($this->line_editor->eof()) {
            $this->line_editor->reset_line();

            return null;
        }

        return $whole_buffer;
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
        // prompt_proc / auto_indent_proc / rprompt wiring (reline.rb:323-326) is
        // tier 4+; the LineEditor defaults them to nil, matching an unset Reline.
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
