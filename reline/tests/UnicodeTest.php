<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Unicode;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Ported from test/reline/test_unicode.rb. Method names and data track upstream
 * so a diff of their suite maps onto ours (ADR-0005).
 *
 * Two scope deviations, both consistent with the unix/UTF-8-first milestone and
 * recorded in CONTEXT.md:
 *  - The `.encode('sjis')` variants of the word-scanning assertions are omitted;
 *    the port measures UTF-8 and has no non-UTF-8 encoding yet.
 *  - test_encoding_conversion (safe_encode across SJIS) is not ported for the
 *    same reason.
 * Where upstream normalises with `.unicode_normalize(:nfd)` the decomposed form
 * is written out with explicit codepoint escapes, so no ext-intl Normalizer is
 * pulled in.
 */
final class UnicodeTest extends TestCase
{
    protected function set_up(): void
    {
        Unicode::setAmbiguousWidth(1);
    }

    public function testGetMbcharWidth(): void
    {
        $this->assertSame(Unicode::ambiguousWidth(), Unicode::get_mbchar_width("\u{00E9}"));
    }

    public function testAmbiguousWidth(): void
    {
        $this->assertSame(1, Unicode::calculate_width('√', true));
    }

    public function testCsiRegexp(): void
    {
        $csiSequences = ["\e[m", "\e[1m", "\e[12;34m", "\e[12;34H", "\e[5 q", "\e[?2004h"];
        $subject = 'text' . implode('text', $csiSequences) . 'text';
        preg_match_all(Unicode::CSI_REGEXP, $subject, $m);
        $this->assertSame($csiSequences, $m[0]);
    }

    public function testOscRegexp(): void
    {
        $oscSequences = ["\e]1\x07", "\e]0;OSC\x07", "\e]1\e\\", "\e]0;OSC\e\\"];
        $separator = "text\x07text";
        $subject = $separator . implode($separator, $oscSequences) . $separator;
        preg_match_all(Unicode::OSC_REGEXP, $subject, $m);
        $this->assertSame($oscSequences, $m[0]);
    }

    public function testSplitByWidth(): void
    {
        // IRB uses this method.
        $this->assertSame([['abc', 'de'], 2], Unicode::split_by_width('abcde', 3));
    }

    public function testSplitLineByWidth(): void
    {
        $this->assertSame(['abc', 'de'], Unicode::split_line_by_width('abcde', 3));
        $this->assertSame(['abc', 'def', ''], Unicode::split_line_by_width('abcdef', 3));
        $this->assertSame(['ab', 'あd', 'ef'], Unicode::split_line_by_width('abあdef', 3));
        $this->assertSame(['ab[zero]c', 'def', ''], Unicode::split_line_by_width("ab\1[zero]\2cdef", 3));
        $this->assertSame(["\e[31mabc", "\e[31md\e[42mef", "\e[31m\e[42mg"], Unicode::split_line_by_width("\e[31mabcd\e[42mefg", 3));
        $this->assertSame(["ab\e]0;1\x07c", "\e]0;1\x07d"], Unicode::split_line_by_width("ab\e]0;1\x07cd", 3));
    }

    public function testSplitLineByWidthEdgeCases(): void
    {
        // Empty input still yields one (empty) line — the seed line.
        $this->assertSame([''], Unicode::split_line_by_width('', 3));
        // Exact fit: the trailing "" marks the cursor moving to the next line.
        $this->assertSame(['abc', ''], Unicode::split_line_by_width('abc', 3));
        // Under the limit: a single line, no wrap.
        $this->assertSame(['ab'], Unicode::split_line_by_width('ab', 3));
        // A wide char refuses to straddle the boundary — it moves whole to the
        // next line rather than tearing across it (日 would land at cols 3-4).
        $this->assertSame(['abc', '日e'], Unicode::split_line_by_width('abc日e', 4));
        $this->assertSame(['ab', '日'], Unicode::split_line_by_width('ab日', 3));
        // A non-zero offset counts against the first line's budget.
        $this->assertSame(['a', 'bcd', 'e'], Unicode::split_line_by_width('abcde', 3, 2));
    }

