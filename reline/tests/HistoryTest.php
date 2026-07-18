<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\History;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Ported from test/reline/test_history.rb — the size cap, dup/index semantics,
 * and push chains. Upstream's Array-subclass surface is asserted through the
 * ArrayAccess / Countable / IteratorAggregate the port exposes; the two Ruby
 * failure modes survive as \OutOfRangeException (IndexError) and \RangeException
 * (RangeError). The SJIS half of test_history_encoding_conversion is out of scope
 * (CONTEXT.md), so only the UTF-8 U+FFFD normalisation is checked.
 */
final class HistoryTest extends TestCase
{
    private function historyNew(int $historySize = 10): History
    {
        $config = new Config();
        $config->set_history_size($historySize);

        return new History($config);
    }

    /**
     * Mirror upstream's push_history: lines "1:a", "2:b", ... "N:e".
     *
     * @return array{0: History, 1: list<string>}
     */
    private function historyNewAndPush(int $num): array
    {
        $history = $this->historyNew(100);
        $lines = [];
        for ($i = 0; $i < $num; $i++) {
            $lines[] = ($i + 1) . ':' . \chr(\ord('a') + $i);
        }
        $history->push(...$lines);

        return [$history, $lines];
    }

    public function testSurfaceIsArrayLike(): void
    {
        $history = $this->historyNew();
        $this->assertInstanceOf(\ArrayAccess::class, $history);
        $this->assertInstanceOf(\Countable::class, $history);
        $this->assertInstanceOf(\IteratorAggregate::class, $history);
    }

    public function testToS(): void
    {
        $this->assertSame('HISTORY', $this->historyNew()->to_s());
    }

    public function testGet(): void
    {
        [$history, $lines] = $this->historyNewAndPush(5);
        foreach ($lines as $i => $s) {
            $this->assertSame($s, $history[$i]);
        }
    }

