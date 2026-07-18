<?php

declare(strict_types=1);

namespace PhPty\Reline;

use PhPty\Reline\Unicode\EastAsianWidth;

/**
 * Grapheme width, word-boundary scanning and escape-aware measuring, ported from
 * lib/reline/unicode.rb. Pure functions only; no instance state.
 *
 * Fidelity notes (ADR-0005), the places a Ruby idiom had to be mapped:
 *
 * - Ruby's `String#grapheme_clusters` becomes a PCRE `\X` scan (the `u` flag).
 *   PCRE2's grapheme matching follows UAX #29, the same standard upstream's
 *   engine does, so ZWJ emoji, Hangul jamo, keycaps and combining marks cluster
 *   the same way. No ext-intl / grapheme_* dependency is taken.
 * - The WIDTH_SCANNER (`\G`-anchored alternation over non-printing brackets,
 *   CSI, OSC and one grapheme) becomes scan(): a manual offset walk that yields
 *   the same token stream, so the callers stay a line-for-line transcription.
 * - Ambiguous width (-1 in the table) resolves through ambiguousWidth(). Upstream
 *   reads `Reline.ambiguous_width`, measured once at startup against the tty.
 *   Tier 0 has no Core to own that probe, so it is a static default of 1 here;
 *   the Core, when it lands, will set it via setAmbiguousWidth().
 * - Method names are upstream's, kept snake_case (em_forward_word, vi_yank …).
 *
 * Non-UTF-8 encodings (upstream's SJIS handling in safe_encode and the encoded
 * variants of the word tests) are out of tier-0 scope; the port operates on
 * UTF-8, consistent with the unix-first milestone.
 */
final class Unicode
{
    /** @var array<int, string> */
    public const ESCAPED_PAIRS = [
        0x00 => '^@',
        0x01 => '^A', // C-a
        0x02 => '^B',
        0x03 => '^C',
        0x04 => '^D',
        0x05 => '^E',
        0x06 => '^F',
        0x07 => '^G',
        0x08 => '^H', // Backspace
        0x09 => '^I',
        0x0A => '^J',
        0x0B => '^K',
        0x0C => '^L',
        0x0D => '^M', // Enter
        0x0E => '^N',
        0x0F => '^O',
        0x10 => '^P',
        0x11 => '^Q',
        0x12 => '^R',
        0x13 => '^S',
        0x14 => '^T',
        0x15 => '^U',
        0x16 => '^V',
        0x17 => '^W',
        0x18 => '^X',
        0x19 => '^Y',
        0x1A => '^Z', // C-z
        0x1B => '^[', // C-[ C-3
        0x1C => '^\\', // C-\
        0x1D => '^]', // C-]
        0x1E => '^^', // C-~ C-6
        0x1F => '^_', // C-_ C-7
        0x7F => '^?', // C-? C-8
    ];

    public const NON_PRINTING_START = "\1";
    public const NON_PRINTING_END = "\2";
    public const CSI_REGEXP = '/\e\[[\x30-\x3f]*[\x20-\x2f]*[a-zA-Z]/';
    public const OSC_REGEXP = '/\e\]\d+(?:;[^;\a\e]+)*(?:\a|\e\\\\)/';

    // Anchored (`\G`-equivalent) variants for the offset walk in scan().
    private const CSI_ANCHORED = '/\e\[[\x30-\x3f]*[\x20-\x2f]*[a-zA-Z]/A';
    private const OSC_ANCHORED = '/\e\]\d+(?:;[^;\a\e]+)*(?:\a|\e\\\\)/A';
    private const GRAPHEME_ANCHORED = '/\X/uA';
    private const GRAPHEME_ALL = '/\X/u';

    private static int $ambiguousWidth = 1;

    /**
     * Width of an East Asian "ambiguous" character. Defaults to 1; the Core will
     * set it once it can probe the tty (see the class note).
     */
    public static function ambiguousWidth(): int
    {
        return self::$ambiguousWidth;
    }

    public static function setAmbiguousWidth(int $width): void
    {
        self::$ambiguousWidth = $width;
    }