    public function testSplitLineByWidthCsiResetSgrOptimization(): void
    {
        $this->assertSame(["\e[1ma\e[mb\e[2mc", "\e[2md\e[0me\e[3mf", "\e[3mg"], Unicode::split_line_by_width("\e[1ma\e[mb\e[2mcd\e[0me\e[3mfg", 3));
        $this->assertSame(["\e[1ma\e[mzero\e[0m\e[2mb", "\e[1m\e[2mc"], Unicode::split_line_by_width("\e[1ma\1\e[mzero\e[0m\2\e[2mbc", 2));
    }

    public function testTakeRange(): void
    {
        $this->assertSame('cdef', Unicode::take_range('abcdefghi', 2, 4));
        $this->assertSame('あde', Unicode::take_range('abあdef', 2, 4));
        $this->assertSame('[zero]cdef', Unicode::take_range("ab\1[zero]\2cdef", 2, 4));
        $this->assertSame('b[zero]cde', Unicode::take_range("ab\1[zero]\2cdef", 1, 4));
        $this->assertSame("\e[31mcd\e[42mef", Unicode::take_range("\e[31mabcd\e[42mefg", 2, 4));
        $this->assertSame("\e]0;1\x07cd", Unicode::take_range("ab\e]0;1\x07cd", 2, 3));
        $this->assertSame('いう', Unicode::take_range('あいうえお', 2, 4));
    }

    public function testNonprintingStartEnd(): void
    {
        // \1 and \2 should be removed
        $this->assertSame('ab[zero]cd', Unicode::take_range("ab\1[zero]\2cdef", 0, 4));
        $this->assertSame(['ab[zero]cd', 'ef'], Unicode::split_line_by_width("ab\1[zero]\2cdef", 4));
        // CSI between \1 and \2 does not need to be applied to the subsequent line
        $this->assertSame(["\e[31mab\e[32mcd", "\e[31mef"], Unicode::split_line_by_width("\e[31mab\1\e[32m\2cdef", 4));
    }

    public function testStripNonPrintingStartEnd(): void
    {
        $this->assertSame("ab[zero]cd[ze\1ro]ef[zero]", Unicode::strip_non_printing_start_end("ab\1[zero]\2cd\1[ze\1ro]\2ef\1[zero]"));
    }

    public function testCalculateWidth(): void
    {
        $this->assertSame(9, Unicode::calculate_width('abcdefghi'));
        $this->assertSame(9, Unicode::calculate_width('abcdefghi', true));
        $this->assertSame(7, Unicode::calculate_width('abあdef'));
        $this->assertSame(7, Unicode::calculate_width('abあdef', true));
        $this->assertSame(16, Unicode::calculate_width("ab\1[zero]\2cdef"));
        $this->assertSame(6, Unicode::calculate_width("ab\1[zero]\2cdef", true));
        $this->assertSame(19, Unicode::calculate_width("\e[31mabcd\e[42mefg"));
        $this->assertSame(7, Unicode::calculate_width("\e[31mabcd\e[42mefg", true));
        $this->assertSame(12, Unicode::calculate_width("ab\e]0;1\x07cd"));
        $this->assertSame(4, Unicode::calculate_width("ab\e]0;1\x07cd", true));
        $this->assertSame(10, Unicode::calculate_width('あいうえお'));
        $this->assertSame(10, Unicode::calculate_width('あいうえお', true));
    }