    public function testGetNegative(): void
    {
        [$history, $lines] = $this->historyNewAndPush(5);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame($lines[\count($lines) - $i], $history[-$i]);
        }
    }

    public function testGetOutOfRangeIndexError(): void
    {
        [$history] = $this->historyNewAndPush(5);
        foreach ([5, 6, 100, -6, -7, -100] as $i) {
            try {
                $history[$i];
                $this->fail("expected out-of-range for i={$i}");
            } catch (\OutOfRangeException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetOutOfRangeRangeError(): void
    {
        [$history] = $this->historyNewAndPush(5);
        foreach ([2147483648, -2147483649] as $i) {
            try {
                $history[$i];
                $this->fail("expected range error for i={$i}");
            } catch (\RangeException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSet(): void
    {
        [$history] = $this->historyNewAndPush(5);
        for ($i = 0; $i < 5; $i++) {
            $expected = "set: {$i}";
            $history[$i] = $expected;
            $this->assertSame($expected, $history[$i]);
        }
    }

    public function testSetOutOfRange(): void
    {
        $history = $this->historyNew();
        $this->expectException(\OutOfRangeException::class);
        $history[0] = 'set: 0';
    }

    public function testPush(): void
    {
        $history = $this->historyNew();
        for ($i = 0; $i < 5; $i++) {
            $s = (string) $i;
            $this->assertSame($history, $history->push($s));
            $this->assertSame($s, $history[$i]);
        }
        $this->assertSame(5, $history->length());
    }

    public function testPushPlural(): void
    {
        $history = $this->historyNew();
        $this->assertSame($history, $history->push('0', '1', '2', '3', '4'));
        for ($i = 0; $i <= 4; $i++) {
            $this->assertSame((string) $i, $history[$i]);
        }
        $this->assertSame(5, $history->length());

        $this->assertSame($history, $history->push('5', '6', '7', '8', '9'));
        for ($i = 5; $i <= 9; $i++) {
            $this->assertSame((string) $i, $history[$i]);
        }
        $this->assertSame(10, $history->length());
    }

    public function testPop(): void
    {
        $history = $this->historyNew();
        $this->assertNull($history->pop());

        [$history, $lines] = $this->historyNewAndPush(5);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame($lines[\count($lines) - $i], $history->pop());
            $this->assertSame(\count($lines) - $i, $history->length());
        }
        $this->assertNull($history->pop());
    }

    public function testShift(): void
    {
        $history = $this->historyNew();
        $this->assertNull($history->shift());

        [$history, $lines] = $this->historyNewAndPush(5);
        for ($i = 0; $i <= 4; $i++) {
            $this->assertSame($lines[$i], $history->shift());
            $this->assertSame(\count($lines) - ($i + 1), $history->length());
        }
        $this->assertNull($history->shift());
    }

    public function testEach(): void
    {
        [$history, $lines] = $this->historyNewAndPush(5);
        $collected = [];
        foreach ($history as $s) {
            $collected[] = $s;
        }
        $this->assertSame($lines, $collected);
    }

    public function testLengthAndCount(): void
    {
        $history = $this->historyNew();
        $this->assertSame(0, $history->length());
        $history->push('1');
        $this->assertSame(1, $history->length());
        $history->push('2', '3', '4', '5');
        $this->assertSame(5, \count($history));
        $history->clear();
        $this->assertSame(0, $history->length());
    }

    public function testEmpty(): void
    {
        $history = $this->historyNew();
        for ($n = 0; $n < 2; $n++) {
            $this->assertTrue($history->empty());
            $history->push('s');
            $this->assertFalse($history->empty());
            $history->clear();
            $this->assertTrue($history->empty());
        }
    }

    public function testDeleteAt(): void
    {
        [$history, $lines] = $this->historyNewAndPush(5);
        for ($i = 0; $i <= 4; $i++) {
            $this->assertSame($lines[$i], $history->delete_at(0));
        }
        $this->assertTrue($history->empty());

        [$history, $lines] = $this->historyNewAndPush(5);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame($lines[\count($lines) - $i], $history->delete_at(-1));
        }
        $this->assertTrue($history->empty());

        [$history, $lines] = $this->historyNewAndPush(5);
        $this->assertSame($lines[0], $history->delete_at(0));
        $this->assertSame($lines[4], $history->delete_at(3));
        $this->assertSame($lines[1], $history->delete_at(0));
        $this->assertSame($lines[3], $history->delete_at(1));
        $this->assertSame($lines[2], $history->delete_at(0));
        $this->assertTrue($history->empty());
    }

    public function testHistorySizeZeroDropsEverything(): void
    {
        $history = $this->historyNew(0);
        $this->assertSame(0, $history->size());
        $history->push('aa');
        $history->push('bb');
        $this->assertSame(0, $history->size());
        $history->push('aa', 'bb', 'cc');
        $this->assertSame(0, $history->size());
    }

    public function testHistorySizeNegativeIsUnlimited(): void
    {
        $history = $this->historyNew(-1);
        $this->assertSame(0, $history->size());
        $history->push('aa');
        $history->push('bb');
        $this->assertSame(2, $history->size());
        $history->push('aa', 'bb', 'cc');
        $this->assertSame(5, $history->size());
    }

    public function testHistorySizeCapTrimsOldest(): void
    {
        // The size cap in action: pushing past the cap shifts the oldest entries.
        $history = $this->historyNew(2);
        $history->push('a', 'b', 'c');
        $this->assertSame(['b', 'c'], $history->to_a());
        $history->push('d');
        $this->assertSame(['c', 'd'], $history->to_a());
    }

    public function testEncodingNormalisationReplacesInvalidBytes(): void
    {
        // safe_encode replaces bytes that are not valid UTF-8 with U+FFFD.
        $history = $this->historyNew();
        $history->push("a\xFFc");
        $this->assertSame("a\u{FFFD}c", $history[0]);
    }
}
