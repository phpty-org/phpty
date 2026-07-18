<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Unicode;
use PhPty\Reline\Unicode\EastAsianWidth;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Integrity checks on the generated East Asian Width table and the binary search
 * that reads it. Not an upstream port: the Ruby table is exercised only through
 * the width tests. These guard the generation step itself.
 */
final class EastAsianWidthTest extends TestCase
{
    protected function set_up(): void
    {
        Unicode::setAmbiguousWidth(1);
    }

    public function testTableIsWellFormed(): void
    {
        $last = EastAsianWidth::CHUNK_LAST;
        $width = EastAsianWidth::CHUNK_WIDTH;

        $this->assertSame(count($last), count($width), 'the two parallel arrays must line up');
        $this->assertSame(0x7FFFFFFF, $last[count($last) - 1], 'the final chunk covers everything above');

        $previous = -1;
        foreach ($last as $bound) {
            $this->assertGreaterThan($previous, $bound, 'chunk bounds are strictly ascending for binary search');
            $previous = $bound;
        }
        foreach ($width as $w) {
            $this->assertContains($w, [-1, 0, 1, 2], 'widths are only ambiguous(-1), 0, 1 or 2');
        }
    }

    public function testKnownCodepointWidths(): void
    {
        $this->assertSame(1, Unicode::east_asian_width(0x20)); // SPACE
        $this->assertSame(1, Unicode::east_asian_width(0x41)); // 'A'
        $this->assertSame(2, Unicode::east_asian_width(0x5168)); // 全 (wide)
        $this->assertSame(2, Unicode::east_asian_width(0x3042)); // あ (wide)
        $this->assertSame(0, Unicode::east_asian_width(0x0301)); // combining acute (nonspacing mark)
    }

    public function testAmbiguousWidthResolvesThroughSetting(): void
    {
        // → (U+2192) is East Asian Ambiguous.
        Unicode::setAmbiguousWidth(1);
        $this->assertSame(1, Unicode::east_asian_width(0x2192));
        Unicode::setAmbiguousWidth(2);
        $this->assertSame(2, Unicode::east_asian_width(0x2192));
    }

    public function testZwjEmojiSequenceWidth(): void
    {
        // A ZWJ family sequence collapses to a single wide cell.
        $this->assertSame(2, Unicode::get_mbchar_width("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"));
    }
}