    public function testTakeMbcharRange(): void
    {
        $this->assertSame(['cdef', 2, 4], Unicode::take_mbchar_range('abcdefghi', 2, 4));
        $this->assertSame(['cdef', 2, 4], Unicode::take_mbchar_range('abcdefghi', 2, 4, false, false, true));
        $this->assertSame(['cdef', 2, 4], Unicode::take_mbchar_range('abcdefghi', 2, 4, true));
        $this->assertSame(['cdef', 2, 4], Unicode::take_mbchar_range('abcdefghi', 2, 4, false, true));
        $this->assertSame(['いう', 2, 4], Unicode::take_mbchar_range('あいうえお', 2, 4));
        $this->assertSame(['いう', 2, 4], Unicode::take_mbchar_range('あいうえお', 2, 4, false, false, true));
        $this->assertSame(['いう', 2, 4], Unicode::take_mbchar_range('あいうえお', 2, 4, true));
        $this->assertSame(['いう', 2, 4], Unicode::take_mbchar_range('あいうえお', 2, 4, false, true));
        $this->assertSame(['う', 4, 2], Unicode::take_mbchar_range('あいうえお', 3, 4));
        $this->assertSame([' う ', 3, 4], Unicode::take_mbchar_range('あいうえお', 3, 4, false, false, true));
        $this->assertSame(['いう', 2, 4], Unicode::take_mbchar_range('あいうえお', 3, 4, true));
        $this->assertSame(['うえ', 4, 4], Unicode::take_mbchar_range('あいうえお', 3, 4, false, true));
        $this->assertSame(['いう ', 2, 5], Unicode::take_mbchar_range('あいうえお', 3, 4, true, false, true));
        $this->assertSame([' うえ', 3, 5], Unicode::take_mbchar_range('あいうえお', 3, 4, false, true, true));
        $this->assertSame([' うえお   ', 3, 10], Unicode::take_mbchar_range('あいうえお', 3, 10, false, false, true));
        $this->assertSame([" \e[41mうえお\e[0m   ", 3, 10], Unicode::take_mbchar_range("あい\e[41mうえお", 3, 10, false, false, true));
        $this->assertSame(["\e[41m \e[42mい\e[43m ", 1, 4], Unicode::take_mbchar_range("\e[41mあ\e[42mい\e[43mう", 1, 4, false, false, true));
        $this->assertSame(["\e[31mc[ABC]d\e[0mef", 2, 4], Unicode::take_mbchar_range("\e[31mabc\1[ABC]\2d\e[0mefghi", 2, 4));
    }

    public function testThreeWidthCharactersTakeMbcharRange(): void
    {
        $halfwidthDakuten = "\u{FF9E}";
        $a = 'あ' . $halfwidthDakuten;
        $b = 'い' . $halfwidthDakuten;
        $c = 'う' . $halfwidthDakuten;
        $line = 'x' . $a . $b . $c . 'x';
        $this->assertSame(['  ' . $b . ' ', 2, 6], Unicode::take_mbchar_range($line, 2, 6, false, false, true));
        $this->assertSame([' ' . $b . '  ', 3, 6], Unicode::take_mbchar_range($line, 3, 6, false, false, true));
        $this->assertSame([$b . $c, 4, 6], Unicode::take_mbchar_range($line, 4, 6, false, false, true));
        $this->assertSame([$a . $b, 1, 6], Unicode::take_mbchar_range($line, 2, 6, true));
        $this->assertSame([$a . $b, 1, 6], Unicode::take_mbchar_range($line, 3, 6, true));
        $this->assertSame([$b . $c, 4, 6], Unicode::take_mbchar_range($line, 2, 6, false, true));
        $this->assertSame([$b . $c, 4, 6], Unicode::take_mbchar_range($line, 3, 6, false, true));
    }

