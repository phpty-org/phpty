<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\Key;
use PhPty\Reline\KeyStroke;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Ported from test/reline/test_key_stroke.rb. Method and data track upstream so
 * a diff of their suite maps onto ours. `.bytes` becomes bytesOf(); Ruby's
 * `\C-a` is "\x01" and `\e` is "\x1b".
 */
final class KeyStrokeTest extends TestCase
{
    private const ENCODING = 'UTF-8';

    /**
     * @return list<int>
     */
    private static function bytesOf(string $s): array
    {
        return $s === '' ? [] : array_values(unpack('C*', $s));
    }

    public function testMatchStatus(): void
    {
        $config = new Config();
        $bindings = [
            'a' => 'xx',
            'ab' => 'y',
            'abc' => 'z',
            'x' => 'rr',
        ];
        foreach ($bindings as $key => $func) {
            $config->add_default_key_binding(self::bytesOf($key), self::bytesOf($func));
        }
        $stroke = new KeyStroke($config, self::ENCODING);

        $this->assertSame(KeyStroke::MATCHING_MATCHED, $stroke->match_status(self::bytesOf('a')));
        $this->assertSame(KeyStroke::MATCHING_MATCHED, $stroke->match_status(self::bytesOf('ab')));
        $this->assertSame(KeyStroke::MATCHED, $stroke->match_status(self::bytesOf('abc')));
        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(self::bytesOf('abz')));
        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(self::bytesOf('abcx')));
        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(self::bytesOf('aa')));
        $this->assertSame(KeyStroke::MATCHED, $stroke->match_status(self::bytesOf('x')));
        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(self::bytesOf('xa')));
    }

    public function testMatchUnknown(): void
    {
        $config = new Config();
        $config->add_default_key_binding(self::bytesOf("\e[9abc"), 'x');
        $stroke = new KeyStroke($config, self::ENCODING);
        $sequences = [
            "\e[9abc",
            "\e[9d",
            "\e[A", // Up
            "\e[1;1R", // Cursor position report
            "\e[15~", // F5
            "\eOP", // F1
            "\e\e[A", // Option+Up
            "\eX",
            "\e\eX",
        ];
        foreach ($sequences as $seq) {
            $bytes = self::bytesOf($seq);
            $this->assertSame(KeyStroke::MATCHED, $stroke->match_status($bytes));
            $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(array_merge($bytes, [32])));
            for ($i = 2; $i < count($bytes); $i++) {
                $this->assertSame(KeyStroke::MATCHING, $stroke->match_status(array_slice($bytes, 0, $i)));
            }
        }
    }

    public function testExpand(): void
    {
        $config = new Config();
        $config->add_default_key_binding(self::bytesOf('abc'), self::bytesOf('AB'));
        $config->add_default_key_binding(self::bytesOf('ab'), self::bytesOf("1\x01")); // "1\C-a"
        $stroke = new KeyStroke($config, self::ENCODING);

        $this->assertEquals(
            [[new Key('A', 'ed_insert', false), new Key('B', 'ed_insert', false)], self::bytesOf('de')],
            $stroke->expand(self::bytesOf('abcde')),
        );
        $this->assertEquals(
            [[new Key('1', 'ed_digit', false), new Key("\x01", 'ed_move_to_beg', false)], self::bytesOf('de')],
            $stroke->expand(self::bytesOf('abde')),
        );
        // CSI sequence
        $this->assertEquals([[], self::bytesOf('bc')], $stroke->expand(self::bytesOf("\e[1;2;3;4;5abc")));
        $this->assertEquals([[], self::bytesOf('BC')], $stroke->expand(self::bytesOf("\e\e[ABC")));
        // SS3 sequence
        $this->assertEquals([[], self::bytesOf('QR')], $stroke->expand(self::bytesOf("\eOPQR")));
    }

    public function testOneshotKeyBindings(): void
    {
        $config = new Config();
        $config->add_oneshot_key_binding(self::bytesOf('abc'), self::bytesOf('123'));
        // IRB version <= 1.13.1 wrongly uses Reline::Key. It should be ignored without error.
        $config->add_oneshot_key_binding([new Key(null, 0xE4, true)], self::bytesOf('012'));
        $config->add_oneshot_key_binding(self::bytesOf("\eda"), self::bytesOf('abc')); // Alt+d a
        $config->add_oneshot_key_binding([195, 164], self::bytesOf('def'));
        $stroke = new KeyStroke($config, self::ENCODING);

        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(self::bytesOf('zzz')));
        $this->assertSame(KeyStroke::MATCHED, $stroke->match_status(self::bytesOf('abc')));
        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(self::bytesOf('da')));
        $this->assertSame(KeyStroke::MATCHED, $stroke->match_status(self::bytesOf("\eda")));
        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(self::bytesOf(" \eda")));
        $this->assertSame(KeyStroke::MATCHED, $stroke->match_status([195, 164]));
    }

    public function testMultibyteMatching(): void
    {
        $char = 'あ';
        $config = new Config();
        $stroke = new KeyStroke($config, self::ENCODING);
        $key = new Key($char, 'ed_insert', false);
        $bytes = self::bytesOf($char);

        $this->assertSame(KeyStroke::MATCHED, $stroke->match_status($bytes));
        $this->assertEquals([[$key], []], $stroke->expand($bytes));
        $this->assertSame(KeyStroke::UNMATCHED, $stroke->match_status(array_merge($bytes, $bytes)));
        $this->assertEquals([[$key], $bytes], $stroke->expand(array_merge($bytes, $bytes)));
        for ($i = 1; $i < count($bytes); $i++) {
            $partial = array_slice($bytes, 0, $i);
            $this->assertSame(KeyStroke::MATCHING_MATCHED, $stroke->match_status($partial));
            $this->assertEquals([[], []], $stroke->expand($partial));
        }
    }
}
