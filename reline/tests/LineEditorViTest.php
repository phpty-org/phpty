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
 * Port of test/reline/test_key_actor_vi.rb, in the same style as
 * LineEditorEmacsTest: feed byte sequences through KeyStroke::expand into
 * LineEditor::input_key (`inputKeys`), then assert the buffer split at the cursor
 * (`assertAroundCursor`). A Dumb gate stands in for the terminal.
 *
 * Upstream flips the editor into vi mode with the inputrc line `set editing-mode
 * vi`; inputrc parsing is tier 7, so here the mode is set programmatically
 * (`set_editing_mode('vi_insert')`) — the note the tier brief calls for. Covers
 * the mode switches, motions (h/l/w/b/e/W/B/E/f/F/t/T/0/^/|), editing
 * (x/X/r/p/P/C/^U), the d/c/y operator composition (incl. counts, cancellation,
 * and waiting-proc motions), and the vi_command byte-pointer clamp.
 */
final class LineEditorViTest extends TestCase
{
    private Config $config;

    private LineEditor $editor;

    private KeyStroke $keyStroke;

    protected function set_up(): void
    {
        $this->config = new Config();
        // The inputrc `set editing-mode vi` path is tier 7; set it programmatically.
        $this->config->set_editing_mode('vi_insert');
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

    private function inputKeyBySymbol(string $sym, ?string $char = null, bool $csi = false): void
    {
        $char = $char ?? ($csi ? "\e[A" : "\x01");
        $this->editor->input_key(new Key($char, $sym, false));
    }

    private function assertAroundCursor(string $before, string $after): void
    {
        $line = $this->editor->current_line();
        $bp = $this->editor->byte_pointer();
        $this->assertSame([$before, $after], [\substr($line, 0, $bp), \substr($line, $bp)]);
    }

    private function assertMode(string $label): void
    {
        $this->assertSame($label, $this->config->editing_mode_label());
    }

    // --- Mode switches -----------------------------------------------------

    public function testViCommandMode(): void
    {
        $this->inputKeys("\x1b");
        $this->assertMode('vi_command');
    }

    public function testViCommandModeWithInput(): void
    {
        $this->inputKeys("abc\x1b");
        $this->assertMode('vi_command');
        $this->assertAroundCursor('ab', 'c');
    }

    public function testViInsert(): void
    {
        $this->assertMode('vi_insert');
        $this->inputKeys('i');
        $this->assertAroundCursor('i', '');
        $this->assertMode('vi_insert');
        $this->inputKeys("\x1b");
        $this->assertAroundCursor('', 'i');
        $this->assertMode('vi_command');
        $this->inputKeys('i');
        $this->assertAroundCursor('', 'i');
        $this->assertMode('vi_insert');
    }

    public function testViAdd(): void
    {
        $this->assertMode('vi_insert');
        $this->inputKeys('a');
        $this->assertAroundCursor('a', '');
        $this->inputKeys("\x1b");
        $this->assertAroundCursor('', 'a');
        $this->assertMode('vi_command');
        $this->inputKeys('a');
        $this->assertAroundCursor('a', '');
        $this->assertMode('vi_insert');
    }

    public function testViInsertAtBol(): void
    {
        $this->inputKeys('I');
        $this->assertAroundCursor('I', '');
        $this->assertMode('vi_insert');
        $this->inputKeys("12345\x1bhh");
        $this->assertAroundCursor('I12', '345');
        $this->assertMode('vi_command');
        $this->inputKeys('I');
        $this->assertAroundCursor('', 'I12345');
        $this->assertMode('vi_insert');
    }

    public function testViAddAtEol(): void
    {
        $this->inputKeys('A');
        $this->assertAroundCursor('A', '');
        $this->assertMode('vi_insert');
        $this->inputKeys("12345\x1bhh");
        $this->assertAroundCursor('A12', '345');
        $this->assertMode('vi_command');
        $this->inputKeys('A');
        $this->assertAroundCursor('A12345', '');
        $this->assertMode('vi_insert');
    }

    public function testEmacsEditingMode(): void
    {
        $this->inputKeyBySymbol('emacs_editing_mode');
        $this->assertTrue($this->config->editing_mode_is('emacs'));
    }

    // --- Insert ------------------------------------------------------------

    public function testEdInsertMbchar(): void
    {
        $this->inputKeys('かき');
        $this->assertAroundCursor('かき', '');
    }

    public function testEdInsertForMbcharByPluralCodePoints(): void
    {
        $this->inputKeys("か\u{3099}き\u{3099}");
        $this->assertAroundCursor("か\u{3099}き\u{3099}", '');
    }

    public function testEdInsertIgnoreInViCommand(): void
    {
        $this->inputKeys("\x1b");
        $ignored = "\x0fあ=";
        $this->inputKeys($ignored);
        $this->assertAroundCursor('', '');
        $this->inputKeys("5\x0f5あ5=");
        $this->assertAroundCursor('', '');
        $this->inputKeys('iい');
        $this->assertAroundCursor('い', '');
    }

    // --- Motion ------------------------------------------------------------

    public function testEdNextChar(): void
    {
        $this->inputKeys("abcdef\x1b0");
        $this->assertAroundCursor('', 'abcdef');
        $this->inputKeys('l');
        $this->assertAroundCursor('a', 'bcdef');
        $this->inputKeys('2l');
        $this->assertAroundCursor('abc', 'def');
    }

    public function testEdPrevChar(): void
    {
        $this->inputKeys("abcdef\x1b");
        $this->assertAroundCursor('abcde', 'f');
        $this->inputKeys('h');
        $this->assertAroundCursor('abcd', 'ef');
        $this->inputKeys('2h');
        $this->assertAroundCursor('ab', 'cdef');
    }

    public function testHistory(): void
    {
        $this->editor->history()->concat('abc', '123', 'AAA');
        $this->inputKeys("\x1b");
        $this->assertAroundCursor('', '');
        $this->inputKeys('k');
        $this->assertAroundCursor('', 'AAA');
        $this->inputKeys('2k');
        $this->assertAroundCursor('', 'abc');
        $this->inputKeys('j');
        $this->assertAroundCursor('', '123');
        $this->inputKeys('2j');
        $this->assertAroundCursor('', '');
    }

    public function testViFirstPrint(): void
    {
        $this->inputKeys("abcde\x1b^");
        $this->assertAroundCursor('', 'abcde');
        $this->inputKeys("0\x0bi");
        $this->inputKeys(" abcde\x1b^");
        $this->assertAroundCursor(' ', 'abcde');
        $this->inputKeys("0\x0bi");
        $this->inputKeys("   abcde  ABCDE  \x1b^");
        $this->assertAroundCursor('   ', 'abcde  ABCDE  ');
    }

    public function testEdMoveToBeg(): void
    {
        $this->inputKeys("abcde\x1b0");
        $this->assertAroundCursor('', 'abcde');
        $this->inputKeys("0\x0bi");
        $this->inputKeys("   abcde  ABCDE  \x1b0");
        $this->assertAroundCursor('', '   abcde  ABCDE  ');
    }

    public function testViToColumn(): void
    {
        $this->inputKeys("a一二三\x1b0");
        $this->inputKeys('1|');
        $this->assertAroundCursor('', 'a一二三');
        $this->inputKeys('2|');
        $this->assertAroundCursor('a', '一二三');
        $this->inputKeys('3|');
        $this->assertAroundCursor('a', '一二三');
        $this->inputKeys('4|');
        $this->assertAroundCursor('a一', '二三');
        $this->inputKeys('9|');
        $this->assertAroundCursor('a一二', '三');
    }

    public function testViPrevNextWord(): void
    {
        $this->inputKeys("aaa b{b}b ccc\x1b0");
        $this->assertAroundCursor('', 'aaa b{b}b ccc');
        $this->inputKeys('w');
        $this->assertAroundCursor('aaa ', 'b{b}b ccc');
        $this->inputKeys('w');
        $this->assertAroundCursor('aaa b', '{b}b ccc');
        $this->inputKeys('w');
        $this->assertAroundCursor('aaa b{', 'b}b ccc');
        $this->inputKeys('w');
        $this->assertAroundCursor('aaa b{b', '}b ccc');
        $this->inputKeys('w');
        $this->assertAroundCursor('aaa b{b}', 'b ccc');
        $this->inputKeys('w');
        $this->assertAroundCursor('aaa b{b}b ', 'ccc');
        $this->inputKeys('w');
        $this->assertAroundCursor('aaa b{b}b cc', 'c');
        $this->inputKeys('b');
        $this->assertAroundCursor('aaa b{b}b ', 'ccc');
        $this->inputKeys('b');
        $this->assertAroundCursor('aaa b{b}', 'b ccc');
        $this->inputKeys('b');
        $this->assertAroundCursor('aaa b{b', '}b ccc');
        $this->inputKeys('b');
        $this->assertAroundCursor('aaa b{', 'b}b ccc');
        $this->inputKeys('b');
        $this->assertAroundCursor('aaa b', '{b}b ccc');
        $this->inputKeys('b');
        $this->assertAroundCursor('aaa ', 'b{b}b ccc');
        $this->inputKeys('b');
        $this->assertAroundCursor('', 'aaa b{b}b ccc');
        $this->inputKeys('3w');
        $this->assertAroundCursor('aaa b{', 'b}b ccc');
        $this->inputKeys('3w');
        $this->assertAroundCursor('aaa b{b}b ', 'ccc');
        $this->inputKeys('3w');
        $this->assertAroundCursor('aaa b{b}b cc', 'c');
        $this->inputKeys('3b');
        $this->assertAroundCursor('aaa b{b', '}b ccc');
        $this->inputKeys('3b');
        $this->assertAroundCursor('aaa ', 'b{b}b ccc');
        $this->inputKeys('3b');
        $this->assertAroundCursor('', 'aaa b{b}b ccc');
    }

    public function testViEndWord(): void
    {
        $this->inputKeys("aaa   b{b}}}b   ccc\x1b0");
        $this->assertAroundCursor('', 'aaa   b{b}}}b   ccc');
        $this->inputKeys('e');
        $this->assertAroundCursor('aa', 'a   b{b}}}b   ccc');
        $this->inputKeys('e');
        $this->assertAroundCursor('aaa   ', 'b{b}}}b   ccc');
        $this->inputKeys('e');
        $this->assertAroundCursor('aaa   b', '{b}}}b   ccc');
        $this->inputKeys('e');
        $this->assertAroundCursor('aaa   b{', 'b}}}b   ccc');
        $this->inputKeys('e');
        $this->assertAroundCursor('aaa   b{b}}', '}b   ccc');
        $this->inputKeys('e');
        $this->assertAroundCursor('aaa   b{b}}}', 'b   ccc');
        $this->inputKeys('e');
        $this->assertAroundCursor('aaa   b{b}}}b   cc', 'c');
        $this->inputKeys('e');
        $this->assertAroundCursor('aaa   b{b}}}b   cc', 'c');
        $this->inputKeys('03e');
        $this->assertAroundCursor('aaa   b', '{b}}}b   ccc');
        $this->inputKeys('3e');
        $this->assertAroundCursor('aaa   b{b}}}', 'b   ccc');
        $this->inputKeys('3e');
        $this->assertAroundCursor('aaa   b{b}}}b   cc', 'c');
    }

    public function testViPrevNextBigWord(): void
    {
        $this->inputKeys("aaa b{b}b ccc\x1b0");
        $this->assertAroundCursor('', 'aaa b{b}b ccc');
        $this->inputKeys('W');
        $this->assertAroundCursor('aaa ', 'b{b}b ccc');
        $this->inputKeys('W');
        $this->assertAroundCursor('aaa b{b}b ', 'ccc');
        $this->inputKeys('2B');
        $this->assertAroundCursor('', 'aaa b{b}b ccc');
        $this->inputKeys('2W');
        $this->assertAroundCursor('aaa b{b}b ', 'ccc');
    }

    public function testViEndBigWord(): void
    {
        $this->inputKeys("aaa   b{b}}}b   ccc\x1b0");
        $this->inputKeys('E');
        $this->assertAroundCursor('aa', 'a   b{b}}}b   ccc');
        $this->inputKeys('E');
        $this->assertAroundCursor('aaa   b{b}}}', 'b   ccc');
        $this->inputKeys('E');
        $this->assertAroundCursor('aaa   b{b}}}b   cc', 'c');
    }

    public function testViNextChar(): void
    {
        $this->inputKeys("abcdef\x1b0");
        $this->assertAroundCursor('', 'abcdef');
        $this->inputKeys('fz');
        $this->assertAroundCursor('', 'abcdef');
        $this->inputKeys('fe');
        $this->assertAroundCursor('abcd', 'ef');
    }

    public function testViToNextChar(): void
    {
        $this->inputKeys("abcdef\x1b0");
        $this->inputKeys('tz');
        $this->assertAroundCursor('', 'abcdef');
        $this->inputKeys('te');
        $this->assertAroundCursor('abc', 'def');
    }

    public function testViPrevChar(): void
    {
        $this->inputKeys("abcdef\x1b");
        $this->assertAroundCursor('abcde', 'f');
        $this->inputKeys('Fz');
        $this->assertAroundCursor('abcde', 'f');
        $this->inputKeys('Fa');
        $this->assertAroundCursor('', 'abcdef');
    }

    public function testViToPrevChar(): void
    {
        $this->inputKeys("abcdef\x1b");
        $this->assertAroundCursor('abcde', 'f');
        $this->inputKeys('Ta');
        $this->assertAroundCursor('a', 'bcdef');
    }

    // --- Editing -----------------------------------------------------------

    public function testViPastePrev(): void
    {
        $this->inputKeys("abcde\x1b3h");
        $this->assertAroundCursor('a', 'bcde');
        $this->inputKeys('P');
        $this->assertAroundCursor('a', 'bcde');
        $this->inputKeys('d$');
        $this->assertAroundCursor('', 'a');
        $this->inputKeys('P');
        $this->assertAroundCursor('bcd', 'ea');
        $this->inputKeys('2P');
        $this->assertAroundCursor('bcdbcdbcd', 'eeea');
    }

    public function testViPasteNext(): void
    {
        $this->inputKeys("abcde\x1b3h");
        $this->assertAroundCursor('a', 'bcde');
        $this->inputKeys('p');
        $this->assertAroundCursor('a', 'bcde');
        $this->inputKeys('d$');
        $this->assertAroundCursor('', 'a');
        $this->inputKeys('p');
        $this->assertAroundCursor('abcd', 'e');
        $this->inputKeys('2p');
        $this->assertAroundCursor('abcdebcdebcd', 'e');
    }

    public function testViPastePrevForMbchar(): void
    {
        $this->inputKeys("あいうえお\x1b3h");
        $this->assertAroundCursor('あ', 'いうえお');
        $this->inputKeys('d$');
        $this->assertAroundCursor('', 'あ');
        $this->inputKeys('P');
        $this->assertAroundCursor('いうえ', 'おあ');
    }

    public function testViReplaceChar(): void
    {
        $this->inputKeys("abcdef\x1b03l");
        $this->assertAroundCursor('abc', 'def');
        $this->inputKeys('rz');
        $this->assertAroundCursor('abc', 'zef');
        $this->inputKeys('2rx');
        $this->assertAroundCursor('abcxx', 'f');
    }

    public function testViReplaceCharWithMbchar(): void
    {
        $this->inputKeys("あいうえお\x1b0l");
        $this->assertAroundCursor('あ', 'いうえお');
        $this->inputKeys('rx');
        $this->assertAroundCursor('あ', 'xうえお');
        $this->inputKeys('l2ry');
        $this->assertAroundCursor('あxyy', 'お');
    }

    public function testViDeleteNextChar(): void
    {
        $this->inputKeys("abc\x1bh");
        $this->assertAroundCursor('a', 'bc');
        $this->inputKeys('x');
        $this->assertAroundCursor('a', 'c');
        $this->inputKeys('x');
        $this->assertAroundCursor('', 'a');
        $this->inputKeys('x');
        $this->assertAroundCursor('', '');
        $this->inputKeys('x');
        $this->assertAroundCursor('', '');
    }

    public function testViDeleteNextCharForMbchar(): void
    {
        $this->inputKeys("あいう\x1bh");
        $this->assertAroundCursor('あ', 'いう');
        $this->inputKeys('x');
        $this->assertAroundCursor('あ', 'う');
        $this->inputKeys('x');
        $this->assertAroundCursor('', 'あ');
    }

    public function testViDeletePrevChar(): void
    {
        $this->inputKeys('ab');
        $this->assertAroundCursor('ab', '');
        $this->inputKeys("\x08");
        $this->assertAroundCursor('a', '');
    }

    public function testViDeletePrevCharForMbcharByPluralCodePoints(): void
    {
        $this->inputKeys("か\u{3099}き\u{3099}");
        $this->assertAroundCursor("か\u{3099}き\u{3099}", '');
        $this->inputKeys("\x08");
        $this->assertAroundCursor("か\u{3099}", '');
    }

    public function testEdDeletePrevChar(): void
    {
        $this->inputKeys("abcdefg\x1bh");
        $this->assertAroundCursor('abcde', 'fg');
        $this->inputKeys('X');
        $this->assertAroundCursor('abcd', 'fg');
        $this->inputKeys('3X');
        $this->assertAroundCursor('a', 'fg');
        $this->inputKeys('p');
        $this->assertAroundCursor('afbc', 'dg');
    }

    public function testEdDeletePrevWord(): void
    {
        $this->inputKeys('abc def{bbb}ccc');
        $this->assertAroundCursor('abc def{bbb}ccc', '');
        $this->inputKeys("\x17");
        $this->assertAroundCursor('abc def{bbb}', '');
        $this->inputKeys("\x17");
        $this->assertAroundCursor('abc def{', '');
        $this->inputKeys("\x17");
        $this->assertAroundCursor('abc ', '');
        $this->inputKeys("\x17");
        $this->assertAroundCursor('', '');
    }

    public function testEdDeleteNextCharAtEol(): void
    {
        $this->inputKeys('"あ"');
        $this->assertAroundCursor('"あ"', '');
        $this->inputKeys("\x1b");
        $this->assertAroundCursor('"あ', '"');
        $this->inputKeys('xa"');
        $this->assertAroundCursor('"あ"', '');
    }

    public function testViKillLinePrev(): void
    {
        $this->inputKeys("\x15");
        $this->assertAroundCursor('', '');
        $this->inputKeys('abc');
        $this->assertAroundCursor('abc', '');
        $this->inputKeys("\x15");
        $this->assertAroundCursor('', '');
        $this->inputKeys('abc');
        $this->inputKeys("\x1b\x15");
        $this->assertAroundCursor('', 'c');
        $this->inputKeys("\x15");
        $this->assertAroundCursor('', 'c');
    }

    public function testViChangeToEol(): void
    {
        $this->inputKeys("abcdef\x1b2hC");
        $this->assertAroundCursor('abc', '');
        $this->inputKeys("\x1b0C");
        $this->assertAroundCursor('', '');
        $this->assertMode('vi_insert');
    }

    // --- Newline / EOF -----------------------------------------------------

    public function testEdNewlineWithCr(): void
    {
        $this->inputKeys('ab');
        $this->assertFalse($this->editor->finished());
        $this->inputKeys("\x0d");
        $this->assertAroundCursor('ab', '');
        $this->assertTrue($this->editor->finished());
    }

    public function testEdNewlineWithLf(): void
    {
        $this->inputKeys('ab');
        $this->inputKeys("\x0a");
        $this->assertTrue($this->editor->finished());
    }

    public function testViListOrEof(): void
    {
        $this->inputKeys("\x04");
        $this->assertNull($this->editor->line());
        $this->assertTrue($this->editor->finished());
    }

    public function testViListOrEofWithNonEmptyLine(): void
    {
        $this->inputKeys('ab');
        $this->assertFalse($this->editor->finished());
        $this->inputKeys("\x04");
        $this->assertAroundCursor('ab', '');
        $this->assertTrue($this->editor->finished());
    }

    // --- Quoted insert -----------------------------------------------------

    public function testEdQuotedInsert(): void
    {
        $this->inputKeys('ab');
        $this->inputKeyBySymbol('insert_raw_char', "\x01");
        $this->assertAroundCursor("ab\x01", '');
    }

    public function testEdQuotedInsertWithViArg(): void
    {
        $this->inputKeys("ab\x1b3");
        $this->inputKeyBySymbol('insert_raw_char', "\x01");
        $this->inputKeys('4');
        $this->inputKeyBySymbol('insert_raw_char', '1');
        $this->assertAroundCursor("a\x01\x01\x011111", 'b');
    }

    // --- Operator composition (d / c / y) ----------------------------------

    public function testViDeleteMeta(): void
    {
        $this->inputKeys("aaa bbb ccc ddd eee\x1b02w");
        $this->assertAroundCursor('aaa bbb ', 'ccc ddd eee');
        $this->inputKeys('dw');
        $this->assertAroundCursor('aaa bbb ', 'ddd eee');
        $this->inputKeys('db');
        $this->assertAroundCursor('aaa ', 'ddd eee');
    }

    public function testViDeleteMetaNothing(): void
    {
        $this->inputKeys("foo\x1b0");
        $this->assertAroundCursor('', 'foo');
        $this->inputKeys('dhp');
        $this->assertAroundCursor('', 'foo');
    }

    public function testViDeleteMetaWithViNextWordAtEol(): void
    {
        $this->inputKeys("foo bar\x1b0w");
        $this->assertAroundCursor('foo ', 'bar');
        $this->inputKeys('w');
        $this->assertAroundCursor('foo ba', 'r');
        $this->inputKeys('0dw');
        $this->assertAroundCursor('', 'bar');
        $this->inputKeys('dw');
        $this->assertAroundCursor('', '');
    }

    public function testViDeleteMetaWithViNextChar(): void
    {
        $this->inputKeys("aaa bbb ccc ___ ddd\x1b02w");
        $this->assertAroundCursor('aaa bbb ', 'ccc ___ ddd');
        $this->inputKeys('df_');
        $this->assertAroundCursor('aaa bbb ', '__ ddd');
    }

    public function testViDeleteMetaWithArg(): void
    {
        $this->inputKeys("aaa bbb ccc ddd\x1b03w");
        $this->assertAroundCursor('aaa bbb ccc ', 'ddd');
        $this->inputKeys('2dl');
        $this->assertAroundCursor('aaa bbb ccc ', 'd');
        $this->inputKeys('d2h');
        $this->assertAroundCursor('aaa bbb cc', 'd');
        $this->inputKeys('2d3h');
        $this->assertAroundCursor('aaa ', 'd');
        $this->inputKeys('dd');
        $this->assertAroundCursor('', '');
    }

    public function testViChangeMeta(): void
    {
        $this->inputKeys("aaa bbb ccc ddd eee\x1b02w");
        $this->assertAroundCursor('aaa bbb ', 'ccc ddd eee');
        $this->inputKeys('cwaiueo');
        $this->assertAroundCursor('aaa bbb aiueo', ' ddd eee');
        $this->inputKeys("\x1b");
        $this->assertAroundCursor('aaa bbb aiue', 'o ddd eee');
        $this->inputKeys('cb');
        $this->assertAroundCursor('aaa bbb ', 'o ddd eee');
    }

    public function testViChangeMetaWithViNextWord(): void
    {
        $this->inputKeys("foo  bar  baz\x1b0w");
        $this->assertAroundCursor('foo  ', 'bar  baz');
        $this->inputKeys('cwhoge');
        $this->assertAroundCursor('foo  hoge', '  baz');
        $this->inputKeys("\x1b");
        $this->assertAroundCursor('foo  hog', 'e  baz');
    }

    public function testViWaitingOperatorWithWaitingProc(): void
    {
        $this->inputKeys("foo foo foo foo foo\x1b0");
        $this->inputKeys('2d3fo');
        $this->assertAroundCursor('', ' foo foo');
        $this->inputKeys('fo');
        $this->assertAroundCursor(' f', 'oo foo');
    }

    public function testWaitingOperatorArgIncludingZero(): void
    {
        $this->inputKeys("a111111111111222222222222\x1b0");
        $this->inputKeys('10df1');
        $this->assertAroundCursor('', '11222222222222');
        $this->inputKeys('d10f2');
        $this->assertAroundCursor('', '22');
    }

    public function testViWaitingOperatorCancel(): void
    {
        $this->inputKeys("aaa bbb ccc\x1b02w");
        $this->assertAroundCursor('aaa bbb ', 'ccc');
        // dc / dy cancel delete_meta; cd / cy cancel change_meta; yd / yc cancel yank.
        $this->inputKeys('dch');
        $this->inputKeys('dyh');
        $this->inputKeys('cdh');
        $this->inputKeys('cyh');
        $this->inputKeys('ydhP');
        $this->inputKeys('ychP');
        $this->assertAroundCursor('aa', 'a bbb ccc');
    }

    public function testCancelWaitingWithSymbolKey(): void
    {
        $this->inputKeys("aaa bbb lll\x1b0");
        $this->assertAroundCursor('', 'aaa bbb lll');
        // A csi ed_next_char cancels the pending vi_next_char and just moves right.
        $this->inputKeys('f');
        $this->inputKeyBySymbol('ed_next_char', null, true);
        $this->inputKeys('l');
        $this->assertAroundCursor('aa', 'a bbb lll');
        // vi_delete_meta + csi ed_next_char deletes the character instead.
        $this->inputKeys('d');
        $this->inputKeyBySymbol('ed_next_char', null, true);
        $this->inputKeys('l');
        $this->assertAroundCursor('aa ', 'bbb lll');
    }

    public function testUnimplementedViCommandIsNoOp(): void
    {
        $this->inputKeys("abc\x1bh");
        $this->assertAroundCursor('a', 'bc');
        $this->inputKeys('@');
        $this->assertAroundCursor('a', 'bc');
    }

    public function testViYank(): void
    {
        $this->inputKeys("foo bar\x1b2h");
        $this->assertAroundCursor('foo ', 'bar');
        $this->inputKeys('y3l');
        $this->assertAroundCursor('foo ', 'bar');
        $this->inputKeys('P');
        $this->assertAroundCursor('foo ba', 'rbar');
        $this->inputKeys('3h3yhP');
        $this->assertAroundCursor('foofo', 'o barbar');
    }

    public function testViYankNothing(): void
    {
        $this->inputKeys("foo\x1b0");
        $this->assertAroundCursor('', 'foo');
        $this->inputKeys('yhp');
        $this->assertAroundCursor('', 'foo');
    }

    public function testViEndWordWithOperator(): void
    {
        $this->inputKeys("foo bar\x1b0");
        $this->assertAroundCursor('', 'foo bar');
        $this->inputKeys('de');
        $this->assertAroundCursor('', ' bar');
        $this->inputKeys('de');
        $this->assertAroundCursor('', '');
    }

    public function testViEndBigWordWithOperator(): void
    {
        $this->inputKeys("aaa   b{b}}}b\x1b0");
        $this->inputKeys('dE');
        $this->assertAroundCursor('', '   b{b}}}b');
        $this->inputKeys('dE');
        $this->assertAroundCursor('', '');
    }

    public function testViNextCharWithOperator(): void
    {
        $this->inputKeys("foo bar\x1b0");
        $this->assertAroundCursor('', 'foo bar');
        $this->inputKeys('df ');
        $this->assertAroundCursor('', 'bar');
    }

    public function testViMotionOperators(): void
    {
        // Regression guard: this sequence used to raise; assert it lands clean.
        $this->inputKeys("test = { foo: bar }\x1bBBBldt}b");
        $this->assertMode('vi_command');
    }

    // --- Completion journey via vi_insert ^N / ^P --------------------------

    public function testCompletionJourney(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => ['foo_bar', 'foo_bar_baz']);
        $this->inputKeys('foo');
        $this->assertAroundCursor('foo', '');
        $this->inputKeys("\x0e"); // ^N menu_complete
        $this->assertAroundCursor('foo_bar', '');
        $this->inputKeys("\x0e");
        $this->assertAroundCursor('foo_bar_baz', '');
        $this->inputKeys("\x0e");
        $this->assertAroundCursor('foo', '');
    }

    public function testCompletionJourneyReverse(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => ['foo_bar', 'foo_bar_baz']);
        $this->inputKeys('foo');
        $this->inputKeys("\x10"); // ^P menu_complete_backward
        $this->assertAroundCursor('foo_bar_baz', '');
        $this->inputKeys("\x10");
        $this->assertAroundCursor('foo_bar', '');
    }
}