    public function testCommonPrefix(): void
    {
        $this->assertSame('', Unicode::common_prefix([]));
        $this->assertSame('abc', Unicode::common_prefix(['abc']));
        $this->assertSame('12', Unicode::common_prefix(['123', '123️⃣']));
        $this->assertSame('', Unicode::common_prefix(['abc', 'xyz']));
        $this->assertSame('ab', Unicode::common_prefix(['abcd', 'abc', 'abx', 'abcd']));
        $this->assertSame('A', Unicode::common_prefix(['AbcD', 'ABC', 'AbX', 'AbCD']));
        $this->assertSame('Ab', Unicode::common_prefix(['AbcD', 'ABC', 'AbX', 'AbCD'], true));
    }

    public function testEmForwardWord(): void
    {
        $this->assertSame(12, Unicode::em_forward_word('abc---fooあbar-baz', 3));
        $this->assertSame(3, Unicode::em_forward_word('abcfoo', 3));
        $this->assertSame(3, Unicode::em_forward_word('abc---', 3));
        $this->assertSame(0, Unicode::em_forward_word('abc', 3));
    }

    public function testEmForwardWordWithCapitalization(): void
    {
        $this->assertSame([12, '---Fooあbar'], Unicode::em_forward_word_with_capitalization('abc---foOあBar-baz', 3));
        $this->assertSame([3, 'Foo'], Unicode::em_forward_word_with_capitalization('abcfOo', 3));
        $this->assertSame([3, '---'], Unicode::em_forward_word_with_capitalization('abc---', 3));
        $this->assertSame([0, ''], Unicode::em_forward_word_with_capitalization('abc', 3));
        $this->assertSame([6, "I\u{0069}\u{0307}\u{0069}\u{0307}"], Unicode::em_forward_word_with_capitalization('ıİİ', 0));
    }

    public function testEmBackwardWord(): void
    {
        $this->assertSame(12, Unicode::em_backward_word('abc foo-barあbaz--- xyz', 20));
        $this->assertSame(2, Unicode::em_backward_word('  ', 2));
        $this->assertSame(2, Unicode::em_backward_word('ab', 2));
        $this->assertSame(0, Unicode::em_backward_word('ab', 0));
    }

    public function testEmBigBackwardWord(): void
    {
        $this->assertSame(16, Unicode::em_big_backward_word('abc foo-barあbaz--- xyz', 20));
        $this->assertSame(2, Unicode::em_big_backward_word('  ', 2));
        $this->assertSame(2, Unicode::em_big_backward_word('ab', 2));
        $this->assertSame(0, Unicode::em_big_backward_word('ab', 0));
    }

    public function testEdTransposeWords(): void
    {
        // any value that does not trigger transpose
        $this->assertSame([0, 0, 0, 2], Unicode::ed_transpose_words('aa bb cc  ', 1));

        $this->assertSame([0, 2, 3, 5], Unicode::ed_transpose_words('aa bb cc  ', 2));
        $this->assertSame([0, 2, 3, 5], Unicode::ed_transpose_words('aa bb cc  ', 4));
        $this->assertSame([3, 5, 6, 8], Unicode::ed_transpose_words('aa bb cc  ', 5));
        $this->assertSame([3, 5, 6, 8], Unicode::ed_transpose_words('aa bb cc  ', 7));
        $this->assertSame([3, 5, 6, 10], Unicode::ed_transpose_words('aa bb cc  ', 8));
        $this->assertSame([3, 5, 6, 10], Unicode::ed_transpose_words('aa bb cc  ', 9));

        $texts = ['fooあ', 'barあbaz', 'aaa  -', '- -', '-  bbb'];
        [$word1, $word2, $left, $middle, $right] = $texts;
        $expected = [
            strlen($left),
            strlen($left . $word1),
            strlen($left . $word1 . $middle),
            strlen($left . $word1 . $middle . $word2),
        ];
        $line = $left . $word1 . $middle . $word2 . $right;
        $this->assertSame($expected, Unicode::ed_transpose_words($line, strlen($left) + strlen($word1)));
        $this->assertSame($expected, Unicode::ed_transpose_words($line, strlen($left) + strlen($word1) + strlen($middle)));
        $this->assertSame($expected, Unicode::ed_transpose_words($line, strlen($left) + strlen($word1) + strlen($middle) + strlen($word2) - 1));
    }

