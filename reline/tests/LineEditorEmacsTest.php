<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\IO\Dumb;
use PhPty\Reline\KeyStroke;
use PhPty\Reline\LineEditor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The tier-1-relevant subset of test/reline/test_key_actor_emacs.rb, ported in
 * upstream's style: feed byte sequences through KeyStroke::expand into
 * LineEditor::input_key (the helper's `input_keys`), then assert the buffer split
 * at the cursor (`assert_line_around_cursor`). No terminal is involved — a Dumb
 * gate stands in, as upstream runs these against its test-mode Dumb IOGate.
 *
 * Covers insertion (ascii, combining, and wide multibyte), cursor motion,
 * backspace across a wide 日本語 run, kill-to-end + yank, transpose, and the
 * C-d-on-empty EOF. vi motions, history, and completion cases are out of tier 1
 * and not ported.
 */
final class LineEditorEmacsTest extends TestCase
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

    private function assertAroundCursor(string $before, string $after): void
    {
        $line = $this->editor->current_line();
        $bp = $this->editor->byte_pointer();
        $this->assertSame([$before, $after], [\substr($line, 0, $bp), \substr($line, $bp)]);
    }

    public function testInsertAscii(): void
    {
        $this->inputKeys('ab');
        $this->assertAroundCursor('ab', '');
    }

    public function testInsertWideMbchar(): void
    {
        $this->inputKeys('かき');
        $this->assertAroundCursor('かき', '');
    }

    public function testInsertCombiningCodepoints(): void
    {
        // か + combining voiced mark is one grapheme, inserted whole.
        $this->inputKeys("か\u{3099}");
        $this->assertAroundCursor("か\u{3099}", '');
    }

    public function testMoveNextAndPrev(): void
    {
        $this->inputKeys('abd');
        $this->assertAroundCursor('abd', '');
        $this->inputKeys("\x02"); // C-b
        $this->assertAroundCursor('ab', 'd');
        $this->inputKeys("\x02"); // C-b
        $this->assertAroundCursor('a', 'bd');
        $this->inputKeys("\x06"); // C-f
        $this->assertAroundCursor('ab', 'd');
    }

    public function testMoveToBegAndEnd(): void
    {
        $this->inputKeys('abc');
        $this->inputKeys("\x01"); // C-a
        $this->assertAroundCursor('', 'abc');
        $this->inputKeys("\x05"); // C-e
        $this->assertAroundCursor('abc', '');
    }

    public function testInsertMidwayThroughWideRun(): void
    {
        $this->inputKeys('日本語');
        $this->inputKeys("\x01");      // to beginning
        $this->inputKeys("\x06\x06"); // C-f C-f: past 日本
        $this->inputKeys('X');
        $this->assertAroundCursor('日本X', '語');
    }

    public function testBackspaceAcrossWideChars(): void
    {
        $this->inputKeys('日本語');
        $this->assertAroundCursor('日本語', '');
        $this->inputKeys("\x7f"); // Backspace removes 語 (3 bytes)
        $this->assertAroundCursor('日本', '');
        $this->inputKeys("\x7f");
        $this->assertAroundCursor('日', '');
    }

    public function testKillLineAndYank(): void
    {
        $this->inputKeys('hello world');
        $this->inputKeys("\x01");          // C-a to beginning
        $this->inputKeys("\x06\x06\x06\x06\x06"); // C-f x5 -> after "hello"
        $this->inputKeys("\x0b");          // C-k kills " world"
        $this->assertAroundCursor('hello', '');
        $this->inputKeys("\x19");          // C-y yanks it back
        $this->assertAroundCursor('hello world', '');
    }

    public function testTransposeChars(): void
    {
        $this->inputKeys('ab');
        $this->inputKeys("\x14"); // C-t transposes the two chars before/at cursor
        $this->assertAroundCursor('ba', '');
    }

    public function testDeleteWordForward(): void
    {
        $this->inputKeys('foo bar');
        $this->inputKeys("\x01");     // C-a
        $this->inputKeys("\x1bd");    // M-d kills "foo"
        $this->assertAroundCursor('', ' bar');
    }

    public function testControlDDeletesForward(): void
    {
        $this->inputKeys('abc');
        $this->inputKeys("\x01");  // C-a
        $this->inputKeys("\x04");  // C-d deletes 'a'
        $this->assertAroundCursor('', 'bc');
    }

    public function testControlDOnEmptyIsEof(): void
    {
        $this->inputKeys("\x04"); // C-d on an empty line
        $this->assertTrue($this->editor->eof());
        $this->assertTrue($this->editor->finished());
    }

    public function testAcceptLineFinishes(): void
    {
        $this->inputKeys('hi');
        $this->inputKeys("\r"); // Enter (C-m)
        $this->assertTrue($this->editor->finished());
        $this->assertFalse($this->editor->eof());
        $this->assertSame('hi', $this->editor->line());
    }
}