    public static function escape_for_print(string $str): string
    {
        $result = '';
        foreach (self::chars($str) as $gr) {
            if ($gr === "\n") {
                $result .= $gr;
            } elseif ($gr === "\t") {
                $result .= '  ';
            } else {
                $result .= self::ESCAPED_PAIRS[self::ord($gr)] ?? $gr;
            }
        }

        return $result;
    }

    /**
     * Replace bytes that are not valid in the target encoding. Tier 0 supports a
     * UTF-8 target only (see the class note); invalid bytes become U+FFFD.
     */
    public static function safe_encode(string $str, string $encoding): string
    {
        if ($encoding === '' || strtoupper($encoding) === 'UTF-8' || strtoupper($encoding) === 'UTF8') {
            $previous = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            try {
                return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
            } finally {
                mb_substitute_character($previous);
            }
        }

        throw new \RuntimeException("safe_encode to {$encoding} is not supported until non-UTF-8 encodings are ported.");
    }

    public static function east_asian_width(int $ord): int
    {
        $last = EastAsianWidth::CHUNK_LAST;
        $lo = 0;
        $hi = count($last) - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($ord <= $last[$mid]) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }
        $size = EastAsianWidth::CHUNK_WIDTH[$lo];

        return $size === -1 ? self::ambiguousWidth() : $size;
    }

    public static function get_mbchar_width(string $mbchar): int
    {
        $ord = self::ord($mbchar);
        if ($ord <= 0x1F) { // in ESCAPED_PAIRS
            return 2;
        }
        if (mb_strlen($mbchar, 'UTF-8') === 1 && $ord <= 0x7E) { // printable ASCII chars
            return 1;
        }

        $zwj = false;
        $width = 0;
        foreach (self::codepoints($mbchar) as $cp) {
            if ($zwj) {
                $zwj = false;
            } elseif ($cp === 0x200D) { // Zero Width Joiner
                $zwj = true;
            } else {
                $width += self::east_asian_width($cp);
            }
        }

        return $width;
    }

    public static function calculate_width(string $str, bool $allow_escape_code = false): int
    {
        if ($allow_escape_code) {
            $width = 0;
            $inZeroWidth = false;
            foreach (self::scan($str) as [$type, $text]) {
                switch ($type) {
                    case 'nps':
                        $inZeroWidth = true;
                        break;
                    case 'npe':
                        $inZeroWidth = false;
                        break;
                    case 'csi':
                    case 'osc':
                        break;
                    case 'gc':
                        if (!$inZeroWidth) {
                            $width += self::get_mbchar_width($text);
                        }
                        break;
                }
            }

            return $width;
        }

        $width = 0;
        foreach (self::graphemeClusters($str) as $gc) {
            $width += self::get_mbchar_width($gc);
        }

        return $width;
    }

    /**
     * Used by IRB.
     *
     * @return array{0: list<string>, 1: int}
     */
    public static function split_by_width(string $str, int $max_width): array
    {
        $lines = self::split_line_by_width($str, $max_width);

        return [$lines, count($lines)];
    }

    /**
     * @return list<string>
     */
    public static function split_line_by_width(string $str, int $max_width, int $offset = 0): array
    {
        $lines = [''];
        $width = $offset;
        $inZeroWidth = false;
        $seq = '';
        foreach (self::scan($str) as [$type, $text]) {
            switch ($type) {
                case 'nps':
                    $inZeroWidth = true;
                    break;
                case 'npe':
                    $inZeroWidth = false;
                    break;
                case 'csi':
                    $lines[count($lines) - 1] .= $text;
                    if (!$inZeroWidth) {
                        if ($text === "\e[m" || $text === "\e[0m") {
                            $seq = '';
                        } else {
                            $seq .= $text;
                        }
                    }
                    break;
                case 'osc':
                    $lines[count($lines) - 1] .= $text;
                    if (!$inZeroWidth) {
                        $seq .= $text;
                    }
                    break;
                case 'gc':
                    if (!$inZeroWidth) {
                        $mbcharWidth = self::get_mbchar_width($text);
                        $width += $mbcharWidth;
                        if ($width > $max_width) {
                            $width = $mbcharWidth;
                            $lines[] = $seq;
                        }
                    }
                    $lines[count($lines) - 1] .= $text;
                    break;
            }
        }
        // The cursor moves to the next line first.
        if ($width === $max_width) {
            $lines[] = '';
        }

        return $lines;
    }

    public static function strip_non_printing_start_end(string $prompt): string
    {
        return preg_replace('/\x01([^\x02]*)(?:\x02|\z)/', '$1', $prompt);
    }

    /** Take a chunk of a string cut by width, escape sequences preserved. */
    public static function take_range(string $str, int $start_col, int $max_width): string
    {
        return self::take_mbchar_range($str, $start_col, $max_width)[0];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    public static function take_mbchar_range(
        string $str,
        int $start_col,
        int $width,
        bool $cover_begin = false,
        bool $cover_end = false,
        bool $padding = false
    ): array {
        $chunk = '';
        $endCol = $start_col + $width;
        $totalWidth = 0;
        $inZeroWidth = false;
        $chunkStartCol = null;
        $chunkEndCol = null;
        $hasCsi = false;
        foreach (self::scan($str) as [$type, $text]) {
            switch ($type) {
                case 'nps':
                    $inZeroWidth = true;
                    break;
                case 'npe':
                    $inZeroWidth = false;
                    break;
                case 'csi':
                    $hasCsi = true;
                    $chunk .= $text;
                    break;
                case 'osc':
                    $chunk .= $text;
                    break;
                case 'gc':
                    if ($inZeroWidth) {
                        $chunk .= $text;
                        break;
                    }

                    $mbcharWidth = self::get_mbchar_width($text);
                    $prevWidth = $totalWidth;
                    $totalWidth += $mbcharWidth;

                    if (($cover_begin || $padding) ? $totalWidth <= $start_col : $prevWidth < $start_col) {
                        // Current character has not reached start_col yet.
                        break;
                    }
                    if ($padding && !$cover_begin && $prevWidth < $start_col && $start_col < $totalWidth) {
                        // Preceding padding, which might carry a background colour.
                        $chunk .= str_repeat(' ', $totalWidth - $start_col);
                        $chunkStartCol ??= $start_col;
                        $chunkEndCol = $totalWidth;
                        break;
                    }
                    if ($cover_end ? $prevWidth < $endCol : $totalWidth <= $endCol) {
                        // Current character is in range.
                        $chunk .= $text;
                        $chunkStartCol ??= $prevWidth;
                        $chunkEndCol = $totalWidth;
                        if ($totalWidth >= $endCol) {
                            break 2;
                        }
                        break;
                    }
                    // Current character exceeds end_col.
                    if ($padding && $endCol < $totalWidth) {
                        // Succeeding padding, which might carry a background colour.
                        $chunk .= str_repeat(' ', $endCol - $prevWidth);
                        $chunkStartCol ??= $prevWidth;
                        $chunkEndCol = $endCol;
                    }
                    break 2;
            }
        }
        $chunkStartCol ??= $start_col;
        $chunkEndCol ??= $start_col;
        if ($padding && $chunkEndCol < $endCol) {
            // Trailing padding, which should NOT include a background colour.
            if ($hasCsi) {
                $chunk .= "\e[0m";
            }
            $chunk .= str_repeat(' ', $endCol - $chunkEndCol);
            $chunkEndCol = $endCol;
        }

        return [$chunk, $chunkStartCol, $chunkEndCol - $chunkStartCol];
    }

    public static function get_next_mbchar_size(string $line, int $byte_pointer): int
    {
        $grapheme = self::graphemeClusters(substr($line, $byte_pointer))[0] ?? null;

        return $grapheme !== null ? strlen($grapheme) : 0;
    }

    public static function get_prev_mbchar_size(string $line, int $byte_pointer): int
    {
        if ($byte_pointer === 0) {
            return 0;
        }
        $clusters = self::graphemeClusters(substr($line, 0, $byte_pointer));
        $grapheme = $clusters === [] ? null : $clusters[count($clusters) - 1];

        return $grapheme !== null ? strlen($grapheme) : 0;
    }

    public static function em_forward_word(string $line, int $byte_pointer): int
    {
        $gcs = self::graphemeClusters(substr($line, $byte_pointer));
        $nonwords = self::takeWhile($gcs, static fn (string $c): bool => !self::word_character($c));
        $words = self::takeWhile(array_slice($gcs, count($nonwords)), static fn (string $c): bool => self::word_character($c));

        return self::byteLength($nonwords) + self::byteLength($words);
    }

    /**
     * @return array{0: int, 1: string}
     */
    public static function em_forward_word_with_capitalization(string $line, int $byte_pointer): array
    {
        $gcs = self::graphemeClusters(substr($line, $byte_pointer));
        $nonwords = self::takeWhile($gcs, static fn (string $c): bool => !self::word_character($c));
        $words = self::takeWhile(array_slice($gcs, count($nonwords)), static fn (string $c): bool => self::word_character($c));

        return [
            self::byteLength($nonwords) + self::byteLength($words),
            implode('', $nonwords) . self::capitalize(implode('', $words)),
        ];
    }

    public static function em_backward_word(string $line, int $byte_pointer): int
    {
        $gcs = array_reverse(self::graphemeClusters(substr($line, 0, $byte_pointer)));
        $nonwords = self::takeWhile($gcs, static fn (string $c): bool => !self::word_character($c));
        $words = self::takeWhile(array_slice($gcs, count($nonwords)), static fn (string $c): bool => self::word_character($c));

        return self::byteLength($nonwords) + self::byteLength($words);
    }

    public static function em_big_backward_word(string $line, int $byte_pointer): int
    {
        $gcs = array_reverse(self::graphemeClusters(substr($line, 0, $byte_pointer)));
        $spaces = self::takeWhile($gcs, static fn (string $c): bool => self::space_character($c));
        $nonspaces = self::takeWhile(array_slice($gcs, count($spaces)), static fn (string $c): bool => !self::space_character($c));

        return self::byteLength($spaces) + self::byteLength($nonspaces);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function ed_transpose_words(string $line, int $byte_pointer): array
    {
        $gcs = self::graphemeClusters(substr($line, 0, $byte_pointer));
        $pos = count($gcs);
        $gcs = array_merge($gcs, self::graphemeClusters(substr($line, $byte_pointer)));
        $total = count($gcs);

        while ($pos < $total && !self::word_character($gcs[$pos])) {
            $pos++;
        }
        if ($pos === $total) { // 'aaa  bbb [cursor] '
            while ($pos > 0 && !self::word_character($gcs[$pos - 1])) {
                $pos--;
            }
            $secondWordEnd = $total;
        } else { // 'aaa  [cursor]bbb'
            while ($pos < $total && self::word_character($gcs[$pos])) {
                $pos++;
            }
            $secondWordEnd = $pos;
        }
        while ($pos > 0 && self::word_character($gcs[$pos - 1])) {
            $pos--;
        }
        $secondWordStart = $pos;
        while ($pos > 0 && !self::word_character($gcs[$pos - 1])) {
            $pos--;
        }
        $firstWordEnd = $pos;
        while ($pos > 0 && self::word_character($gcs[$pos - 1])) {
            $pos--;
        }
        $firstWordStart = $pos;

        return [
            self::byteLength(array_slice($gcs, 0, $firstWordStart)),
            self::byteLength(array_slice($gcs, 0, $firstWordEnd)),
            self::byteLength(array_slice($gcs, 0, $secondWordStart)),
            self::byteLength(array_slice($gcs, 0, $secondWordEnd)),
        ];
    }

    public static function vi_big_forward_word(string $line, int $byte_pointer): int
    {
        $gcs = self::graphemeClusters(substr($line, $byte_pointer));
        $nonspaces = self::takeWhile($gcs, static fn (string $c): bool => !self::space_character($c));
        $spaces = self::takeWhile(array_slice($gcs, count($nonspaces)), static fn (string $c): bool => self::space_character($c));

        return self::byteLength($nonspaces) + self::byteLength($spaces);
    }

    public static function vi_big_forward_end_word(string $line, int $byte_pointer): int
    {
        $gcs = self::graphemeClusters(substr($line, $byte_pointer));
        $first = array_slice($gcs, 0, 1);
        $gcs = array_slice($gcs, 1);
        $spaces = self::takeWhile($gcs, static fn (string $c): bool => self::space_character($c));
        $nonspaces = self::takeWhile(array_slice($gcs, count($spaces)), static fn (string $c): bool => !self::space_character($c));
        $matched = array_merge($spaces, $nonspaces);
        array_pop($matched);

        return self::byteLength($first) + self::byteLength($matched);
    }

    public static function vi_big_backward_word(string $line, int $byte_pointer): int
    {
        $gcs = array_reverse(self::graphemeClusters(substr($line, 0, $byte_pointer)));
        $spaces = self::takeWhile($gcs, static fn (string $c): bool => self::space_character($c));
        $nonspaces = self::takeWhile(array_slice($gcs, count($spaces)), static fn (string $c): bool => !self::space_character($c));

        return self::byteLength($spaces) + self::byteLength($nonspaces);
    }

    public static function vi_forward_word(string $line, int $byte_pointer, bool $drop_terminate_spaces = false): int
    {
        $gcs = self::graphemeClusters(substr($line, $byte_pointer));
        if ($gcs === []) {
            return 0;
        }

        $c = $gcs[0];
        if (self::word_character($c)) {
            $matched = self::takeWhile($gcs, static fn (string $x): bool => self::word_character($x));
        } elseif (self::space_character($c)) {
            $matched = self::takeWhile($gcs, static fn (string $x): bool => self::space_character($x));
        } else {
            $matched = self::takeWhile($gcs, static fn (string $x): bool => !self::word_character($x) && !self::space_character($x));
        }

        if ($drop_terminate_spaces) {
            return self::byteLength($matched);
        }

        $spaces = self::takeWhile(array_slice($gcs, count($matched)), static fn (string $x): bool => self::space_character($x));

        return self::byteLength($matched) + self::byteLength($spaces);
    }

    public static function vi_forward_end_word(string $line, int $byte_pointer): int
    {
        $gcs = self::graphemeClusters(substr($line, $byte_pointer));
        if ($gcs === []) {
            return 0;
        }
        if (count($gcs) === 1) {
            return strlen($gcs[0]);
        }

        $start = $gcs[0];
        $gcs = array_slice($gcs, 1);
        $skips = [$start];
        if (self::space_character($start) || self::space_character($gcs[0])) {
            $spaces = self::takeWhile($gcs, static fn (string $c): bool => self::space_character($c));
            $skips = array_merge($skips, $spaces);
            $gcs = array_slice($gcs, count($spaces));
        }
        $startWithWord = self::word_character($gcs[0]);
        $matched = self::takeWhile($gcs, static fn (string $c): bool => $startWithWord
            ? self::word_character($c)
            : (!self::word_character($c) && !self::space_character($c)));
        array_pop($matched);

        return self::byteLength($skips) + self::byteLength($matched);
    }

    public static function vi_backward_word(string $line, int $byte_pointer): int
    {
        $gcs = array_reverse(self::graphemeClusters(substr($line, 0, $byte_pointer)));
        $spaces = self::takeWhile($gcs, static fn (string $c): bool => self::space_character($c));
        $gcs = array_slice($gcs, count($spaces));
        $startWithWord = self::word_character($gcs[0] ?? null);
        $matched = self::takeWhile($gcs, static fn (string $c): bool => $startWithWord
            ? self::word_character($c)
            : (!self::word_character($c) && !self::space_character($c)));

        return self::byteLength($spaces) + self::byteLength($matched);
    }

    /**
     * @param list<string> $list
     */
    public static function common_prefix(array $list, bool $ignore_case = false): string
    {
        if ($list === []) {
            return '';
        }

        $common = self::graphemeClusters($list[0]);
        foreach ($list as $item) {
            $gcs = self::graphemeClusters($item);
            $n = 0;
            $limit = count($common);
            while ($n < $limit && isset($gcs[$n])) {
                if ($ignore_case) {
                    $equal = mb_strtolower($common[$n], 'UTF-8') === mb_strtolower($gcs[$n], 'UTF-8');
                } else {
                    $equal = $common[$n] === $gcs[$n];
                }
                if (!$equal) {
                    break;
                }
                $n++;
            }
            $common = array_slice($common, 0, $n);
        }

        return implode('', $common);
    }

    public static function vi_first_print(string $line): int
    {
        $gcs = self::graphemeClusters($line);
        $spaces = self::takeWhile($gcs, static fn (string $c): bool => self::space_character($c));

        return self::byteLength($spaces);
    }

    public static function word_character(?string $s): bool
    {
        if ($s === null) {
            return false;
        }

        return preg_match('/[\p{L}\p{M}\p{N}\p{Pc}\x{200C}\x{200D}]/u', $s) === 1;
    }

    public static function space_character(?string $s): bool
    {
        if ($s === null) {
            return false;
        }

        return preg_match('/\s/', $s) === 1;
    }

    /**
     * Ruby String#capitalize: first character upper, the rest lower.
     */
    private static function capitalize(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $chars = self::chars($s);
        $head = array_shift($chars);

        return mb_strtoupper($head, 'UTF-8') . mb_strtolower(implode('', $chars), 'UTF-8');
    }

    /**
     * The WIDTH_SCANNER token stream: non-printing brackets, CSI, OSC and one
     * grapheme cluster each, in that precedence, tiling the whole string.
     *
     * @return list<array{0: string, 1: string}> pairs of [type, text] where type
     *   is one of nps, npe, csi, osc, gc
     */
    private static function scan(string $str): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($str);
        while ($offset < $length) {
            $byte = $str[$offset];
            if ($byte === self::NON_PRINTING_START) {
                $tokens[] = ['nps', $byte];
                $offset++;
                continue;
            }
            if ($byte === self::NON_PRINTING_END) {
                $tokens[] = ['npe', $byte];
                $offset++;
                continue;
            }
            if (preg_match(self::CSI_ANCHORED, $str, $m, 0, $offset) === 1) {
                $tokens[] = ['csi', $m[0]];
                $offset += strlen($m[0]);
                continue;
            }
            if (preg_match(self::OSC_ANCHORED, $str, $m, 0, $offset) === 1) {
                $tokens[] = ['osc', $m[0]];
                $offset += strlen($m[0]);
                continue;
            }
            if (preg_match(self::GRAPHEME_ANCHORED, $str, $m, 0, $offset) === 1 && $m[0] !== '') {
                $tokens[] = ['gc', $m[0]];
                $offset += strlen($m[0]);
                continue;
            }
            // A lone byte \X could not cluster (invalid UTF-8): consume one byte
            // as its own cluster so the walk always terminates.
            $tokens[] = ['gc', $byte];
            $offset++;
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    private static function graphemeClusters(string $str): array
    {
        if ($str === '') {
            return [];
        }
        preg_match_all(self::GRAPHEME_ALL, $str, $m);

        return $m[0];
    }

    /**
     * Codepoint-wise split (Ruby String#chars).
     *
     * @return list<string>
     */
    private static function chars(string $str): array
    {
        if ($str === '') {
            return [];
        }

        return preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @return list<int>
     */
    private static function codepoints(string $str): array
    {
        return array_map(static fn (string $c): int => self::ord($c), self::chars($str));
    }

    private static function ord(string $mbchar): int
    {
        $ord = mb_ord($mbchar, 'UTF-8');

        return $ord === false ? 0 : $ord;
    }

    /**
     * @param list<string> $gcs
     */
    private static function byteLength(array $gcs): int
    {
        $total = 0;
        foreach ($gcs as $gc) {
            $total += strlen($gc);
        }

        return $total;
    }

    /**
     * @param list<string> $gcs
     * @param callable(string): bool $predicate
     * @return list<string>
     */
    private static function takeWhile(array $gcs, callable $predicate): array
    {
        $out = [];
        foreach ($gcs as $gc) {
            if (!$predicate($gc)) {
                break;
            }
            $out[] = $gc;
        }

        return $out;
    }
}
