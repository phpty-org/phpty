<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\CompletionState;
use PhPty\Reline\IO\Dumb;
use PhPty\Reline\Key;
use PhPty\Reline\KeyStroke;
use PhPty\Reline\LineEditor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The completion cases of test/reline/test_key_actor_emacs.rb, ported in
 * upstream's style. `input_keys` feeds bytes through KeyStroke (so Tab / ^I drives
 * the keymap-bound `complete`), and `input_key_by_symbol` drives the methods the
 * emacs keymap does not bind (menu_complete, menu_complete_backward,
 * completion_journey_up) exactly as upstream's helper does. No terminal is
 * involved — a Dumb gate stands in, and @menu_info / @completion_state are read
 * back through the public accessors added for tier 4.
 */
final class LineEditorCompletionTest extends TestCase
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

    private function inputKeyBySymbol(string $method_symbol, string $char = "\x01"): void
    {
        $this->editor->input_key(new Key($char, $method_symbol, false));
    }

    private function setLineAroundCursor(string $before, string $after): void
    {
        $this->inputKeys("\x01\x0b"); // C-a C-k: clear the line
        $this->inputKeys($after);
        $this->inputKeys("\x01"); // C-a
        $this->inputKeys($before);
    }

    private function assertAroundCursor(string $before, string $after): void
    {
        $line = $this->editor->current_line();
        $bp = $this->editor->byte_pointer();
        $this->assertSame([$before, $after], [\substr($line, 0, $bp), \substr($line, $bp)]);
    }

    /** @return list<string>|null */
    private function menuList(): ?array
    {
        $menu = $this->editor->menu_info();

        return $menu === null ? null : $menu->list();
    }

    private function fooCompletionProc(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => [
            'foo_foo', 'foo_bar', 'foo_baz', 'qux',
        ]);
    }

    public function testCompletion(): void
    {
        $this->fooCompletionProc();
        $this->inputKeys('fo');
        $this->assertAroundCursor('fo', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09"); // C-i / Tab
        $this->assertAroundCursor('foo_', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertSame(['foo_foo', 'foo_bar', 'foo_baz'], $this->menuList());
        $this->inputKeys('a');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_a', '');
        $this->inputKeys("\x08"); // C-h backspace
        $this->inputKeys('b');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_ba', '');
        $this->inputKeys("\x08");
        $this->inputKeyBySymbol('complete');
        $this->assertAroundCursor('foo_ba', '');
        $this->inputKeys("\x08");
        $this->inputKeyBySymbol('menu_complete');
        $this->assertAroundCursor('foo_bar', '');
        $this->inputKeyBySymbol('menu_complete');
        $this->assertAroundCursor('foo_baz', '');
        $this->inputKeys("\x08");
        $this->inputKeyBySymbol('menu_complete_backward');
        $this->assertAroundCursor('foo_baz', '');
        $this->inputKeyBySymbol('menu_complete_backward');
        $this->assertAroundCursor('foo_bar', '');
    }

    public function testCompletionDuplicatedList(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => ['foo_foo', 'foo_foo', 'foo_bar']);
        $this->inputKeys('foo_');
        $this->assertAroundCursor('foo_', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertSame(['foo_foo', 'foo_bar'], $this->menuList());
    }

    public function testCompletionWithPerfectMatch(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => ['foo', 'foo_bar']);
        $matched = null;
        $this->editor->set_dig_perfect_match_proc(static function (string $m) use (&$matched): void {
            $matched = $m;
        });
        $this->inputKeys('fo');
        $this->assertAroundCursor('fo', '');
        $this->assertSame(CompletionState::NORMAL, $this->editor->completion_state());
        $this->assertNull($matched);
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo', '');
        $this->assertSame(CompletionState::MENU_WITH_PERFECT_MATCH, $this->editor->completion_state());
        $this->assertNull($matched);
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo', '');
        $this->assertSame(CompletionState::PERFECT_MATCH, $this->editor->completion_state());
        $this->assertNull($matched);
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo', '');
        $this->assertSame(CompletionState::PERFECT_MATCH, $this->editor->completion_state());
        $this->assertSame('foo', $matched);
        $matched = null;
        $this->inputKeys('_');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_bar', '');
        $this->assertSame(CompletionState::PERFECT_MATCH, $this->editor->completion_state());
        $this->assertNull($matched);
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_bar', '');
        $this->assertSame(CompletionState::PERFECT_MATCH, $this->editor->completion_state());
        $this->assertSame('foo_bar', $matched);
    }

    public function testContinuousCompletionWithPerfectMatch(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => $word === 'f' ? ['foo'] : ['foobar', 'foobaz']);
        $this->inputKeys('f');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('fooba', '');
    }

    public function testContinuousCompletionDisabledWithPerfectMatch(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => $word === 'f' ? ['foo'] : ['foobar', 'foobaz']);
        $this->editor->set_dig_perfect_match_proc(static function (): void {
        });
        $this->inputKeys('f');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo', '');
    }

    public function testCompletionAppendCharacter(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => \array_values(\array_filter(
            ['foo_', 'foo_foo', 'foo_bar'],
            static fn (string $s): bool => \strncmp($s, $word, \strlen($word)) === 0,
        )));
        $this->editor->set_completion_append_character('X');
        $this->inputKeys('f');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->inputKeys('f');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_fooX', '');
        $this->inputKeys(' foo_bar');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_fooX foo_barX', '');
    }

    public function testCompletionWithQuoteAppend(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => \array_values(\array_filter(
            ['foo', 'bar', 'baz'],
            static fn (string $s): bool => \strncmp($s, $word, \strlen($word)) === 0,
        )));
        $this->setLineAroundCursor('x = "b', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('x = "ba', '');
        $this->setLineAroundCursor('x = "f', ' ');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('x = "foo', ' ');
        $this->setLineAroundCursor("x = 'f", '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor("x = 'foo'", '');
        $this->setLineAroundCursor('"a "f', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('"a "foo', '');
        $this->setLineAroundCursor('"a\\" "f', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('"a\\" "foo', '');
        $this->setLineAroundCursor('"a" "f', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('"a" "foo"', '');
    }

    public function testCompletionWithCompletionIgnoreCase(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => [
            'foo_foo', 'foo_bar', 'Foo_baz', 'qux',
        ]);
        $this->inputKeys('fo');
        $this->assertAroundCursor('fo', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertSame(['foo_foo', 'foo_bar'], $this->menuList());
        $this->config->set_completion_ignore_case(true);
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertSame(['foo_foo', 'foo_bar', 'Foo_baz'], $this->menuList());
        $this->inputKeys('a');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_a', '');
        $this->inputKeys("\x08");
        $this->inputKeys('b');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_ba', '');
        $this->inputKeys('Z');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('Foo_baz', '');
    }

    public function testCompletionInMiddleOfLine(): void
    {
        $this->fooCompletionProc();
        $this->inputKeys('abcde fo ABCDE');
        $this->assertAroundCursor('abcde fo ABCDE', '');
        $this->inputKeys(\str_repeat("\x02", 6) . "\x09"); // C-b x6 then Tab
        $this->assertAroundCursor('abcde foo_', ' ABCDE');
        $this->inputKeys(\str_repeat("\x02", 2) . "\x09");
        $this->assertAroundCursor('abcde foo_', 'o_ ABCDE');
    }

    public function testCompletionWithNilValue(): void
    {
        $this->editor->set_completion_proc(static fn (string $word): array => [
            null, 'foo_foo', 'foo_bar', 'Foo_baz', 'qux',
        ]);
        $this->config->set_completion_ignore_case(true);
        $this->inputKeys('fo');
        $this->assertAroundCursor('fo', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_', '');
        $this->assertSame(['foo_foo', 'foo_bar', 'Foo_baz'], $this->menuList());
        $this->inputKeys('a');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_a', '');
        $this->inputKeys("\x08");
        $this->inputKeys('b');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('foo_ba', '');
    }

    public function testCompletionWithIndent(): void
    {
        $this->fooCompletionProc();
        $this->inputKeys('  fo');
        $this->assertAroundCursor('  fo', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('  foo_', '');
        $this->assertNull($this->menuList());
        $this->inputKeys("\x09");
        $this->assertAroundCursor('  foo_', '');
        $this->assertSame(['foo_foo', 'foo_bar', 'foo_baz'], $this->menuList());
    }

    public function testAutocompletion(): void
    {
        $this->config->set_autocompletion(true);
        $this->editor->set_completion_proc(static fn (string $word): array => [
            'Readline', 'Regexp', 'RegexpError',
        ]);
        $this->inputKeys('Re');
        $this->assertAroundCursor('Re', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('Readline', '');
        $this->inputKeys("\x09");
        $this->assertAroundCursor('Regexp', '');
        $this->inputKeyBySymbol('completion_journey_up');
        $this->assertAroundCursor('Readline', '');
        $this->inputKeyBySymbol('complete');
        $this->assertAroundCursor('Regexp', '');
        $this->inputKeyBySymbol('menu_complete_backward');
        $this->assertAroundCursor('Readline', '');
        $this->inputKeyBySymbol('menu_complete');
        $this->assertAroundCursor('Regexp', '');
    }
}