    public function testViBigForwardWord(): void
    {
        $this->assertSame(18, Unicode::vi_big_forward_word('abc---fooあbar-baz  xyz', 3));
        $this->assertSame(8, Unicode::vi_big_forward_word('abcfooあ  --', 3));
        $this->assertSame(6, Unicode::vi_big_forward_word('abcfooあ', 3));
        $this->assertSame(3, Unicode::vi_big_forward_word('abc-  ', 3));
        $this->assertSame(0, Unicode::vi_big_forward_word('abc', 3));
    }

    public function testViBigForwardEndWord(): void
    {
        $this->assertSame(4, Unicode::vi_big_forward_end_word('a  bb c', 0));
        $this->assertSame(4, Unicode::vi_big_forward_end_word('-  bb c', 0));
        $this->assertSame(1, Unicode::vi_big_forward_end_word('-a b', 0));
        $this->assertSame(1, Unicode::vi_big_forward_end_word('a- b', 0));
        $this->assertSame(1, Unicode::vi_big_forward_end_word('aa b', 0));
        $this->assertSame(3, Unicode::vi_big_forward_end_word('  aa b', 0));
        $this->assertSame(15, Unicode::vi_big_forward_end_word('abc---fooあbar-baz  xyz', 3));
        $this->assertSame(3, Unicode::vi_big_forward_end_word('abcfooあ  --', 3));
        $this->assertSame(3, Unicode::vi_big_forward_end_word('abcfooあ', 3));
        $this->assertSame(2, Unicode::vi_big_forward_end_word('abc-  ', 3));
        $this->assertSame(0, Unicode::vi_big_forward_end_word('abc', 3));
    }

    public function testViBigBackwardWord(): void
    {
        $this->assertSame(16, Unicode::vi_big_backward_word('abc foo-barあbaz--- xyz', 20));
        $this->assertSame(2, Unicode::vi_big_backward_word('  ', 2));
        $this->assertSame(2, Unicode::vi_big_backward_word('ab', 2));
        $this->assertSame(0, Unicode::vi_big_backward_word('ab', 0));
    }

    public function testViForwardWord(): void
    {
        $this->assertSame(3, Unicode::vi_forward_word('abc---fooあbar-baz', 3));
        $this->assertSame(9, Unicode::vi_forward_word('abc---fooあbar-baz', 6));
        $this->assertSame(6, Unicode::vi_forward_word('abcfooあ', 3));
        $this->assertSame(3, Unicode::vi_forward_word('abc---', 3));
        $this->assertSame(0, Unicode::vi_forward_word('abc', 3));
        $this->assertSame(2, Unicode::vi_forward_word('abc   def', 1, true));
        $this->assertSame(5, Unicode::vi_forward_word('abc   def', 1, false));
    }

    public function testViForwardEndWord(): void
    {
        $this->assertSame(2, Unicode::vi_forward_end_word('abc---fooあbar-baz', 3));
        $this->assertSame(8, Unicode::vi_forward_end_word('abc---fooあbar-baz', 6));
        $this->assertSame(3, Unicode::vi_forward_end_word('abcfooあ', 3));
        $this->assertSame(2, Unicode::vi_forward_end_word('abc---', 3));
        $this->assertSame(0, Unicode::vi_forward_end_word('abc', 3));
    }

    public function testViBackwardWord(): void
    {
        $this->assertSame(3, Unicode::vi_backward_word('abc foo-barあbaz--- xyz', 20));
        $this->assertSame(9, Unicode::vi_backward_word('abc foo-barあbaz--- xyz', 17));
        $this->assertSame(2, Unicode::vi_backward_word('  ', 2));
        $this->assertSame(2, Unicode::vi_backward_word('ab', 2));
        $this->assertSame(0, Unicode::vi_backward_word('ab', 0));
    }

