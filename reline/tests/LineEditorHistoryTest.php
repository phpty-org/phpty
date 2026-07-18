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
 * The history cases of test/reline/test_key_actor_emacs.rb: prev/next navigation
 * (incl. multiline entries), the size cap in the editor, C-r/C-s incremental
 * search, and M-</M-> jumps. Same harness as LineEditorEmacsTest — keys fed
 * through KeyStroke::expand into input_key over a Dumb gate — with the store
 * reached via `$editor->history()`, the injected-not-global stand-in for
 * `Reline::HISTORY` (CONTEXT.md).
 *
 * ed_search_prev_history / ed_search_next_history (history_search_backward/
 * forward) are NOT bound in the 0.6.3 emacs keymap and so are out of scope; their
 * upstream tests are skipped. test_search_history_with_isearch_terminator needs
 * inputrc parsing (tier 7) and is likewise skipped.
 */
final class LineEditorHistoryTest extends TestCase
{
    private Config $config;

    private LineEditor $editor;

    private KeyStroke $keyStroke;

    protected function set_up(): void
    {
        $this->config = new Config();
        $this->editor = new LineEditor($this->config, new Dumb());
        $this->editor->reset('> ');
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

    /** Mirror the helper's input_key_by_symbol: dispatch a command by name. */
    private function inputBySymbol(string $methodSymbol, ?string $char = null, bool $csi = false): void
    {
        $char ??= $csi ? "\e[A" : "\x01";
        $this->editor->input_key(new Key($char, $methodSymbol, false));
    }

    private function assertAroundCursor(string $before, string $after): void
    {
        $line = $this->editor->current_line();
        $bp = $this->editor->byte_pointer();
        $this->assertSame([$before, $after], [\substr($line, 0, $bp), \substr($line, $bp)]);
    }

    public function testPrevAndNextNavigation(): void
    {
        $this->editor->history()->concat('abc', '123', 'AAA');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x10"); // C-p -> newest
        $this->assertAroundCursor('AAA', '');
        $this->inputKeys("\x10"); // C-p
        $this->assertAroundCursor('123', '');
        $this->inputKeys("\x0e"); // C-n back down
        $this->assertAroundCursor('AAA', '');
        $this->inputKeys("\x0e"); // C-n past the newest restores the fresh line
        $this->assertAroundCursor('', '');
    }

    public function testEditingHistoryLeavesFreshLineIntact(): void
    {
        // Typing then C-p stashes the fresh line; C-n restores it unedited even
        // though the recalled entry was itself edited.
        $this->editor->history()->concat('old');
        $this->inputKeys('fresh');
        $this->inputKeys("\x10"); // C-p -> "old"
        $this->assertAroundCursor('old', '');
        $this->inputKeys('X'); // edit the recalled entry
        $this->assertAroundCursor('oldX', '');
        $this->inputKeys("\x0e"); // C-n -> back to the fresh line
        $this->assertAroundCursor('fresh', '');
        // The edit is retained in the store until accept (upstream behaviour).
        $this->assertSame('oldX', $this->editor->history()[0]);
    }

    public function testLargerHistoriesThanHistorySize(): void
    {
        $this->config->set_history_size(2);
        $this->editor->history()->concat('abc', '123', 'AAA');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x10");
        $this->assertAroundCursor('AAA', '');
        $this->inputKeys("\x10");
        $this->assertAroundCursor('123', '');
        $this->inputKeys("\x10"); // already oldest: stays
        $this->assertAroundCursor('123', '');
    }

    public function testMultilineHistoryEntryNavigation(): void
    {
        $this->editor->multiline_on();
        $this->editor->history()->concat("a\nb\nc");
        $this->inputKeys("\x10"); // C-p recalls the 3-line entry, cursor on last line
        $this->assertSame(['a', 'b', 'c'], $this->editor->whole_lines());
        $this->assertSame(2, $this->editor->line_index());
        $this->assertAroundCursor('c', '');
        $this->inputKeys("\x10"); // C-p moves up within the buffer
        $this->assertSame(1, $this->editor->line_index());
        $this->inputKeys("\x0e"); // C-n moves back down within the buffer
        $this->assertSame(2, $this->editor->line_index());
    }

    public function testBeginningOfHistory(): void
    {
        $this->editor->history()->concat('abc', '123');
        $this->inputBySymbol('beginning_of_history');
        $this->assertAroundCursor('abc', '');
    }

    public function testEndOfHistory(): void
    {
        $this->editor->history()->concat('abc', '123');
        $this->inputKeys("def\x10\x10"); // "def" then C-p C-p -> "abc"
        $this->inputKeys('d');
        $this->assertAroundCursor('abcd', '');
        $this->inputBySymbol('end_of_history'); // M-> -> back to the fresh "def"
        $this->assertAroundCursor('def', '');
    }

    // --- Incremental search ------------------------------------------------

    public function testViSearchPrev(): void
    {
        $this->editor->history()->concat('abc', '123', 'AAA');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x12a\x0a"); // C-r a C-j
        $this->assertAroundCursor('', 'abc');
    }

    public function testSearchHistoryToBack(): void
    {
        $this->editor->history()->concat('1235', '12aa', '1234');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x12" . '123'); // C-r 123
        $this->assertAroundCursor('1234', '');
        $this->inputKeys("\x08" . 'a'); // C-h a
        $this->assertAroundCursor('12aa', '');
        $this->inputKeys("\x08" . '3'); // C-h 3
        $this->assertAroundCursor('1235', '');
    }

    public function testSearchHistoryToFront(): void
    {
        $this->editor->history()->concat('1235', '12aa', '1234');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x13" . '123'); // C-s 123
        $this->assertAroundCursor('1235', '');
        $this->inputKeys("\x08" . 'a'); // C-h a
        $this->assertAroundCursor('12aa', '');
        $this->inputKeys("\x08" . '3'); // C-h 3
        $this->assertAroundCursor('1234', '');
    }

    public function testSearchHistoryFrontAndBack(): void
    {
        $this->editor->history()->concat('1235', '12aa', '1234');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x13" . '12'); // C-s 12
        $this->assertAroundCursor('1235', '');
        $this->inputKeys("\x13"); // C-s
        $this->assertAroundCursor('12aa', '');
        $this->inputKeys("\x12"); // C-r
        $this->assertAroundCursor('12aa', '');
        $this->inputKeys("\x12"); // C-r
        $this->assertAroundCursor('1235', '');
    }

    public function testSearchHistoryBackAndFront(): void
    {
        $this->editor->history()->concat('1235', '12aa', '1234');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x12" . '12'); // C-r 12
        $this->assertAroundCursor('1234', '');
        $this->inputKeys("\x12"); // C-r
        $this->assertAroundCursor('12aa', '');
        $this->inputKeys("\x13"); // C-s
        $this->assertAroundCursor('12aa', '');
        $this->inputKeys("\x13"); // C-s
        $this->assertAroundCursor('1234', '');
    }

    public function testSearchHistoryToBackInTheMiddle(): void
    {
        $this->editor->history()->concat('1235', '12aa', '1234');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x10\x10"); // C-p C-p
        $this->assertAroundCursor('12aa', '');
        $this->inputKeys("\x12" . '123'); // C-r 123
        $this->assertAroundCursor('1235', '');
    }

    public function testSearchHistoryTwice(): void
    {
        $this->editor->history()->concat('1235', '12aa', '1234');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x12" . '123'); // C-r 123
        $this->assertAroundCursor('1234', '');
        $this->inputKeys("\x12"); // C-r
        $this->assertAroundCursor('1235', '');
    }

    public function testSearchHistoryByLastDetermined(): void
    {
        $this->editor->history()->concat('1235', '12aa', '1234');
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x12" . '123'); // C-r 123
        $this->assertAroundCursor('1234', '');
        $this->inputKeys("\x0a"); // C-j commits
        $this->assertAroundCursor('', '1234');
        $this->inputKeys("\x0b"); // C-k delete
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x12"); // C-r (empty)
        $this->assertAroundCursor('', '');
        $this->inputKeys("\x12"); // C-r reuses the last determined search "123"
        $this->assertAroundCursor('1235', '');
    }

    public function testIncrementalSearchCancelBySymbolKey(): void
    {
        // A multi-character (csi) key cancels the search and then runs as its
        // command: C-r, then the left-arrow moves the cursor and ends search.
        $this->inputKeys("abc\x12"); // "abc" then C-r
        $this->inputBySymbol('ed_prev_char', null, true); // csi left arrow
        $this->inputKeys('d');
        $this->assertAroundCursor('abd', 'c');
    }

    public function testIncrementalSearchSavesAndRestoresLastInput(): void
    {
        $this->editor->history()->concat('abc', '123');
        $this->inputKeys('abcd');
        $this->inputKeys("\x12" . '12' . "\x0a"); // C-r 12 C-j terminates
        $this->assertAroundCursor('', '123');
        $this->inputBySymbol('ed_next_history');
        $this->assertAroundCursor('abcd', '');
        // A non-printable key also terminates: C-i (tab).
        $this->inputKeys("\x12" . '12' . "\x09");
        $this->assertAroundCursor('', '123');
        $this->inputBySymbol('ed_next_history');
        $this->assertAroundCursor('abcd', '');
        // C-g cancels and restores the input, cursor and history index.
        $this->inputBySymbol('ed_prev_history');
        $this->inputKeys("\x02\x02"); // C-b C-b
        $this->assertAroundCursor('1', '23');
        $this->inputKeys("\x12" . 'ab' . "\x07"); // C-r ab C-g
        $this->assertAroundCursor('1', '23');
        $this->inputBySymbol('ed_next_history');
        $this->assertAroundCursor('abcd', '');
    }
}
