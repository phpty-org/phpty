<?php

declare(strict_types=1);

namespace PhPty\Reline\Tests;

use PhPty\Reline\Config;
use PhPty\Reline\Config\InvalidInputrc;
use PhPty\Reline\KeyActor\Base;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Ported from test/reline/test_config.rb. Env vars are manipulated with putenv and
 * restored in tearDown; HOME / XDG_CONFIG_HOME / cwd are isolated via a temp dir,
 * mirroring the Ruby suite's Dir.chdir + ENV backup/restore.
 *
 * Private state is reached the way upstream reaches it (`instance_variable_get`):
 * through the public accessors where they exist, and via reflection for the
 * additional-keymap layer. The two non-UTF-8 cases (test_inputrc_with_eucjp) are
 * out of scope for this UTF-8-first port and are omitted; test_inputrc_with_utf8
 * (which upstream also guards with a rescue) is kept.
 */
final class ConfigTest extends TestCase
{
    private Config $config;

    private string $pwd;

    private string $tmpdir;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function set_up(): void
    {
        foreach (['INPUTRC', 'HOME', 'XDG_CONFIG_HOME'] as $name) {
            $this->envBackup[$name] = \getenv($name);
        }
        $this->pwd = \getcwd();
        $this->tmpdir = \sys_get_temp_dir() . '/test_reline_config_' . \getmypid() . '_' . \uniqid();
        \mkdir($this->tmpdir, 0o777, true);
        \chdir($this->tmpdir);
        $this->config = new Config();
    }