    public function testViFirstPrint(): void
    {
        $this->assertSame(3, Unicode::vi_first_print('   abcdefg'));
        $this->assertSame(3, Unicode::vi_first_print('   '));
        $this->assertSame(0, Unicode::vi_first_print('abc'));
        $this->assertSame(0, Unicode::vi_first_print('あ'));
        $this->assertSame(0, Unicode::vi_first_print(''));
    }

    public function testCharacterType(): void
    {
        $this->assertTrue(Unicode::word_character('a'));
        $this->assertTrue(Unicode::word_character('あ'));
        $this->assertFalse(Unicode::word_character('-'));
        $this->assertFalse(Unicode::word_character(null));

        $this->assertTrue(Unicode::space_character(' '));
        $this->assertFalse(Unicode::space_character('あ'));
        $this->assertFalse(Unicode::space_character('-'));
        $this->assertFalse(Unicode::space_character(null));
    }

    public function testHalfwidthDakutenHandakutenCombinations(): void
    {
        $this->assertSame(1, Unicode::get_mbchar_width("\u{FF9E}"));
        $this->assertSame(1, Unicode::get_mbchar_width("\u{FF9F}"));
        $this->assertSame(2, Unicode::get_mbchar_width('ｶﾞ'));
        $this->assertSame(2, Unicode::get_mbchar_width('ﾊﾟ'));
        $this->assertSame(2, Unicode::get_mbchar_width('ｻﾞ'));
        $this->assertSame(2, Unicode::get_mbchar_width('aﾞ'));
        $this->assertSame(2, Unicode::get_mbchar_width('1ﾟ'));
        $this->assertSame(3, Unicode::get_mbchar_width('あﾞ'));
        $this->assertSame(3, Unicode::get_mbchar_width('紅ﾞ'));
    }

    public function testGraphemeClusterWidth(): void
    {
        // GB6, GB7, GB8: Hangul syllable (한 decomposed to L V T)
        $this->assertSame(2, Unicode::get_mbchar_width("\u{1112}\u{1161}\u{11AB}"));
        $this->assertSame(6, Unicode::get_mbchar_width(str_repeat("\u{1100}", 3)));

        // GB9: Char + NonspacingMark
        $this->assertSame(1, Unicode::get_mbchar_width("c\u{0327}")); // ç NFD
        $this->assertSame(2, Unicode::get_mbchar_width("\u{306F}\u{309A}")); // ぱ NFD
        $this->assertSame(1, Unicode::get_mbchar_width("c\u{0301}\u{0327}"));
        // '1' + NonspacingMark + EnclosingMark
        $this->assertSame(1, Unicode::get_mbchar_width("1\u{FE0F}\u{20E3}"));
        // Char + SpacingMark
        $this->assertSame(2, Unicode::get_mbchar_width("\u{0995}\u{09BE}"));
        $this->assertSame(5, Unicode::get_mbchar_width('ｶﾞﾟﾞﾞ'));
        // Emoji joined with ZeroWidthJoiner
        $this->assertSame(2, Unicode::get_mbchar_width("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"));
        $this->assertSame(7, Unicode::get_mbchar_width("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{FF9E}\u{FF9F}\u{FF9F}\u{FF9F}\u{FF9E}"));

        // GB9a: Char + GraphemeClusterBreak=SpacingMark
        $this->assertSame(2, Unicode::get_mbchar_width("\u{0E04}\u{0E33}"));

        // GB9c: Consonant + Linker(NonspacingMark) + Consonant
        $this->assertSame(2, Unicode::get_mbchar_width("\u{0915}\u{094D}\u{0924}"));

        // GB12, GB13: RegionalIndicator
        $this->assertSame(2, Unicode::get_mbchar_width("\u{1F1EF}\u{1F1F5}"));
    }
}
