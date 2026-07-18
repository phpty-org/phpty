<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\IO\Dumb;
use PhPty\Reline\Key;
use PhPty\Reline\KeyStroke;
use PhPty\Reline\LineEditor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The tier-2 multiline subset of test/reline/test_key_actor_emacs.rb, in
 * upstream's style: a Dumb gate stands in, keys are fed through KeyStroke::expand
 * into LineEditor::input_key, and the assertions are on @buffer_of_lines /
 * @line_index / the cursor split — no terminal.
 *
 * Covers: newline splitting a buffer (key_newline / insert_new_line), the
 * confirm_multiline_termination accept gate, cross-line vertical motion
 * (ed_prev_history / ed_next_history multiline branch), line joins at BOL
 * (em_delete_prev_char) and EOL (em_delete), and the bracketed-paste target
 * insert_multiline_text.
 */
final class LineEditorMultilineTest extends TestCase
{
    private Config $config;

    private LineEditor $editor;

    private KeyStroke $keyStroke;

    protected function set_up(): void
    {
        $this->config = new Config();
        $this->editor = new LineEditor($this->config, new Dumb());
        $this->editor->reset('> ');
        $this->editor->multiline_on();
        // proc {} — always falsy, so Enter never terminates and always splits.
        $this->editor->set_confirm_multiline_termination_proc(static fn (string $_buffer): bool => false);
        $this->keyStroke = new KeyStroke($this->config, 'UTF-8');
    }

    private function inputKeys(string $input): void
    {
        $bytes = $input === '' ? [] : \array_values(\unpack('C*', $input));
        while ($bytes !== []) {
            [$expanded, $bytes] = $this->keyStroke->expand($bytes);
            foreach ($expanded as $key) {
                $this->editor->input_key($key);
            }
        }
    }

    private function assertAroundCursor(string $before, string $after): void
    {
        $line = $this->editor->current_line();
        $bp = $this->editor->byte_pointer();
        $this->assertSame([$before, $after], [\substr($line, 0, $bp), \substr($line, $bp)]);
    }

    public function testNewlineSplitsBufferIntoLines(): void
    {
        $this->inputKeys("1\n2\n3");
        $this->assertSame(['1', '2', '3'], $this->editor->whole_lines());
        $this->assertSame(2, $this->editor->line_index());
        $this->assertAroundCursor('3', '');
    }

    public function testNewlineSplitsMidLine(): void
    {
        $this->inputKeys('abcd');
        $this->inputKeys("\x02\x02"); // C-b C-b -> cursor between "ab" and "cd"
        $this->inputKeys("\n");
        $this->assertSame(['ab', 'cd'], $this->editor->whole_lines());
        $this->assertSame(1, $this->editor->line_index());
        $this->assertAroundCursor('', 'cd');
    }

    public function testConfirmTerminationAcceptsAtLastLine(): void
    {
        // A heredoc-style confirm proc: finish only once the buffer ends in ";".
        $this->editor->set_confirm_multiline_termination_proc(
            static fn (string $buffer): bool => \substr(\rtrim($buffer, "\n"), -1) === ';',
        );
        $this->inputKeys("foo\n"); // not terminated: splits
        $this->assertSame(['foo', ''], $this->editor->whole_lines());
        $this->assertFalse($this->editor->finished());

        $this->inputKeys("bar;\n"); // terminated: accepts
        $this->assertTrue($this->editor->finished());
        $this->assertSame("foo\nbar;", $this->editor->whole_buffer());
    }

    public function testPrevAndNextLineKeepColumn(): void
    {
        $this->inputKeys("abc\ndef");
        $this->assertSame(1, $this->editor->line_index());
        $this->assertAroundCursor('def', '');
        $this->inputKeys("\x10"); // C-p up to "abc", column preserved at end
        $this->assertSame(0, $this->editor->line_index());
        $this->assertAroundCursor('abc', '');
        $this->inputKeys("\x0e"); // C-n back down to "def"
        $this->assertSame(1, $this->editor->line_index());
        $this->assertAroundCursor('def', '');
    }

    public function testPrevLineSnapsToNearestColumn(): void
    {
        // Upper line shorter than the cursor column on the lower line: the cursor
        // snaps to the nearest grapheme boundary (calculate_nearest_cursor).
        $this->inputKeys("ab\ncdef");
        $this->assertAroundCursor('cdef', '');
        $this->inputKeys("\x10"); // C-p: column 4 clamps to end of "ab" (col 2)
        $this->assertSame(0, $this->editor->line_index());
        $this->assertAroundCursor('ab', '');
    }

    public function testBackspaceJoinsLinesAtBol(): void
    {
        $this->inputKeys("1\n2\n3");
        $this->inputKeys("\x10"); // C-p to "2"
        $this->inputKeys("\x01"); // C-a to beginning of "2"
        $this->inputKeys("\x08"); // Backspace joins "1" and "2"
        $this->assertSame(['12', '3'], $this->editor->whole_lines());
        $this->assertSame(0, $this->editor->line_index());
        $this->assertAroundCursor('1', '2');
    }

    public function testDeleteJoinsLinesAtEol(): void
    {
        $this->inputKeys("ab\ncd");
        $this->inputKeys("\x10"); // C-p to "ab"
        $this->inputKeys("\x05"); // C-e to end of "ab"
        $this->inputKeys("\x04"); // C-d joins "ab" and "cd"
        $this->assertSame(['abcd'], $this->editor->whole_lines());
        $this->assertSame(0, $this->editor->line_index());
        $this->assertAroundCursor('ab', 'cd');
    }

    public function testInsertMultilineTextSplitsAtNewlines(): void
    {
        $this->editor->set_current_line('AZ', 1); // cursor between A and Z
        // Bracketed paste of "abc\n" + C-a + "bc" (upstream test_bracketed_paste_insert).
        $this->editor->input_key(new Key("abc\n\x01bc", 'insert_multiline_text', false));
        $this->assertSame(['Aabc', "\x01bcZ"], $this->editor->whole_lines());
        $this->assertSame(1, $this->editor->line_index());
        $this->assertAroundCursor("\x01bc", 'Z');
    }

    public function testInsertMultilineTextNormalisesCrlf(): void
    {
        $this->editor->input_key(new Key("aaa\r\nbbb\rccc", 'insert_multiline_text', false));
        $this->assertSame(['aaa', 'bbb', 'ccc'], $this->editor->whole_lines());
        $this->assertSame(2, $this->editor->line_index());
        $this->assertAroundCursor('ccc', '');
    }
}