    protected function tear_down(): void
    {
        \chdir($this->pwd);
        self::rrmdir($this->tmpdir);
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                \putenv($name);
            } else {
                \putenv("{$name}={$value}");
            }
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) ? self::rrmdir($path) : \unlink($path);
        }
        \rmdir($dir);
    }

    /**
     * @return list<int>
     */
    private static function bytesOf(string $s): array
    {
        return $s === '' ? [] : \array_values(\unpack('C*', $s));
    }

    /**
     * @param list<string> $lines
     */
    private function readLines(string $text): void
    {
        // Split into lines keeping the trailing newline, as File.readlines does.
        $lines = \preg_split('/(?<=\n)/', $text, -1, \PREG_SPLIT_NO_EMPTY);
        $this->config->read_lines($lines);
    }

    /**
     * The additional (inputrc) bindings for a keymap, keyed by comma-joined bytes.
     *
     * @return array<string, string|list<int>>
     */
    private function additionalBindings(string $label): array
    {
        $rp = new \ReflectionProperty(Config::class, 'additionalKeyBindings');
        $rp->setAccessible(true);
        $base = $rp->getValue($this->config)[$label];
        $rb = new \ReflectionProperty(Base::class, 'keyBindings');
        $rb->setAccessible(true);

        return $rb->getValue($base);
    }

    /**
     * @param list<list<int>> $keys
     * @return array<string, string|list<int>|null>
     */
    private function registeredKeyBindings(array $keys): array
    {
        $bindings = $this->config->key_bindings();
        $out = [];
        foreach ($keys as $key) {
            $out[\implode(',', $key)] = $bindings->get($key);
        }

        return $out;
    }

    // --- set variable ------------------------------------------------------

    public function testReadLines(): void
    {
        $this->readLines("set show-mode-in-prompt on\n");
        self::assertTrue($this->config->show_mode_in_prompt());
    }

    public function testReadLinesWithVariable(): void
    {
        $this->readLines("set disable-completion on\n");
        self::assertTrue($this->config->disable_completion());
    }

    public function testStringValue(): void
    {
        $this->readLines("set show-mode-in-prompt on\nset emacs-mode-string Emacs\n");
        self::assertSame('Emacs', $this->config->emacs_mode_string());
    }

    public function testStringValueWithBrackets(): void
    {
        $this->readLines("set show-mode-in-prompt on\nset emacs-mode-string [Emacs]\n");
        self::assertSame('[Emacs]', $this->config->emacs_mode_string());
    }

    public function testStringValueWithBracketsAndQuotes(): void
    {
        $this->readLines("set show-mode-in-prompt on\nset emacs-mode-string \"[Emacs]\"\n");
        self::assertSame('[Emacs]', $this->config->emacs_mode_string());
    }

    public function testStringValueWithParens(): void
    {
        $this->readLines("set show-mode-in-prompt on\nset emacs-mode-string (Emacs)\n");
        self::assertSame('(Emacs)', $this->config->emacs_mode_string());
    }

    public function testStringValueWithParensAndQuotes(): void
    {
        $this->readLines("set show-mode-in-prompt on\nset emacs-mode-string \"(Emacs)\"\n");
        self::assertSame('(Emacs)', $this->config->emacs_mode_string());
    }

    // --- convert-meta / encoding -------------------------------------------

    public function testEncodingIsAscii(): void
    {
        // Upstream mutates the gate encoding then rebuilds Config; the port seeds
        // convert-meta from the encoding passed to the constructor.
        $config = new Config('US-ASCII');
        self::assertTrue($config->convert_meta());
    }

    public function testEncodingIsNotAscii(): void
    {
        $config = new Config('UTF-8');
        self::assertFalse($config->convert_meta());
    }

    // --- key bindings ------------------------------------------------------

    public function testInvalidKeystroke(): void
    {
        $this->readLines("#\"a\": comment\na: error\n\"b\": no-error\n");
        $bindings = $this->additionalBindings('emacs');
        self::assertArrayNotHasKey('97', $bindings); // 'a'
        self::assertArrayNotHasKey('', $bindings);
        self::assertArrayHasKey('98', $bindings); // 'b'
    }

    public function testBindKey(): void
    {
        self::assertSame(
            [self::bytesOf('input'), self::bytesOf('abcde')],
            $this->config->parse_key_binding('"input"', '"abcde"'),
        );
    }

    public function testBindKeyWithMacro(): void
    {
        self::assertSame(
            [self::bytesOf('input'), 'abcde'],
            $this->config->parse_key_binding('"input"', 'abcde'),
        );
    }

    public function testBindKeyWithEscapedChars(): void
    {
        self::assertSame(
            [self::bytesOf('input'), self::bytesOf("\e \\ \" ' \x07 \x08 d \x0c \n \r \t \x0b")],
            $this->config->parse_key_binding('"input"', '"\\e \\\\ \\" \\\' \\a \\b \\d \\f \\n \\r \\t \\v"'),
        );
    }

    public function testBindKeyWithCtrlChars(): void
    {
        $expected = [self::bytesOf('input'), [0x08, 0x08, 0x1f]]; // \C-h \C-H \C-_
        self::assertSame($expected, $this->config->parse_key_binding('"input"', '"\C-h\C-H\C-_"'));
        self::assertSame($expected, $this->config->parse_key_binding('"input"', '"\Control-h\Control-H\Control-_"'));
    }

    public function testBindKeyWithMetaChars(): void
    {
        $expected = [self::bytesOf('input'), [0x1b, 0x68, 0x1b, 0x48, 0x1b, 0x5f]]; // \eh \eH \e_
        self::assertSame($expected, $this->config->parse_key_binding('"input"', '"\M-h\M-H\M-_"'));
        self::assertSame($expected, $this->config->parse_key_binding('"input"', '"\Meta-h\Meta-H\M-_"'));
    }

    public function testBindKeyWithCtrlMetaChars(): void
    {
        self::assertSame(
            [self::bytesOf('input'), [0x1b, 0x08, 0x1b, 0x08, 0x1b, 0x1f]],
            $this->config->parse_key_binding('"input"', '"\M-\C-h\C-\M-H\M-\C-_"'),
        );
        self::assertSame(
            [self::bytesOf('input'), [0x1b, 0x08, 0x1b, 0x1f]],
            $this->config->parse_key_binding('"input"', '"\Meta-\Control-h\Control-\Meta-_"'),
        );
    }

    public function testBindKeyWithOctalNumber(): void
    {
        $input = self::bytesOf('input');
        self::assertSame([$input, [1]], $this->config->parse_key_binding('"input"', '"\1"'));
        self::assertSame([$input, [0o12]], $this->config->parse_key_binding('"input"', '"\12"'));
        self::assertSame([$input, [0o123]], $this->config->parse_key_binding('"input"', '"\123"'));
        self::assertSame([$input, [0o123, 0x34]], $this->config->parse_key_binding('"input"', '"\1234"'));
    }

    public function testBindKeyWithHexadecimalNumber(): void
    {
        $input = self::bytesOf('input');
        self::assertSame([$input, [0x4]], $this->config->parse_key_binding('"input"', '"\x4"'));
        self::assertSame([$input, [0x45]], $this->config->parse_key_binding('"input"', '"\x45"'));
        self::assertSame([$input, [0x45, 0x36]], $this->config->parse_key_binding('"input"', '"\x456"'));
    }

    // --- $include ----------------------------------------------------------

    public function testInclude(): void
    {
        \file_put_contents($this->tmpdir . '/included_partial', "set show-mode-in-prompt on\n");
        $this->readLines("\$include included_partial\n");
        self::assertTrue($this->config->show_mode_in_prompt());
    }

    public function testIncludeExpandPath(): void
    {
        \file_put_contents($this->tmpdir . '/included_partial', "set show-mode-in-prompt on\n");
        \putenv('HOME=' . $this->tmpdir);
        $this->readLines("\$include ~/included_partial\n");
        self::assertTrue($this->config->show_mode_in_prompt());
    }

    // --- $if / $else / $endif ----------------------------------------------

    public function testIf(): void
    {
        $this->readLines("\$if Ruby\nset vi-cmd-mode-string (cmd)\n\$else\nset vi-cmd-mode-string [cmd]\n\$endif\n");
        self::assertSame('(cmd)', $this->config->vi_cmd_mode_string());
    }

    public function testIfWithFalse(): void
    {
        $this->readLines("\$if Python\nset vi-cmd-mode-string (cmd)\n\$else\nset vi-cmd-mode-string [cmd]\n\$endif\n");
        self::assertSame('[cmd]', $this->config->vi_cmd_mode_string());
    }

    public function testIfWithIndent(): void
    {
        foreach (['Ruby', 'Reline'] as $cond) {
            $this->config = new Config();
            $this->readLines(
                "set vi-cmd-mode-string {cmd}\n  \$if {$cond}\n    set vi-cmd-mode-string (cmd)\n  \$else\n    set vi-cmd-mode-string [cmd]\n  \$endif\n",
            );
            self::assertSame('(cmd)', $this->config->vi_cmd_mode_string());
        }
    }

    public function testNestedIfElse(): void
    {
        $this->readLines(
            "\$if Ruby\n" .
            "  \"\x1\": \"O\"\n" .
            "  \$if NotRuby\n" .
            "    \"\x2\": \"X\"\n" .
            "  \$else\n" .
            "    \"\x3\": \"O\"\n" .
            "    \$if Ruby\n" .
            "      \"\x4\": \"O\"\n" .
            "    \$else\n" .
            "      \"\x5\": \"X\"\n" .
            "    \$endif\n" .
            "    \"\x6\": \"O\"\n" .
            "  \$endif\n" .
            "  \"\x7\": \"O\"\n" .
            "\$else\n" .
            "  \"\x8\": \"X\"\n" .
            "  \$if NotRuby\n" .
            "    \"\x9\": \"X\"\n" .
            "  \$else\n" .
            "    \"\xA\": \"X\"\n" .
            "  \$endif\n" .
            "  \"\xB\": \"X\"\n" .
            "\$endif\n" .
            "\"\xC\": \"O\"\n",
        );
        $expected = [];
        foreach ([0x1, 0x3, 0x4, 0x6, 0x7, 0xC] as $k) {
            $expected[(string) $k] = [\ord('O')];
        }
        self::assertEquals($expected, $this->additionalBindings('emacs'));
    }

    public function testUnclosedIf(): void
    {
        try {
            $this->config->read_lines(["\$if Ruby\n"], 'INPUTRC');
            self::fail('expected InvalidInputrc');
        } catch (InvalidInputrc $e) {
            self::assertSame('INPUTRC:1: unclosed if', $e->getMessage());
        }
    }

    public function testUnmatchedElse(): void
    {
        try {
            $this->config->read_lines(["\$else\n"], 'INPUTRC');
            self::fail('expected InvalidInputrc');
        } catch (InvalidInputrc $e) {
            self::assertSame('INPUTRC:1: unmatched else', $e->getMessage());
        }
    }

    public function testUnmatchedEndif(): void
    {
        try {
            $this->config->read_lines(["\$endif\n"], 'INPUTRC');
            self::fail('expected InvalidInputrc');
        } catch (InvalidInputrc $e) {
            self::assertSame('INPUTRC:1: unmatched endif', $e->getMessage());
        }
    }

    public function testIfWithMode(): void
    {
        $this->readLines("\$if mode=emacs\n  \"\C-e\": history-search-backward # comment\n\$else\n  \"\C-f\": history-search-forward\n\$endif\n");
        self::assertEquals(['5' => 'history_search_backward'], $this->additionalBindings('emacs'));
        self::assertSame([], $this->additionalBindings('vi_insert'));
        self::assertSame([], $this->additionalBindings('vi_command'));
    }

    public function testElse(): void
    {
        $this->readLines("\$if mode=vi\n  \"\C-e\": history-search-backward # comment\n\$else\n  \"\C-f\": history-search-forward\n\$endif\n");
        self::assertEquals(['6' => 'history_search_forward'], $this->additionalBindings('emacs'));
        self::assertSame([], $this->additionalBindings('vi_insert'));
    }

    public function testIfWithInvalidMode(): void
    {
        $this->readLines("\$if mode=vim\n  \"\C-e\": history-search-backward\n\$else\n  \"\C-f\": history-search-forward # comment\n\$endif\n");
        self::assertEquals(['6' => 'history_search_forward'], $this->additionalBindings('emacs'));
    }

    public function testModeLabelDiffersFromKeymapLabel(): void
    {
        $this->readLines(
            "set editing-mode vi\nset keymap emacs\n\$if mode=vi\n  \"\C-e\": history-search-backward\n\$endif\n",
        );
        self::assertEquals(['5' => 'history_search_backward'], $this->additionalBindings('emacs'));
        self::assertSame([], $this->additionalBindings('vi_insert'));
        self::assertSame([], $this->additionalBindings('vi_command'));
    }

    public function testIfWithoutElseCondition(): void
    {
        $this->readLines("set editing-mode vi\n\$if mode=vi\n  \"\C-e\": history-search-backward\n\$endif\n");
        self::assertSame([], $this->additionalBindings('emacs'));
        self::assertEquals(['5' => 'history_search_backward'], $this->additionalBindings('vi_insert'));
    }

    // --- registered bindings (composite) -----------------------------------

    public function testDefaultKeyBindings(): void
    {
        $this->config->add_default_key_binding(self::bytesOf('abcd'), self::bytesOf('EFGH'));
        $this->readLines("\"abcd\": \"ABCD\"\n\"ijkl\": \"IJKL\"\n");
        $expected = [
            \implode(',', self::bytesOf('abcd')) => self::bytesOf('ABCD'),
            \implode(',', self::bytesOf('ijkl')) => self::bytesOf('IJKL'),
        ];
        self::assertEquals($expected, $this->registeredKeyBindings([self::bytesOf('abcd'), self::bytesOf('ijkl')]));
    }

    public function testAdditionalKeyBindings(): void
    {
        $this->readLines("\"ef\": \"EF\"\n\"gh\": \"GH\"\n");
        $expected = [
            \implode(',', self::bytesOf('ef')) => self::bytesOf('EF'),
            \implode(',', self::bytesOf('gh')) => self::bytesOf('GH'),
        ];
        self::assertEquals($expected, $this->registeredKeyBindings([self::bytesOf('ef'), self::bytesOf('gh')]));
    }

    public function testUnquotedAdditionalKeyBindings(): void
    {
        $this->readLines(
            "Meta-a: \"Ma\"\nControl-b: \"Cb\"\nMeta-Control-c: \"MCc\"\nControl-Meta-d: \"CMd\"\nM-C-e: \"MCe\"\nC-M-f: \"CMf\"\n",
        );
        $expected = [
            \implode(',', [0x1b, 0x61]) => self::bytesOf('Ma'),      // \ea
            \implode(',', [0x02]) => self::bytesOf('Cb'),            // \C-b
            \implode(',', [0x1b, 0x03]) => self::bytesOf('MCc'),     // \e\C-c
            \implode(',', [0x1b, 0x04]) => self::bytesOf('CMd'),     // \e\C-d
            \implode(',', [0x1b, 0x05]) => self::bytesOf('MCe'),     // \e\C-e
            \implode(',', [0x1b, 0x06]) => self::bytesOf('CMf'),     // \e\C-f
        ];
        $keys = [[0x1b, 0x61], [0x02], [0x1b, 0x03], [0x1b, 0x04], [0x1b, 0x05], [0x1b, 0x06]];
        self::assertEquals($expected, $this->registeredKeyBindings($keys));
    }

    public function testAdditionalKeyBindingsWithNestingAndCommentOut(): void
    {
        $this->readLines("#\"ab\": \"AB\"\n  #\"cd\": \"cd\"\n\"ef\": \"EF\"\n  \"gh\": \"GH\"\n");
        $expected = [
            \implode(',', self::bytesOf('ef')) => self::bytesOf('EF'),
            \implode(',', self::bytesOf('gh')) => self::bytesOf('GH'),
        ];
        self::assertEquals($expected, $this->registeredKeyBindings([self::bytesOf('ef'), self::bytesOf('gh')]));
    }

    public function testAdditionalKeyBindingsForOtherKeymap(): void
    {
        $this->readLines(
            "set keymap vi-command\n\"ab\": \"AB\"\nset keymap vi-insert\n\"cd\": \"CD\"\nset keymap emacs\n\"ef\": \"EF\"\nset editing-mode vi # keymap changes to be vi-insert\n",
        );
        $expected = [\implode(',', self::bytesOf('cd')) => self::bytesOf('CD')];
        self::assertEquals($expected, $this->registeredKeyBindings([self::bytesOf('cd')]));
    }

    public function testAdditionalKeyBindingsForAuxiliaryEmacsKeymaps(): void
    {
        $this->readLines(
            "set keymap emacs\n\"ab\": \"AB\"\nset keymap emacs-standard\n\"cd\": \"CD\"\nset keymap emacs-ctlx\n\"ef\": \"EF\"\nset keymap emacs-meta\n\"gh\": \"GH\"\nset editing-mode emacs # keymap changes to be emacs\n",
        );
        $expected = [
            \implode(',', self::bytesOf('ab')) => self::bytesOf('AB'),
            \implode(',', self::bytesOf('cd')) => self::bytesOf('CD'),
            \implode(',', \array_merge([0x18], self::bytesOf('ef'))) => self::bytesOf('EF'), // \C-x ef
            \implode(',', \array_merge([0x1b], self::bytesOf('gh'))) => self::bytesOf('GH'), // \e gh
        ];
        $keys = [
            self::bytesOf('ab'),
            self::bytesOf('cd'),
            \array_merge([0x18], self::bytesOf('ef')),
            \array_merge([0x1b], self::bytesOf('gh')),
        ];
        self::assertEquals($expected, $this->registeredKeyBindings($keys));
    }

    public function testKeyBindingsWithReset(): void
    {
        $this->config->add_default_key_binding(self::bytesOf('default'), self::bytesOf('DEFAULT'));
        $this->readLines("\"additional\": \"ADDITIONAL\"\n");
        $this->config->reset();
        $expected = [
            \implode(',', self::bytesOf('default')) => self::bytesOf('DEFAULT'),
            \implode(',', self::bytesOf('additional')) => self::bytesOf('ADDITIONAL'),
        ];
        self::assertEquals($expected, $this->registeredKeyBindings([self::bytesOf('default'), self::bytesOf('additional')]));
    }

    public function testHistorySize(): void
    {
        $this->readLines("set history-size 5000\n");
        self::assertSame(5000, $this->config->history_size());
    }

    // --- file resolution ---------------------------------------------------

    public function testEmptyInputrcEnv(): void
    {
        \putenv('INPUTRC=');
        $this->config->read(); // must not raise
        self::assertTrue($this->config->loaded());
    }

    public function testInputrc(): void
    {
        $expected = $this->tmpdir . '/abcde';
        \putenv('INPUTRC=' . $expected);
        self::assertSame($expected, $this->config->inputrc_path());
    }

    public function testInputrcRawValue(): void
    {
        $this->readLines("set editing-mode vi ignored-string\nset vi-ins-mode-string aaa aaa\nset vi-cmd-mode-string bbb ccc # comment\n");
        self::assertSame('vi_insert', $this->config->editing_mode_label());
        self::assertSame('aaa aaa', $this->config->vi_ins_mode_string());
        self::assertSame('bbb ccc # comment', $this->config->vi_cmd_mode_string());
    }

    public function testInputrcWithUtf8(): void
    {
        $this->readLines("set editing-mode vi\nset vi-cmd-mode-string 🍸\nset vi-ins-mode-string 🍶\n");
        self::assertSame('🍸', $this->config->vi_cmd_mode_string());
        self::assertSame('🍶', $this->config->vi_ins_mode_string());
    }

    public function testEmptyInputrc(): void
    {
        $this->config->read_lines([]); // must not raise
        self::assertTrue(true);
    }

    public function testXdgConfigHome(): void
    {
        $xdgConfigHome = $this->tmpdir . '/.config/example_dir';
        $expected = $xdgConfigHome . '/readline/inputrc';
        \mkdir(\dirname($expected), 0o777, true);
        \touch($expected);
        \putenv('HOME=' . $this->tmpdir);
        \putenv('XDG_CONFIG_HOME=' . $xdgConfigHome);
        \putenv('INPUTRC=');
        self::assertSame($expected, $this->config->inputrc_path());
    }

    public function testEmptyXdgConfigHome(): void
    {
        \putenv('HOME=' . $this->tmpdir);
        \putenv('XDG_CONFIG_HOME=');
        \putenv('INPUTRC=');
        $expected = $this->tmpdir . '/.config/readline/inputrc';
        \mkdir(\dirname($expected), 0o777, true);
        \touch($expected);
        self::assertSame($expected, $this->config->inputrc_path());
    }

    public function testRelativeXdgConfigHome(): void
    {
        \putenv('HOME=' . $this->tmpdir);
        \putenv('INPUTRC=');
        $expected = $this->tmpdir . '/.config/readline/inputrc';
        \mkdir(\dirname($expected), 0o777, true);
        \touch($expected);
        // A relative XDG_CONFIG_HOME is not absolute, so it is skipped and the
        // ~/.config fallback wins (config.rb:105-115).
        $xdgConfigHome = '.config/example_dir';
        \putenv('XDG_CONFIG_HOME=' . $xdgConfigHome);
        $inputrc = $xdgConfigHome . '/readline/inputrc';
        \mkdir(\dirname($inputrc), 0o777, true);
        \touch($inputrc);
        self::assertSame($expected, $this->config->inputrc_path());
    }

    public function testReload(): void
    {
        $inputrc = $this->tmpdir . '/inputrc';
        \putenv('INPUTRC=' . $inputrc);

        \file_put_contents($inputrc, 'set emacs-mode-string !');
        $this->config->read();
        self::assertSame('!', $this->config->emacs_mode_string());

        \file_put_contents($inputrc, 'set emacs-mode-string ?');
        $this->config->reload();
        self::assertSame('?', $this->config->emacs_mode_string());

        \file_put_contents($inputrc, '');
        $this->config->reload();
        self::assertSame('@', $this->config->emacs_mode_string());
    }

    public function testInvalidByteSequenceInputrc(): void
    {
        $lines = [
            "set vi-cmd-mode-string\n",
            "\$if Ruby\n",
            "  \"\x01\": \"Ruby\"\n",
            "\$else \"\xFF\"\n", // invalid byte sequence
            "  \"\x02\": \"NotRuby\"\n",
            "\$endif\n",
        ];
        try {
            $this->config->read_lines($lines, 'INPUTRC');
            self::fail('expected InvalidInputrc');
        } catch (InvalidInputrc $e) {
            self::assertSame("INPUTRC:4: can't be converted to the locale UTF-8", $e->getMessage());
        }
    }
}
