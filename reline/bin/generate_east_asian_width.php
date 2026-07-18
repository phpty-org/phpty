<?php

declare(strict_types=1);

/*
 * Regenerates reline/src/Unicode/EastAsianWidth.php from upstream's Ruby table.
 *
 * Upstream generates lib/reline/unicode/east_asian_width.rb from the raw
 * EastAsianWidth.txt (bin/generate_east_asian_width in the submodule). We do NOT
 * re-derive from EastAsianWidth.txt here: that would fork the width policy
 * (Mn/Me -> 0, Hangul V/T -> 0, the -1 ambiguous marker) away from upstream's
 * and break follow-the-diff. Instead we transcribe the already-generated Ruby
 * literal verbatim, so the widths are upstream's by construction and a diff of
 * their table maps one-to-one onto a diff of ours.
 *
 * Usage: php reline/bin/generate_east_asian_width.php
 */

$root = dirname(__DIR__, 2);
$source = $root . '/references/reline/lib/reline/unicode/east_asian_width.rb';
$target = dirname(__DIR__) . '/src/Unicode/EastAsianWidth.php';

$ruby = file_get_contents($source);
if ($ruby === false) {
    fwrite(STDERR, "Cannot read {$source}\n");
    exit(1);
}

if (!preg_match("/UNICODE_VERSION = '([^']+)'/", $ruby, $m)) {
    fwrite(STDERR, "Cannot find UNICODE_VERSION in upstream table\n");
    exit(1);
}
$unicodeVersion = $m[1];

// Each pair is `[0xHHHH, W]` where W is 2, 1, 0 or -1 (ambiguous).
if (!preg_match_all('/\[0x([0-9a-f]+),\s*(-?\d+)\]/', $ruby, $pairs, PREG_SET_ORDER)) {
    fwrite(STDERR, "No table entries matched\n");
    exit(1);
}

$last = [];
$width = [];
foreach ($pairs as $pair) {
    $last[] = hexdec($pair[1]);
    $width[] = (int) $pair[2];
}

$commit = trim((string) shell_exec(
    'git -C ' . escapeshellarg(dirname($source)) . ' rev-parse --short HEAD 2>/dev/null'
));
if ($commit === '') {
    $commit = 'edf8d6b';
}

$formatList = static function (array $values, callable $fmt): string {
    $lines = [];
    $chunk = [];
    foreach ($values as $value) {
        $chunk[] = $fmt($value);
        if (count($chunk) === 8) {
            $lines[] = '        ' . implode(', ', $chunk) . ',';
            $chunk = [];
        }
    }
    if ($chunk !== []) {
        $lines[] = '        ' . implode(', ', $chunk) . ',';
    }
    $body = implode("\n", $lines);
    // Drop the trailing comma of the whole list for a clean literal.
    return preg_replace('/,\n?$/', "\n", $body);
};

$lastBody = $formatList($last, static fn (int $v): string => '0x' . dechex($v));
$widthBody = $formatList($width, static fn (int $v): string => (string) $v);

$count = count($last);

$php = <<<PHP
<?php

declare(strict_types=1);

namespace PhPty\\Reline\\Unicode;

/**
 * Generated East Asian Width lookup table. DO NOT EDIT BY HAND.
 *
 * Source:    references/reline/lib/reline/unicode/east_asian_width.rb
 * Submodule: reline gem 0.6.3, commit {$commit}
 * Unicode:   {$unicodeVersion}
 * Generator: reline/bin/generate_east_asian_width.php
 *
 * The two parallel arrays mirror upstream's `CHUNK_LAST, CHUNK_WIDTH` pair (a
 * transposed list of [upper_bound, width] chunks). CHUNK_LAST[i] is the last
 * codepoint of chunk i; CHUNK_WIDTH[i] its display width, where -1 marks an
 * ambiguous-width chunk to be resolved at runtime (see Unicode::eastAsianWidth).
 * Keeping the chunk/binary-search shape identical to upstream is what lets a
 * regenerated diff track theirs. There are {$count} chunks.
 */
final class EastAsianWidth
{
    /** @var list<int> */
    public const CHUNK_LAST = [
{$lastBody}    ];

    /** @var list<int> */
    public const CHUNK_WIDTH = [
{$widthBody}    ];
}

PHP;

file_put_contents($target, $php);
fwrite(STDERR, "Wrote {$count} chunks to {$target}\n");
